<?php
declare(strict_types=1);

/**
 * Cassette toggle — start/stop helper for the cassette PHP recording extension.
 *
 * Public entry points:
 *   CassetteToggle::up($phpVersion, $projectRoot)
 *   CassetteToggle::down($phpVersion, $projectRoot)
 *
 * On `up`:
 *   1. Writes /etc/php/<ver>/fpm/conf.d/zzz-cassette.ini (uopz, opcache, xdebug, blackfire)
 *   2. Enables the uopz module (phpenmod -v <ver> uopz)
 *   3. For PHP 8.5: builds & installs an LD_PRELOAD shim that provides the
 *      `zend_exception_get_default` symbol removed in PHP 8.5 (uopz still references it)
 *      and registers a systemd drop-in for php<ver>-fpm so the shim is loaded.
 *   4. Restarts php<ver>-fpm
 *   5. With $projectRoot: detects WordPress (wp-config.php) or Laravel (public/index.php)
 *      and injects the cassette bootstrap line — wrapped in markers so the matching
 *      `down` invocation can remove it cleanly.
 *   6. With $projectRoot: ensures `/.cassette/` is in the project's .gitignore.
 *
 * `down` reverses every step except the .gitignore entry, which stays so the
 * directory remains ignored across toggle cycles.
 */
final class CassetteToggle
{
    private const INI_BASENAME = 'zzz-cassette.ini';
    private const SHIM_SO      = '/usr/local/lib/uopz-php85-shim.so';
    private const MARKER_BEGIN = '// >>> cassette toggle (auto-managed) — do not edit';
    private const MARKER_END   = '// <<< cassette toggle';
    private const GITIGNORE_ENTRY = '/.cassette/';

    public static function up(string $phpVersion, ?string $projectRoot): void
    {
        self::info("PHP version: $phpVersion");
        if ($projectRoot !== null) {
            self::info("Project root: $projectRoot");
        }

        self::requirePhpVersionInstalled($phpVersion);
        self::writeIni($phpVersion);
        self::enableUopzModule($phpVersion);

        if (self::needsShim($phpVersion)) {
            self::buildShimIfMissing();
            self::installShimDropin($phpVersion);
        }

        self::restartFpm($phpVersion);

        if ($projectRoot !== null) {
            self::injectBootstrapLine($projectRoot);
            self::ensureGitignoreEntry($projectRoot);
        }

        self::ok("cassette enabled for PHP $phpVersion.");
    }

    public static function down(string $phpVersion, ?string $projectRoot): void
    {
        self::info("PHP version: $phpVersion");
        if ($projectRoot !== null) {
            self::info("Project root: $projectRoot");
        }

        self::requirePhpVersionInstalled($phpVersion);

        // Remove the project-level injection first so a half-stopped state
        // never leaves the bootstrap line referencing a disabled extension.
        if ($projectRoot !== null) {
            self::removeBootstrapLine($projectRoot);
        }

        if (self::needsShim($phpVersion)) {
            self::removeShimDropin($phpVersion);
        }

        self::removeIni($phpVersion);
        self::disableUopzModule($phpVersion);
        self::restartFpm($phpVersion);

        self::ok("cassette disabled for PHP $phpVersion.");
    }

    /**
     * Resolve the PHP version: explicit arg overrides .phprc, which overrides 8.5.
     */
    public static function resolvePhpVersion(?string $fromArg, string $scriptDir): string
    {
        if ($fromArg !== null && $fromArg !== '') {
            return $fromArg;
        }
        $phprc = $scriptDir . '/.phprc';
        if (is_file($phprc)) {
            $version = trim((string) file_get_contents($phprc));
            if ($version !== '') {
                return $version;
            }
        }
        return '8.5';
    }

    // -----------------------------------------------------------------------
    // PHP version handling
    // -----------------------------------------------------------------------

    private static function requirePhpVersionInstalled(string $version): void
    {
        if (!is_dir("/etc/php/$version/fpm/conf.d")) {
            self::die("PHP $version is not installed (no /etc/php/$version/fpm/conf.d directory).");
        }
    }

    // -----------------------------------------------------------------------
    // ini file
    // -----------------------------------------------------------------------

    private static function writeIni(string $version): void
    {
        $target = "/etc/php/$version/fpm/conf.d/" . self::INI_BASENAME;
        self::info("Writing $target");

        $contents = <<<INI
            [uopz]
            uopz.exit = 1
            [opcache]
            opcache.enable = 0
            [xdebug]
            xdebug.mode = off
            [blackfire]
            blackfire.apm_enabled = 0
            INI;

        self::writeRoot($target, $contents);
    }

    private static function removeIni(string $version): void
    {
        $target = "/etc/php/$version/fpm/conf.d/" . self::INI_BASENAME;
        if (is_file($target)) {
            self::info("Removing $target");
            self::sudo('rm -f ' . escapeshellarg($target));
        }
    }

    // -----------------------------------------------------------------------
    // uopz module + php-fpm
    // -----------------------------------------------------------------------

    private static function enableUopzModule(string $version): void
    {
        self::info("phpenmod -v $version uopz");
        self::sudo('phpenmod -v ' . escapeshellarg($version) . ' uopz');
    }

    private static function disableUopzModule(string $version): void
    {
        self::info("phpdismod -v $version uopz");
        // Tolerate missing module so down is idempotent.
        self::sudo('phpdismod -v ' . escapeshellarg($version) . ' uopz', allowFail: true);
    }

    private static function restartFpm(string $version): void
    {
        $unit = "php$version-fpm.service";
        // `systemctl cat` exits non-zero when the unit is missing — silent check.
        $exit = 0;
        $out  = [];
        exec('systemctl cat ' . escapeshellarg($unit) . ' >/dev/null 2>&1', $out, $exit);
        if ($exit !== 0) {
            self::warn("Skipping restart: systemd unit $unit not found.");
            return;
        }
        self::info("systemctl restart $unit");
        self::sudo('systemctl restart ' . escapeshellarg($unit));
    }

    // -----------------------------------------------------------------------
    // PHP 8.5 LD_PRELOAD shim
    // -----------------------------------------------------------------------
    //
    // uopz still references `zend_exception_get_default()`, removed from PHP
    // core in 8.5. dlopen fails with: undefined symbol: zend_exception_get_default.
    // The shim returns the still-existing zend_ce_exception global so uopz behaves
    // identically to PHP 8.4. Harmless on other PHP versions — nothing references
    // the symbol there, the shim just sits in memory unused.

    private static function needsShim(string $version): bool
    {
        return str_starts_with($version, '8.5');
    }

    private static function buildShimIfMissing(): void
    {
        if (is_file(self::SHIM_SO)) {
            return;
        }
        self::info('Building uopz PHP 8.5 shim → ' . self::SHIM_SO);

        $gccCheck = 0;
        exec('command -v gcc >/dev/null 2>&1', $_, $gccCheck);
        if ($gccCheck !== 0) {
            self::die('gcc is required to build the PHP 8.5 uopz shim. apt install build-essential');
        }

        $src = (string) tempnam(sys_get_temp_dir(), 'uopzshim_');
        $srcC = $src . '.c';
        rename($src, $srcC);
        file_put_contents($srcC, <<<'C'
            /*
             * Shim providing zend_exception_get_default() — removed from PHP 8.5 core
             * but still referenced by uopz 7.1.x. Returns the unchanged zend_ce_exception
             * global so uopz behaves identically to PHP 8.4.
             */
            extern void *zend_ce_exception;
            void *zend_exception_get_default(void) { return zend_ce_exception; }
            C);

        self::sudo('gcc -shared -fPIC -O2 -o ' . escapeshellarg(self::SHIM_SO) . ' ' . escapeshellarg($srcC));
        @unlink($srcC);
        self::ok('Built shim (' . filesize(self::SHIM_SO) . ' bytes)');
    }

    private static function installShimDropin(string $version): void
    {
        $unit = "php$version-fpm";
        $dir  = "/etc/systemd/system/$unit.service.d";
        $file = "$dir/uopz-shim.conf";
        self::info("Installing systemd drop-in $file");
        self::sudo('mkdir -p ' . escapeshellarg($dir));

        $contents = "# Auto-managed by cassette toggle. Loads the LD_PRELOAD shim that supplies\n"
            . "# the zend_exception_get_default symbol uopz still expects on PHP 8.5.\n"
            . "[Service]\n"
            . "Environment=LD_PRELOAD=" . self::SHIM_SO . "\n";

        self::writeRoot($file, $contents);
        self::sudo('systemctl daemon-reload');
    }

    private static function removeShimDropin(string $version): void
    {
        $file = "/etc/systemd/system/php$version-fpm.service.d/uopz-shim.conf";
        if (is_file($file)) {
            self::info("Removing systemd drop-in $file");
            self::sudo('rm -f ' . escapeshellarg($file));
            self::sudo('rmdir --ignore-fail-on-non-empty ' . escapeshellarg(dirname($file)) . ' 2>/dev/null', allowFail: true);
            self::sudo('systemctl daemon-reload');
        }
    }

    // -----------------------------------------------------------------------
    // Bootstrap line injection
    // -----------------------------------------------------------------------

    /**
     * Detect framework. Returns ['kind' => 'wordpress'|'laravel', 'file' => string].
     */
    private static function detectFramework(string $root): array
    {
        if (is_file("$root/wp-config.php")) {
            return ['kind' => 'wordpress', 'file' => "$root/wp-config.php"];
        }
        if (is_file("$root/public/index.php")) {
            return ['kind' => 'laravel', 'file' => "$root/public/index.php"];
        }
        self::die("Could not detect framework in $root (no wp-config.php and no public/index.php).");
    }

    /**
     * Build the bootstrap snippet. WordPress uses __DIR__/vendor (root file),
     * Laravel uses __DIR__/../vendor (public/index.php sits one level below root).
     */
    private static function buildSnippet(string $kind): string
    {
        $relativeVendor = $kind === 'wordpress'
            ? "__DIR__.'/vendor/vielhuber/cassette/src/bootstrap.php'"
            : "__DIR__.'/../vendor/vielhuber/cassette/src/bootstrap.php'";

        return self::MARKER_BEGIN . "\n"
            . "require_once file_exists('/var/www/cassette/src/bootstrap.php')\n"
            . "    ? '/var/www/cassette/src/bootstrap.php'\n"
            . "    : $relativeVendor;\n"
            . self::MARKER_END;
    }

    private static function snippetAlreadyPresent(string $file): bool
    {
        return str_contains((string) file_get_contents($file), self::MARKER_BEGIN);
    }

    private static function injectBootstrapLine(string $root): void
    {
        ['kind' => $kind, 'file' => $file] = self::detectFramework($root);
        self::info("Detected $kind project, target file: $file");

        if (self::snippetAlreadyPresent($file)) {
            self::ok("Bootstrap snippet already present in $file — skipping.");
            return;
        }

        $snippet  = self::buildSnippet($kind);
        $original = (string) file_get_contents($file);

        if ($kind === 'wordpress') {
            // Insert directly before `require_once ABSPATH . 'wp-settings.php';`.
            if (!preg_match('/ABSPATH \. .wp-settings\.php./', $original)) {
                self::die("Could not find 'wp-settings.php' require in $file.");
            }
            $patched = preg_replace(
                '/(.*ABSPATH \. .wp-settings\.php.*$)/m',
                $snippet . "\n\n$1",
                $original,
                1
            );
        } else {
            // Laravel: insert right after the opening <?php tag.
            $patched = preg_replace(
                '/^(<\?php\s*\n)/',
                "$1\n" . $snippet . "\n",
                $original,
                1
            );
        }

        self::writeRoot($file, (string) $patched);
        self::ok("Injected bootstrap snippet into $file");
    }

    private static function removeBootstrapLine(string $root): void
    {
        ['file' => $file] = self::detectFramework($root);

        if (!is_file($file)) {
            self::warn("Target file $file not found — nothing to remove.");
            return;
        }

        if (!self::snippetAlreadyPresent($file)) {
            self::ok("No cassette toggle snippet in $file — nothing to remove.");
            return;
        }

        self::info("Removing snippet from $file");
        $contents = (string) file_get_contents($file);

        $beginQ = preg_quote(self::MARKER_BEGIN, '/');
        $endQ   = preg_quote(self::MARKER_END, '/');
        $contents = preg_replace('/' . $beginQ . '.*?' . $endQ . '\n?/s', '', $contents) ?? $contents;

        // Cap any blank-line cluster the snippet left behind at a single blank line.
        $contents = preg_replace("/\n{3,}/", "\n\n", $contents) ?? $contents;

        self::writeRoot($file, $contents);
        self::ok("Removed cassette toggle snippet from $file");
    }

    // -----------------------------------------------------------------------
    // .gitignore
    // -----------------------------------------------------------------------

    private static function ensureGitignoreEntry(string $root): void
    {
        if (!is_dir("$root/.git")) {
            // Not a git repo — silently skip.
            return;
        }

        $gitignore = "$root/.gitignore";
        $entry     = self::GITIGNORE_ENTRY;

        $existing = is_file($gitignore) ? (string) file_get_contents($gitignore) : '';
        $lines    = $existing === '' ? [] : (preg_split('/\r?\n/', $existing) ?: []);

        // Treat several equivalent spellings as already-present.
        $equivalents = ['/.cassette/', '/.cassette', '.cassette/', '.cassette'];
        foreach ($lines as $line) {
            if (in_array(trim($line), $equivalents, true)) {
                self::ok("$entry already present in .gitignore — skipping.");
                return;
            }
        }

        $append = ($existing !== '' && !str_ends_with($existing, "\n")) ? "\n" : '';
        $append .= $entry . "\n";

        file_put_contents($gitignore, $existing . $append);
        self::ok("Added $entry to .gitignore");
    }

    // -----------------------------------------------------------------------
    // Shell helpers
    // -----------------------------------------------------------------------

    /**
     * Write a file as root via `sudo tee`. Used for any path under /etc.
     */
    private static function writeRoot(string $path, string $contents): void
    {
        // Project-owned files (under the project root) don't need sudo. Detect
        // by trying to open the parent directory for writing.
        $parent = dirname($path);
        if (is_writable($parent) || (is_file($path) && is_writable($path))) {
            file_put_contents($path, $contents);
            return;
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'cassette_');
        file_put_contents($tmp, $contents);
        self::sudo('cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg($path));
        @unlink($tmp);
    }

    private static function sudo(string $cmd, bool $allowFail = false): void
    {
        $exit = 0;
        passthru('sudo ' . $cmd, $exit);
        if ($exit !== 0 && !$allowFail) {
            self::die("Command failed (exit $exit): sudo $cmd");
        }
    }

    // -----------------------------------------------------------------------
    // Output helpers
    // -----------------------------------------------------------------------

    private static function info(string $msg): void
    {
        fwrite(STDOUT, "\033[1;34m·\033[0m $msg\n");
    }

    private static function ok(string $msg): void
    {
        fwrite(STDOUT, "\033[1;32m✓\033[0m $msg\n");
    }

    private static function warn(string $msg): void
    {
        fwrite(STDERR, "\033[1;33m!\033[0m $msg\n");
    }

    private static function die(string $msg): never
    {
        fwrite(STDERR, "\033[1;31m✗\033[0m $msg\n");
        exit(1);
    }
}
