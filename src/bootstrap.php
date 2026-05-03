<?php
declare(strict_types=1);

/**
 * Cassette bootstrap — low-level record/replay for any PHP application.
 *
 * Works with WordPress, plain PHP scripts, CLI tools, or any other entry
 * point. Has no dependency on MCP, WordPress, or any framework.
 *
 * --- Activation (web context — recommended) ------------------------------
 *
 * Create a control file at .cassette/state.json in the project root:
 *
 *   {"mode": "record", "name": "save_contract_01"}   ← record real calls
 *   {"mode": "mock",   "name": "save_contract_01"}   ← replay from cassette
 *
 * Delete the "mode" key or set it to "" to deactivate. The bootstrap is a
 * no-op when the file is absent or mode is empty, so require_once is always safe.
 *
 * --- Activation (CLI context) --------------------------------------------
 *
 * Environment variables override active.json:
 *
 *   CASSETTE_MODE=record CASSETTE_NAME=save_contract_01 php index.php
 *   CASSETTE_MODE=mock   CASSETTE_NAME=save_contract_01 php index.php
 *
 * Or via auto_prepend_file without touching any source file:
 *
 *   CASSETTE_MODE=record CASSETTE_NAME=save_contract_01 \
 *     php -d auto_prepend_file=/var/www/project/vendor/vielhuber/cassette/src/bootstrap.php \
 *     /var/www/project/index.php
 *
 * --- What happens when active -----------------------------------------
 *
 *   1. Cassette.php and CassetteHooks.php are loaded.
 *   2. Cassette::load() initialises the tape for the given mode and name.
 *   3. uopz hooks replace __::curl() and all db_* functions process-wide.
 *   4. A shutdown handler persists the tape to JSON in record mode.
 *
 * --- No-op guarantee ----------------------------------------------------
 *
 * All work happens inside an anonymous function so not a single variable
 * leaks into the caller's scope when cassette is inactive.  Including this
 * file from wp-config.php, public/index.php, or any other entry point is
 * completely side-effect-free as long as neither CASSETTE_MODE is exported
 * nor .cassette/state.json exists.
 */

(static function (): void {
    // Priority 1: environment variables (CLI, cron, auto_prepend_file).
    $cassetteMode = (string) ($_SERVER['CASSETTE_MODE'] ?? (getenv('CASSETTE_MODE') ?: ''));
    $cassetteName = (string) ($_SERVER['CASSETTE_NAME'] ?? (getenv('CASSETTE_NAME') ?: ''));

    // Locate project root: works for both regular Composer install and path-repository symlinks.
    // Normal: vendor/vielhuber/cassette/src → 4 levels up = project root.
    // Path-repo: __DIR__ resolves to the actual source location (outside vendor/), so we walk up
    // SCRIPT_FILENAME to find the nearest ancestor directory that contains a vendor/ folder.
    $cassetteSiteRoot = (static function (): string {
        $envRoot = (string) ($_SERVER['CASSETTE_ROOT'] ?? (getenv('CASSETTE_ROOT') ?: ''));
        if ($envRoot !== '') {
            return rtrim($envRoot, '/');
        }
        if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', __DIR__), 'vendor/vielhuber/cassette/src')) {
            return dirname(__DIR__, 4);
        }
        $script = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '';
        if ($script !== '') {
            $dir = dirname($script);
            while ($dir !== '/' && $dir !== '') {
                if (is_dir($dir . '/vendor')) {
                    return $dir;
                }
                $dir = dirname($dir);
            }
        }
        return dirname(__DIR__, 4);
    })();

    // Priority 2: control file (web requests, WordPress).
    if ($cassetteMode === '' || $cassetteName === '') {
        $controlFile = $cassetteSiteRoot . '/.cassette/state.json';

        if (is_file($controlFile)) {
            $control      = json_decode((string) file_get_contents($controlFile), true);
            $cassetteMode = (string) ($control['mode'] ?? '');
            $cassetteName = (string) ($control['name'] ?? '');
        }
    }

    // No activation — skip everything (safe to require_once unconditionally).
    if ($cassetteMode === '' || $cassetteMode === 'stopped' || $cassetteName === '') {
        return;
    }

    // Auto-discover Composer autoloaders so uopz can hook classes like __::curl.
    // Searches vendor/autoload.php in the site root and all theme/plugin directories.
    // Uses require_once, so including multiple loaders is always safe.
    $autoloadPatterns = [
        $cassetteSiteRoot . '/vendor/autoload.php',
        $cassetteSiteRoot . '/wp-content/themes/*/vendor/autoload.php',
        $cassetteSiteRoot . '/wp-content/plugins/*/vendor/autoload.php',
        $cassetteSiteRoot . '/wp-content/mu-plugins/*/vendor/autoload.php'
    ];

    foreach ($autoloadPatterns as $autoloadPattern) {
        foreach (glob($autoloadPattern) ?: [] as $autoloadPath) {
            require_once $autoloadPath;
        }
    }

    require_once __DIR__ . '/Cassette.php';

    Cassette::load(name: $cassetteName, mode: $cassetteMode, basePath: $cassetteSiteRoot . '/.cassette/runs');

    // Log the shutdown mode for diagnostics. The actual save()/savePointer()
    // call is deferred to a second handler (see below) which fires *after*
    // CassetteHttpRecorder has finished capturing the response.
    register_shutdown_function(static function (): void {
        Cassette::log("shutdown: mode='" . Cassette::getMode() . "'");
    });

    // Load project config (.cassette/config.json) and share it with Cassette
    // so CassetteHooks.php can read the "hooks" configuration.
    $cassetteProjectConfig = [];
    $cassetteConfigFile    = $cassetteSiteRoot . '/.cassette/config.json';
    if (is_file($cassetteConfigFile)) {
        $cassetteProjectConfig = json_decode((string) file_get_contents($cassetteConfigFile), true) ?? [];
    }
    Cassette::setConfig($cassetteProjectConfig);

    // -------------------------------------------------------------------
    // Domain-extension hooks
    // -------------------------------------------------------------------
    //
    // Each entry is a static class with a `start()` method that installs its
    // own uopz hooks (and/or persists a snapshot via Cassette::recordSerialized
    // / reads it back via Cassette::mock). Order matters:
    //
    //   - PRE_HOOK extensions run BEFORE generic CassetteHooks.php so any
    //     uopz hook they install is visible to the very first relevant call
    //     (e.g. CassetteTime freezes Carbon::now before the app boots).
    //   - POST_HOOK extensions run AFTER CassetteHooks.php — useful when the
    //     extension depends on a hook installed there.
    //
    // To add a new domain hook:
    //   1. Create src/CassetteFoo.php with `final class CassetteFoo` and a
    //      static `start(): void` method.
    //   2. Append the bare class name to the relevant array below.
    //
    // For simple per-call function/method record/mock without custom logic,
    // just append to $cassetteBuiltinFunctions / $cassetteBuiltinStaticMethods
    // / $cassetteBuiltinInstanceMethods inside CassetteHooks.php — or use the
    // user-facing `hooks` key in .cassette/config.json (no PHP edits needed).
    $cassetteExtensionsPreHooks = [
        'CassetteTime',         // freeze now() + native date/time fns + DateTime ctor
    ];
    $cassetteExtensionsPostHooks = [
        'CassetteObjectHash',   // deterministic spl_object_hash / spl_object_id
    ];

    foreach ($cassetteExtensionsPreHooks as $cassetteExt) {
        require_once __DIR__ . '/' . $cassetteExt . '.php';
        $cassetteExt::start();
    }

    // Install all uopz hooks (hooks read Cassette::getMode() at call time).
    require_once __DIR__ . '/CassetteHooks.php';

    foreach ($cassetteExtensionsPostHooks as $cassetteExt) {
        require_once __DIR__ . '/' . $cassetteExt . '.php';
        $cassetteExt::start();
    }

    // In record mode: capture the full HTTP request/response pair so CassetteReplay.php
    // can compare actual responses against recorded ones later.
    require_once __DIR__ . '/CassetteHttpRecorder.php';

    CassetteHttpRecorder::start(cassetteName: $cassetteName, mode: $cassetteMode, basePath: $cassetteSiteRoot . '/.cassette/runs');

    // Ensure save/pointer also runs after CassetteHttpRecorder has completed its
    // shutdown work. PHP calls shutdown functions in FIFO order, so this second
    // handler (registered after CassetteHttpRecorder::start()) fires after the
    // recorder — guaranteeing any intercepted calls made by the recorder are
    // captured before the bucket is written to disk.
    register_shutdown_function(static function (): void {
        $mode = Cassette::getMode();
        if ($mode === Cassette::MODE_RECORD) {
            Cassette::save();
        } elseif ($mode === Cassette::MODE_MOCK) {
            Cassette::savePointer();
        }
    });
})();
