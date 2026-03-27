<?php
declare(strict_types=1);

/**
 * uopz-based hooks for all intercepted external calls.
 *
 * Each hook checks Cassette::getMode():
 *   record → call real function, log result via Cassette::record()
 *   mock   → skip real function, return Cassette::mock()
 *
 * This file must be included AFTER Cassette.php and BEFORE application code runs.
 * No production code is modified.
 *
 * --- Why no uopz_unset_return + reinstall for record mode ---------------
 *
 * uopz_set_return() silently fails when called from inside a running hook
 * closure. The "unset → call real → reinstall" pattern therefore only records
 * the FIRST call; every subsequent call bypasses the hook.
 *
 * Fixes per hook type:
 *
 *   db_*         → In record mode, call $db->method() directly instead of the
 *                  global wrapper function. No reinstall needed at all.
 *
 *   __::curl()   → CassetteCurlRehook object: its __destruct() reinstalls the
 *                  hook AFTER the closure has returned and its local scope has
 *                  been cleaned up — outside uopz's hook execution context,
 *                  where uopz_set_return() works normally again.
 */

// Guard: uopz must be loaded.
if (!extension_loaded('uopz')) {
    throw new \RuntimeException(
        'uopz PHP extension is required for cassette hooks. ' . 'Install via: pecl install uopz'
    );
}

// -----------------------------------------------------------------------
// __::curl() — static method hook (optional: only installed when vielhuber/stringhelper is available)
// -----------------------------------------------------------------------

if (!class_exists('vielhuber\stringhelper\__', true)) {
    // vielhuber/stringhelper is not loaded — curl interception is skipped.
    // Add "vielhuber/stringhelper" to your project's composer.json to enable it.
    return;
}

/**
 * Reinstalls the __::curl() hook via its destructor.
 *
 * When an instance of this class goes out of scope (i.e. when the record-mode
 * closure returns and its local variables are cleaned up), __destruct() fires
 * from outside the uopz hook execution context, making uopz_set_return() work
 * correctly again for all subsequent curl calls.
 */
final class CassetteCurlRehook
{
    public function __destruct()
    {
        registerCurlHook();
    }
}

/**
 * Register (or re-register) the uopz hook for __::curl().
 */
function registerCurlHook(): void
{
    uopz_set_return(
        'vielhuber\stringhelper\__',
        'curl',
        static function () {
            $args = func_get_args();
            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                return Cassette::mock('__::curl', $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                // CassetteCurlRehook destructor reinstalls the hook after this
                // closure returns — when we are no longer inside uopz's hook context.
                $rehook = new CassetteCurlRehook();
                uopz_unset_return('vielhuber\stringhelper\__', 'curl');
                $result = \vielhuber\stringhelper\__::curl(...$args);
                Cassette::record('__::curl', $args, $result);
                return $result;
                // $rehook destructs here → registerCurlHook() called
            }

            // Hooks are only installed when cassette is active.
            throw new \LogicException('Cassette __::curl hook fired without active cassette mode.');
        },
        true
    );
}

registerCurlHook();

// -----------------------------------------------------------------------
// db_* global function hooks
// -----------------------------------------------------------------------

/**
 * Register hooks for all global db_* functions.
 */
(static function (): void {
    $functions = [
        'db_fetch_var',
        'db_fetch_row',
        'db_fetch_col',
        'db_fetch_all',
        'db_query',
        'db_insert',
        'db_update',
        'db_delete',
        'db_count',
        'db_last_insert_id'
    ];

    foreach ($functions as $function) {
        installDbHook($function);
    }
})();

/**
 * Install a uopz hook for a single global db_* function.
 *
 * In record mode, $db->method() is called directly instead of the hooked
 * global wrapper. This sidesteps the "reinstall inside hook closure" problem
 * entirely — the hook stays installed and intercepts every call.
 *
 * @param string $functionName e.g. "db_fetch_row"
 */
function installDbHook(string $functionName): void
{
    uopz_set_return(
        $functionName,
        static function () use ($functionName) {
            $args = func_get_args();
            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                return Cassette::mock($functionName, $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                // Call the underlying dbhelper method directly.
                // e.g. db_fetch_row → $db->fetch_row()
                // This avoids calling the hooked global function and therefore
                // requires no uopz_unset_return / reinstall juggling.
                global $db;
                $method = substr($functionName, 3); // strip leading "db_"
                $result = $db->$method(...$args);
                Cassette::record($functionName, $args, $result);
                return $result;
            }

            // Hooks are only installed when cassette is active.
            throw new \LogicException("Cassette $functionName hook fired without active cassette mode.");
        },
        true
    );
}
