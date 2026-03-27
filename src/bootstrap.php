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
 * Add one line to wp-config.php (before "stop editing" comment):
 *
 *   require_once __DIR__ . '/vendor/vielhuber/cassette/src/bootstrap.php';
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
 */

// Priority 1: environment variables (CLI, cron, auto_prepend_file).
$cassetteMode = (string) ($_SERVER['CASSETTE_MODE'] ?? (getenv('CASSETTE_MODE') ?? ''));
$cassetteName = (string) ($_SERVER['CASSETTE_NAME'] ?? (getenv('CASSETTE_NAME') ?? ''));

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
        $control = json_decode((string) file_get_contents($controlFile), true);
        $cassetteMode = (string) ($control['mode'] ?? '');
        $cassetteName = (string) ($control['name'] ?? '');
    }
}

// No activation — skip everything (safe to require_once unconditionally).
if ($cassetteMode === '' || $cassetteName === '') {
    return;
}

// Auto-discover Composer autoloaders so uopz can hook classes like __::curl.
// Searches vendor/autoload.php in the site root and all theme/plugin directories.
// Uses require_once, so including multiple loaders is always safe.
(static function () use ($cassetteSiteRoot): void {
    $siteRoot = $cassetteSiteRoot;

    $patterns = [
        $siteRoot . '/vendor/autoload.php',
        $siteRoot . '/wp-content/themes/*/vendor/autoload.php',
        $siteRoot . '/wp-content/plugins/*/vendor/autoload.php',
        $siteRoot . '/wp-content/mu-plugins/*/vendor/autoload.php'
    ];

    foreach ($patterns as $pattern) {
        foreach (glob($pattern) ?: [] as $path) {
            require_once $path;
        }
    }
})();

require_once __DIR__ . '/Cassette.php';

Cassette::load(name: $cassetteName, mode: $cassetteMode, basePath: $cassetteSiteRoot . '/.cassette/runs');

// Install all uopz hooks (hooks read Cassette::getMode() at call time).
require_once __DIR__ . '/CassetteHooks.php';

// In record mode: capture the full HTTP request/response pair so CassetteReplay.php
// can compare actual responses against recorded ones later.
require_once __DIR__ . '/CassetteHttpRecorder.php';

CassetteHttpRecorder::start(cassetteName: $cassetteName, mode: $cassetteMode, basePath: $cassetteSiteRoot . '/.cassette/runs');

// Auto-save the tape when the PHP process exits (record mode only).
// Persist the replay pointer after each mock request so the next request
// in the sequence continues exactly where this one left off.
register_shutdown_function(static function (): void {
    Cassette::save();
    Cassette::savePointer();
});
