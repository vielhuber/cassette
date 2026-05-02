<?php
declare(strict_types=1);

/**
 * Freeze "now" for the duration of a request so that any code path which
 * derives SQL bindings or response content from the current date/time
 * produces identical values during recording and replay.
 *
 * Without freezing, a query like
 *   WHERE birthday_md = ?  with bind = Carbon::now()->format('m-d')
 * binds today's date — recording captures '05-01' as the arg-key, replay on
 * a later day binds '05-02'. The mock's Tier-1 arg-key lookup misses, falls
 * through to the empty-array Tier-3 fallback for query callables, and the
 * "today's birthdays" widget renders empty.
 *
 * Mechanism: at request start in RECORD mode, capture Carbon::now() once and
 * call Carbon::setTestNow() so the rest of the request sees a stable time.
 * Persist the captured value alongside the bucket via Cassette's record API.
 * In MOCK mode, read back the recorded value and call setTestNow() with it.
 *
 * No-op when Carbon isn't autoloaded (non-Laravel/Carbon-free apps). Code
 * that calls PHP's built-in date()/time() directly bypasses Carbon and is
 * not frozen — those are too hot to safely intercept.
 */
final class CassetteTime
{
    private const TIME_KEY = '__time__';

    public static function start(): void
    {
        $cls = self::resolveCarbonClass();
        if ($cls === null) {
            return;
        }

        $mode = Cassette::getMode();

        if ($mode === Cassette::MODE_RECORD) {
            $now = $cls::now();
            // Illuminate\Support\Carbon::setTestNow propagates to BaseCarbon and
            // BaseCarbonImmutable, so a single call covers all Carbon classes
            // the app might use. Carbon\Carbon::setTestNow uses the global
            // factory which has the same reach.
            $cls::setTestNow($now);
            Cassette::recordSerialized(self::TIME_KEY, [], serialize($now));
            return;
        }

        if ($mode === Cassette::MODE_MOCK) {
            $now = Cassette::mock(self::TIME_KEY, []);
            if ($now instanceof \DateTimeInterface) {
                $cls::setTestNow($now);
            }
        }
    }

    private static function resolveCarbonClass(): ?string
    {
        // Prefer Laravel's Carbon wrapper because its setTestNow() also
        // freezes BaseCarbonImmutable (some apps mix both).
        if (class_exists('Illuminate\Support\Carbon')) {
            return 'Illuminate\Support\Carbon';
        }
        if (class_exists('Carbon\Carbon')) {
            return 'Carbon\Carbon';
        }
        return null;
    }
}
