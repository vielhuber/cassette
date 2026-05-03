<?php
declare(strict_types=1);

/**
 * uopz hooks for all intercepted external calls.
 *
 * Built-in hook profiles are installed automatically whenever the target
 * function or class is available at runtime. No manual configuration is
 * required.
 *
 * Project-specific additional hooks can be declared in .cassette/config.json:
 *
 *   "hooks": {
 *     "functions":        ["my_global_fn"],
 *     "static_methods":   {"My\\Namespace\\ClassName": ["method"]},
 *     "instance_methods": {"My\\Namespace\\ClassName": ["method"]}
 *   }
 *
 * Built-in profiles (auto-discovered):
 *   functions        → db_fetch_var, db_fetch_row, db_fetch_col, db_fetch_all,
 *                      db_query, db_insert, db_update, db_delete, db_count,
 *                      db_last_insert_id
 *   static_methods   → vielhuber\stringhelper\__::curl
 *   instance_methods → Illuminate\Database\Connection:
 *                        select, insert, update, delete,
 *                        statement, affectingStatement
 *
 * Cassette key format in data.json:
 *   functions        → function name ("db_fetch_row")
 *   static_methods   → "ShortClass::method"   ("__::curl")
 *   instance_methods → "ShortClass::method"   ("Connection::select")
 *
 * --- How original functions get called from inside a hook --------------
 *
 * `uopz_set_return($fn, ..., true)` replaces $fn entirely. To then call the
 * original from inside the hook closure we capture it as a Closure (or a
 * ReflectionMethod for instance methods) — Closure::fromCallable() and
 * ReflectionMethod::invoke() bypass uopz, so the hook never has to unhook
 * and rehook itself. The capture happens lazily on first invocation (cached
 * in a static), since uopz_set_return accepts hooks for functions/methods
 * that are not yet defined at install time — see next section.
 *
 * --- Pre-registering hooks for not-yet-loaded helpers -------------------
 *
 * uopz happily accepts uopz_set_return calls for functions, static methods
 * and instance methods that do not exist yet — once the host application
 * (e.g. a WordPress theme) later defines the helper, the hook is already in
 * place and fires from the very first call. This is why every configured
 * hook is installed unconditionally at bootstrap, even when the target is
 * still missing: no late-init canary is needed.
 */

if (!extension_loaded('uopz')) {
    $phpVersion   = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $phpSapi      = PHP_SAPI;
    $iniFiles     = php_ini_loaded_file() ?: 'none';
    $scanDir      = php_ini_scanned_files() ?: 'none';
    $extDir       = ini_get('extension_dir') ?: 'unknown';
    $soExists     = is_file($extDir . '/uopz.so') ? 'yes' : 'no';
    $zendExts     = implode(', ', get_loaded_extensions(true)) ?: 'none';
    $regularExts  = implode(', ', get_loaded_extensions(false)) ?: 'none';
    $errorLog     = ini_get('error_log') ?: 'none';

    // read last 20 lines of the php error log for uopz-related entries
    $logSnippet = '';
    if ($errorLog !== 'none' && is_readable($errorLog)) {
        $lines = file($errorLog) ?: [];
        $recent = array_slice($lines, -20);
        $relevant = array_filter($recent, static fn(string $l): bool => stripos($l, 'uopz') !== false || stripos($l, 'Cannot load') !== false || stripos($l, 'Unable to load') !== false);
        if ($relevant !== []) {
            $logSnippet = "\n  error_log matches:\n    " . implode('    ', $relevant);
        }
    }

    $xdebugHint = extension_loaded('xdebug')
        ? "\n  *** Xdebug is loaded — this prevents uopz from initialising. ***\n" .
          "  Disable it before enabling uopz:\n" .
          "    sudo phpdismod -v {$phpVersion} xdebug && sudo systemctl restart php{$phpVersion}-fpm\n"
        : '';

    throw new \RuntimeException(
        "uopz PHP extension is not loaded.\n" .
        "  PHP version   : {$phpVersion}\n" .
        "  SAPI          : {$phpSapi}\n" .
        "  uopz.so       : {$extDir}/uopz.so (exists: {$soExists})\n" .
        "  php.ini       : {$iniFiles}\n" .
        "  error_log     : {$errorLog}\n" .
        "  Zend exts     : {$zendExts}\n" .
        "  regular exts  : {$regularExts}\n" .
        "  scanned       : {$scanDir}\n" .
        $logSnippet .
        $xdebugHint .
        "\n" .
        "Install and enable:\n" .
        "  sudo apt-get install php{$phpVersion}-uopz\n" .
        "  sudo phpenmod -v {$phpVersion} uopz\n" .
        "  sudo phpdismod -v {$phpVersion} xdebug\n" .
        "  sudo systemctl restart php{$phpVersion}-fpm\n" .
        "\n" .
        "Verify it is loaded for the correct SAPI ({$phpSapi}):\n" .
        "  php{$phpVersion} -m | grep uopz            # CLI\n" .
        "  php-fpm{$phpVersion} -m | grep uopz        # FPM\n"
    );
}

// -----------------------------------------------------------------------
// Built-in hook profiles
// -----------------------------------------------------------------------

/** @var list<string> */
$cassetteBuiltinFunctions = [
    // ── Database (in-house wrapper) ──────────────────────────────────────
    'db_fetch_var',
    'db_fetch_row',
    'db_fetch_col',
    'db_fetch_all',
    'db_query',
    'db_insert',
    'db_update',
    'db_delete',
    'db_count',
    'db_last_insert_id',

    // ── Randomness ───────────────────────────────────────────────────────
    // Each call returns a different value, so two consecutive random_bytes(16)
    // calls produce two distinct entries with the same arg-key '[16]'. The
    // 4-tier mock matcher serves them in recording order (Tier 1 within the
    // matching arg-key group is sequential).
    'rand',
    'mt_rand',
    'random_bytes',
    'random_int',

    // ── Environment / process identity ───────────────────────────────────
    // getenv() with no args returns the full env array (one big entry); with
    // a key it returns a single string|false (one entry per distinct key).
    // Direct $_ENV / $_SERVER reads are NOT covered: Cassette's bootstrap
    // runs before Laravel's Dotenv populates them, so a snapshot here would
    // capture pre-Dotenv state. Apps that read env vars via getenv() (the
    // recommended Laravel idiom) are fully covered.
    'getenv',
    'gethostname',
    'gethostbyname',
    'getmypid',
    'posix_getpid',
];

/**
 * Filesystem read functions — captured at recording time so replay reproduces
 * the exact state, even when the underlying file was later deleted, modified
 * or moved during the same recording (e.g. user deletes a PDF halfway through
 * the flow; earlier requests reading that file still need to see the original
 * content on replay).
 *
 * Installed via a dedicated hook (installFilesystemFunctionHook) that bypasses
 * any path under `.cassette/` so Cassette's own bucket reads do not recurse
 * into the hook (which would call Cassette::mock() → file_get_contents() →
 * hook → Cassette::mock() → infinite loop).
 *
 * @var list<string>
 */
// Each entry is either a bare function name (path is args[0]) or a
// "function:argIndex" pair when the path argument isn't first — e.g.
// hash_file($algo, $filename) puts the path at index 1.
$cassetteBuiltinFilesystemFunctions = [
    // Content
    'file_get_contents',
    'file',                 // returns array of lines
    // Existence / metadata
    'file_exists',
    'is_file',
    'is_dir',
    'is_readable',
    'filesize',
    'filemtime',
    'filectime',
    'fileatime',
    'fileperms',
    'fileowner',
    'filegroup',
    'fileinode',
    'filetype',
    'realpath',
    'stat',
    'lstat',
    // Listings
    'glob',
    'scandir',
    // Hashing / MIME / image
    'md5_file',
    'sha1_file',
    'hash_file:1',          // hash_file($algo, $filename, …)
    'crc32_file',
    'mime_content_type',
    'getimagesize',
    // Disk-level
    'disk_free_space',
    'disk_total_space',
];

/**
 * Filesystem write/delete functions — captured during RECORD; on MOCK the
 * recorded return is served without performing the real side effect.
 *
 * This is what stops a replay from re-deleting (or re-creating, re-touching)
 * files after the recording's original side effect already mutated them.
 * Critical example: deleting an attorney unlinks the associated PDF during
 * recording — replay must return the recorded `true` instead of unlinking
 * the (already gone) file again, which would otherwise raise "No such file".
 *
 * Like the read variants, these go through installFilesystemFunctionHook so
 * paths under `/.cassette/` bypass the hook (Cassette itself writes buckets,
 * the pointer file, logs, etc. via these very functions).
 *
 * Unlike reads, NO fall-through to the real function on a mock miss: replay
 * must never mutate the disk, so a missing entry returns null/false instead.
 *
 * @var list<string>
 */
$cassetteBuiltinFilesystemWriteFunctions = [
    'unlink',
    'file_put_contents',
    'mkdir',
    'rmdir',
    'rename',
    'copy',
    'touch',
    'chmod',
];

/** @var array<string, list<string>> */
$cassetteBuiltinStaticMethods = [
    'vielhuber\stringhelper\__' => ['curl'],

    // UUID generators — random per call, so the recording-time UUID would
    // never match the replay-time UUID without hooks. Hooking them captures
    // the exact UUID generated during recording; replay returns the same
    // value, so any URL/payload that embeds the UUID (e.g. a 302 redirect
    // to /resource/<uuid>) is identical record vs replay, and subsequent
    // SELECT * WHERE id = <uuid> queries also hit a matching mock entry.
    'Illuminate\Support\Str' => ['uuid', 'uuid7', 'orderedUuid'],
    'Ramsey\Uuid\Uuid'       => ['uuid1', 'uuid4', 'uuid6', 'uuid7'],
    'Symfony\Component\Uid\Uuid' => ['v1', 'v4', 'v6', 'v7'],
];

/** @var array<string, list<string>> */
$cassetteBuiltinInstanceMethods = [
    'Illuminate\Database\Connection' => [
        'select',
        'insert',
        'update',
        'delete',
        'statement',
        'affectingStatement',
    ],
    // Laravel session handlers — the active driver depends on session.php config,
    // but all available handlers are hooked unconditionally; uopz silently skips
    // ones whose class isn't loaded. Hooking read() in particular is what lets
    // file-based sessions replay faithfully: without it the recording-time
    // session file on disk is consumed by the recording itself (e.g. flash
    // messages cleared after first display) so a replay reading the same file
    // gets the post-consumption state. With the hook, each request's read
    // returns the exact blob the handler returned at recording time.
    'Illuminate\Session\FileSessionHandler'      => ['read', 'write', 'destroy'],
    'Illuminate\Session\DatabaseSessionHandler'  => ['read', 'write', 'destroy'],
    'Illuminate\Session\CookieSessionHandler'    => ['read', 'write', 'destroy'],
    'Illuminate\Session\CacheBasedSessionHandler'=> ['read', 'write', 'destroy'],
    'Illuminate\Session\ArraySessionHandler'     => ['read', 'write', 'destroy'],
    'Illuminate\Session\NullSessionHandler'      => ['read', 'write', 'destroy'],
];

// -----------------------------------------------------------------------
// Hook installer functions
// -----------------------------------------------------------------------

/**
 * Install a uopz hook for a global PHP function.
 * Skipped silently when the function does not exist.
 */
function installFunctionHook(string $fn): void
{
    Cassette::log("INSTALL function hook $fn");

    uopz_set_return(
        $fn,
        static function () use ($fn) {
            $args = func_get_args();
            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                return Cassette::mock($fn, $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                // Capture the unhooked function lazily (and cache the Closure
                // for subsequent calls). Lazy because uopz lets us install the
                // hook before the function exists — we only know it is loaded
                // by the time the hook actually fires.
                static $original = null;
                if ($original === null) {
                    $original = Closure::fromCallable($fn);
                }

                $result     = $original(...$args);
                // unserialize(serialize(...)) gives us a deep copy that goes
                // through the same data path as replay (where mock() returns
                // unserialize($entry['return'])). Keeps the value seen by the
                // caller identical between record and replay runs.
                $serialized = serialize($result);
                $result     = unserialize($serialized);
                Cassette::recordSerialized($fn, $args, $serialized);
                return $result;
            }

            throw new \LogicException("Cassette $fn hook fired without active cassette mode.");
        },
        true
    );
}

/**
 * Install a uopz hook for a filesystem function (read or write).
 *
 * Two key differences from installFunctionHook:
 *
 *   1. Any call whose first argument contains `/.cassette/` bypasses the hook
 *      and runs the original. Cassette itself reads buckets and writes the
 *      pointer/log/bucket files via these very functions — without the bypass
 *      a hook would re-enter Cassette::mock() / save() and recurse forever.
 *
 *   2. $fallThrough controls miss behaviour in MOCK mode:
 *        - true  (default, used for reads): on no arg-key match, call the
 *          original. Required because file_exists() must return bool — a
 *          `null` from an exhausted bucket breaks autoloader probes — and
 *          because old recordings that pre-date the hook never captured the
 *          call.
 *        - false (used for writes): on a miss, return null. Replay MUST NEVER
 *          mutate the disk, so unlink/file_put_contents/… can't fall through.
 */
function installFilesystemFunctionHook(string $fn, bool $fallThrough = true, int $pathArgIndex = 0): void
{
    Cassette::log("INSTALL filesystem function hook $fn (pathArg=$pathArgIndex, fallThrough=" . ($fallThrough ? 'yes' : 'no') . ')');

    uopz_set_return(
        $fn,
        static function () use ($fn, $fallThrough, $pathArgIndex) {
            $args = func_get_args();
            $path = (string) ($args[$pathArgIndex] ?? '');

            static $original = null;
            if ($original === null) {
                $original = Closure::fromCallable($fn);
            }

            if (str_contains($path, '/.cassette/')) {
                return $original(...$args);
            }

            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                if ($fallThrough && !Cassette::hasArgKeyMatch($fn, $args)) {
                    return $original(...$args);
                }
                return Cassette::mock($fn, $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                $result     = $original(...$args);
                $serialized = serialize($result);
                $result     = unserialize($serialized);
                Cassette::recordSerialized($fn, $args, $serialized);
                return $result;
            }

            throw new \LogicException("Cassette $fn hook fired without active cassette mode.");
        },
        true
    );
}

/**
 * Install a uopz hook for a static class method.
 * Skipped silently when the class does not exist — uopz_set_return for class
 * methods (unlike for free functions) requires the target class to exist at
 * install time. class_exists($class, true) triggers the registered autoloader
 * so Composer-loaded classes resolve here without forcing an explicit require.
 *
 * Cassette key: "ShortClassName::method".
 */
function installStaticMethodHook(string $class, string $method): void
{
    if (!class_exists($class, true) || !method_exists($class, $method)) {
        Cassette::log("SKIP static hook $class::$method — class/method not loadable");
        return;
    }
    Cassette::log("INSTALL static hook $class::$method");

    $key      = (new \ReflectionClass($class))->getShortName() . '::' . $method;
    $original = Closure::fromCallable([$class, $method]);

    uopz_set_return(
        $class,
        $method,
        static function () use ($key, $original) {
            $args = func_get_args();
            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                return Cassette::mock($key, $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                $result     = $original(...$args);
                $serialized = serialize($result);
                $result     = unserialize($serialized);
                Cassette::recordSerialized($key, $args, $serialized);
                return $result;
            }

            throw new \LogicException("Cassette $key hook fired without active cassette mode.");
        },
        true
    );
}

/**
 * Install a uopz hook for an instance method.
 * Skipped silently when the class is not loadable — see installStaticMethodHook.
 *
 * The closure executes in the instance's scope (execute=true), so $this
 * refers to the actual object whose method was intercepted.
 *
 * Cassette key: "ShortClassName::method".
 */
function installInstanceMethodHook(string $class, string $method): void
{
    if (!class_exists($class, true) || !method_exists($class, $method)) {
        Cassette::log("SKIP instance hook $class::$method — class/method not loadable");
        return;
    }
    Cassette::log("INSTALL instance hook $class::$method");

    $key        = (new \ReflectionClass($class))->getShortName() . '::' . $method;
    $reflection = new \ReflectionMethod($class, $method);

    uopz_set_return(
        $class,
        $method,
        function () use ($key, $reflection) {
            $args = func_get_args();
            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                return Cassette::mock($key, $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                // ReflectionMethod::invoke() bypasses uopz so we call the
                // original method on the current $this without any
                // unhook/rehook trickery.
                $result     = $reflection->invoke($this, ...$args);
                $serialized = serialize($result);
                $result     = unserialize($serialized);
                Cassette::recordSerialized($key, $args, $serialized);
                return $result;
            }

            throw new \LogicException("Cassette $key hook fired without active cassette mode.");
        },
        true
    );
}

// -----------------------------------------------------------------------
// Install: built-in profiles + optional project-specific additions
// -----------------------------------------------------------------------

$cassetteExtraConfig = Cassette::getConfig()['hooks'] ?? [];

$cassetteAllFunctions = array_unique(array_merge(
    $cassetteBuiltinFunctions,
    (array) ($cassetteExtraConfig['functions'] ?? [])
));

$cassetteAllStaticMethods = array_merge_recursive(
    $cassetteBuiltinStaticMethods,
    (array) ($cassetteExtraConfig['static_methods'] ?? [])
);
// Deduplicate per-class method lists to prevent double hook installation.
foreach ($cassetteAllStaticMethods as $class => $methods) {
    $cassetteAllStaticMethods[$class] = array_values(array_unique((array) $methods));
}

$cassetteAllInstanceMethods = array_merge_recursive(
    $cassetteBuiltinInstanceMethods,
    (array) ($cassetteExtraConfig['instance_methods'] ?? [])
);
foreach ($cassetteAllInstanceMethods as $class => $methods) {
    $cassetteAllInstanceMethods[$class] = array_values(array_unique((array) $methods));
}

// Install every configured hook unconditionally. uopz_set_return accepts
// hooks for functions, static methods and instance methods that are not yet
// defined — once the host application later loads them, the hook is already
// in place and fires from the very first call.

foreach ($cassetteAllFunctions as $fn) {
    installFunctionHook((string) $fn);
}

// Each entry is "name" or "name:argIndex" — split and dispatch.
foreach ($cassetteBuiltinFilesystemFunctions as $entry) {
    [$fn, $argIdx] = str_contains((string) $entry, ':')
        ? [explode(':', (string) $entry)[0], (int) explode(':', (string) $entry)[1]]
        : [(string) $entry, 0];
    installFilesystemFunctionHook($fn, fallThrough: true, pathArgIndex: $argIdx);
}

foreach ($cassetteBuiltinFilesystemWriteFunctions as $fn) {
    installFilesystemFunctionHook((string) $fn, fallThrough: false);
}

foreach ($cassetteAllStaticMethods as $class => $methods) {
    foreach ((array) $methods as $method) {
        installStaticMethodHook((string) $class, (string) $method);
    }
}

foreach ($cassetteAllInstanceMethods as $class => $methods) {
    foreach ((array) $methods as $method) {
        installInstanceMethodHook((string) $class, (string) $method);
    }
}



