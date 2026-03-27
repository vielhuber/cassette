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
 * Create a control file at _cassette/.data/state.json:
 *
 *   {"mode": "record", "name": "save_contract_01"}   ← record real calls
 *   {"mode": "mock",   "name": "save_contract_01"}   ← replay from cassette
 *
 * Delete the "mode" key or set it to "" to deactivate. The bootstrap is a
 * no-op when the file is absent or mode is empty, so require_once is always safe.
 *
 * Add one line to wp-config.php (before "stop editing" comment):
 *
 *   require_once __DIR__ . '/_cassette/src/bootstrap.php';
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
 *     php -d auto_prepend_file=/var/www/project/_cassette/src/bootstrap.php \
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

// Priority 2: control file (web requests, WordPress).
if ($cassetteMode === '' || $cassetteName === '') {
    $controlFile = __DIR__ . '/../.data/state.json';

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
(static function (): void {
    $siteRoot = dirname(__DIR__, 2);

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

Cassette::load(name: $cassetteName, mode: $cassetteMode, basePath: __DIR__ . '/../.data');

// Install all uopz hooks (hooks read Cassette::getMode() at call time).
require_once __DIR__ . '/CassetteHooks.php';

// In record mode: capture the full HTTP request/response pair so CassetteReplay.php
// can compare actual responses against recorded ones later.
require_once __DIR__ . '/CassetteHttpRecorder.php';

CassetteHttpRecorder::start(cassetteName: $cassetteName, mode: $cassetteMode, basePath: __DIR__ . '/../.data');

// Auto-save the tape when the PHP process exits (record mode only).
// Persist the replay pointer after each mock request so the next request
// in the sequence continues exactly where this one left off.
register_shutdown_function(static function (): void {
    Cassette::save();
    Cassette::savePointer();
});
