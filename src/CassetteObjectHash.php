<?php
declare(strict_types=1);

/**
 * Deterministic replacement for spl_object_hash() and spl_object_id().
 *
 * Both built-ins return values derived from the Zend object handle / heap
 * address — which is reliably *unique per object within a request* but
 * differs between record and replay (and even between two consecutive runs
 * of the same script). Code that uses them as cache keys, dedup keys or
 * debug identifiers therefore drifts.
 *
 * Strategy: a per-request WeakMap maps each distinct object to a monotonic
 * counter. The first object encountered gets id 1, the second id 2, and so
 * on. As long as record and replay encounter objects in the same order
 * (which they do when all upstream non-determinism is mocked), the same
 * object always gets the same id in both runs.
 *
 * spl_object_hash() returns a 32-char hex string (matching the native
 * format); spl_object_id() returns the int.
 *
 * No record/mock roundtrip — hashes are derived from the WeakMap counter on
 * every call. Bucket size is unaffected.
 *
 * Limitation: when object creation order itself is non-deterministic (e.g.
 * an unhooked source of randomness inserts an extra object somewhere), the
 * counter assignment shifts. Fix the upstream non-determinism, not this.
 */
final class CassetteObjectHash
{
    private static ?\WeakMap $map = null;
    private static int $counter = 0;

    public static function start(): void
    {
        if (!Cassette::isActive() || !extension_loaded('uopz')) {
            return;
        }

        // Lazy-init on first hook invocation rather than here, so a
        // start()/reset() between tests in the same process gets a fresh map.
        self::$map     = new \WeakMap();
        self::$counter = 0;

        // Static closures invoked through uopz lose their enclosing class
        // scope, so `self::` raises "Cannot access self when no class scope
        // is active". Refer to the class by its fully-qualified name instead.
        uopz_set_return(
            'spl_object_hash',
            static function (object $obj): string {
                return str_pad(dechex(CassetteObjectHash::idFor($obj)), 32, '0', STR_PAD_LEFT);
            },
            true
        );

        uopz_set_return(
            'spl_object_id',
            static function (object $obj): int {
                return CassetteObjectHash::idFor($obj);
            },
            true
        );
    }

    public static function idFor(object $obj): int
    {
        self::$map ??= new \WeakMap();
        if (!isset(self::$map[$obj])) {
            self::$map[$obj] = ++self::$counter;
        }
        return self::$map[$obj];
    }
}
