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
 * --- Why reinstall happens in a destructor, not inside the hook ---------
 *
 * uopz_set_return() silently fails when called from inside a running hook
 * closure. CassetteRehook::__destruct() fires after the closure returns and
 * its local scope is cleaned up — outside uopz's hook execution context —
 * where uopz_set_return() works correctly again.
 */

if (!extension_loaded('uopz')) {
    throw new \RuntimeException(
        'uopz PHP extension is required for cassette hooks. Install via: pecl install uopz'
    );
}

// -----------------------------------------------------------------------
// Built-in hook profiles
// -----------------------------------------------------------------------

/** @var list<string> */
$cassetteBuiltinFunctions = [
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
];

/** @var array<string, list<string>> */
$cassetteBuiltinStaticMethods = [
    'vielhuber\stringhelper\__' => ['curl'],
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
];

// -----------------------------------------------------------------------
// Generic rehook helper
// -----------------------------------------------------------------------

/**
 * Reinstalls any cassette hook via its destructor.
 *
 * Store an instance as a local variable inside a record-mode hook closure.
 * When the closure returns and PHP destroys the local variable, __destruct()
 * fires from outside the uopz hook execution context, making uopz_set_return()
 * work correctly again.
 */
final class CassetteRehook
{
    public function __construct(private readonly \Closure $reinstaller) {}

    public function __destruct()
    {
        ($this->reinstaller)();
    }
}

// -----------------------------------------------------------------------
// Hook installer functions
// -----------------------------------------------------------------------

/**
 * Install a uopz hook for a global PHP function.
 * Skipped silently when the function does not exist.
 */
function installFunctionHook(string $fn): void
{
    if (!function_exists($fn)) {
        return;
    }

    uopz_set_return(
        $fn,
        static function () use ($fn) {
            $args = func_get_args();
            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                return Cassette::mock($fn, $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                $rehook = new CassetteRehook(static fn() => installFunctionHook($fn));
                uopz_unset_return($fn);
                $result = $fn(...$args);
                // Serialize once: the same string forces fresh non-interned string
                // allocations (avoids uopz interned-string-pool corruption) AND is
                // reused as the cassette tape entry — halves the hot-path work.
                $serialized = serialize($result);
                $result     = unserialize($serialized);
                Cassette::recordSerialized($fn, $args, $serialized);
                return $result;
                // $rehook destructs here → reinstalls hook
            }

            throw new \LogicException("Cassette $fn hook fired without active cassette mode.");
        },
        true
    );
}

/**
 * Install a uopz hook for a static class method.
 * Skipped silently when the class does not exist.
 *
 * Cassette key: "ShortClassName::method".
 */
function installStaticMethodHook(string $class, string $method): void
{
    if (!class_exists($class, true) || !method_exists($class, $method)) {
        return;
    }

    $key = (new \ReflectionClass($class))->getShortName() . '::' . $method;

    uopz_set_return(
        $class,
        $method,
        static function () use ($class, $method, $key) {
            $args = func_get_args();
            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                return Cassette::mock($key, $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                $rehook = new CassetteRehook(static fn() => installStaticMethodHook($class, $method));
                uopz_unset_return($class, $method);
                $result = $class::$method(...$args);
                // Serialize once — see comment in installFunctionHook().
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
 * Skipped silently when the class does not exist.
 *
 * The closure executes in the instance's scope (execute=true), so $this
 * refers to the actual object whose method was intercepted.
 *
 * Cassette key: "ShortClassName::method".
 */
function installInstanceMethodHook(string $class, string $method): void
{
    if (!class_exists($class, true)) {
        Cassette::log("SKIP instance hook $class::$method — class not found");
        return;
    }
    if (!method_exists($class, $method)) {
        Cassette::log("SKIP instance hook $class::$method — method not found");
        return;
    }
    Cassette::log("INSTALL instance hook $class::$method");

    $key = (new \ReflectionClass($class))->getShortName() . '::' . $method;

    uopz_set_return(
        $class,
        $method,
        function () use ($class, $method, $key) {
            $args = func_get_args();
            $mode = Cassette::getMode();

            if ($mode === Cassette::MODE_MOCK) {
                return Cassette::mock($key, $args);
            }

            if ($mode === Cassette::MODE_RECORD) {
                Cassette::log("HOOK FIRED record: $key args=" . json_encode(array_slice($args, 0, 1)));
                $that   = $this;
                $rehook = new CassetteRehook(static fn() => installInstanceMethodHook($class, $method));
                uopz_unset_return($class, $method);
                $result = $that->$method(...$args);
                // Serialize once — the same string forces fresh non-interned string
                // allocations (PDO property names otherwise reference corrupted interned
                // strings) AND becomes the cassette tape entry. Halves hot-path work.
                $serialized = serialize($result);
                $result     = unserialize($serialized);
                Cassette::recordSerialized($key, $args, $serialized);
                return $result;
            }

            Cassette::log("HOOK FIRED but mode empty/unknown: '$mode' for $key");
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

foreach ($cassetteAllFunctions as $fn) {
    installFunctionHook((string) $fn);
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



