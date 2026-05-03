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
 * Additionally, native PHP date/time functions (time, microtime, date, gmdate,
 * mktime, gmmktime, idate, getdate, localtime, strtotime) are intercepted via
 * uopz and pinned to the same frozen instant. Unlike database/HTTP hooks
 * these are NOT recorded per call (volume would explode); they always derive
 * deterministically from the single timestamp persisted in the bucket — so
 * record and replay see byte-identical output even when the application
 * bypasses Carbon (e.g. raw `date('Y-m-d')` in a controller).
 *
 * Procedural equivalents `date_create()` / `date_create_immutable()` are also
 * covered (substituting "now"/null/empty with the frozen ISO string and
 * resolving relative inputs against the frozen base via a strtotime probe).
 *
 * Not covered: `new DateTime()` / `new DateTimeImmutable()` constructors —
 * uopz refuses to hook __construct ("will not override magic methods").
 * Workarounds: use date_create() / date_create_immutable() in app code, or
 * stick with Carbon (whose now() IS hookable via setTestNow).
 */
final class CassetteTime
{
    private const TIME_KEY = '__time__';

    public static function start(): void
    {
        $cls  = self::resolveCarbonClass();
        $mode = Cassette::getMode();

        $now = null;

        if ($mode === Cassette::MODE_RECORD) {
            $now = $cls !== null ? $cls::now() : new \DateTimeImmutable();
            // Illuminate\Support\Carbon::setTestNow propagates to BaseCarbon and
            // BaseCarbonImmutable, so a single call covers all Carbon classes
            // the app might use. Carbon\Carbon::setTestNow uses the global
            // factory which has the same reach.
            if ($cls !== null) {
                $cls::setTestNow($now);
            }
            Cassette::recordSerialized(self::TIME_KEY, [], serialize($now));
        } elseif ($mode === Cassette::MODE_MOCK) {
            $stored = Cassette::mock(self::TIME_KEY, []);
            if ($stored instanceof \DateTimeInterface) {
                $now = $stored;
                if ($cls !== null) {
                    $cls::setTestNow($now);
                }
            }
        }

        if ($now === null) {
            return;
        }

        if (extension_loaded('uopz')) {
            self::installNativeDateHooks($now);
        }
    }

    /**
     * Install uopz hooks that pin every native date/time function to $now.
     * No record/mock roundtrip — the value is derived from $now on every call.
     */
    private static function installNativeDateHooks(\DateTimeInterface $now): void
    {
        $ts      = $now->getTimestamp();
        $micro   = (int) $now->format('u');          // 0 .. 999999
        $microF  = $micro / 1_000_000;
        $tsFloat = $ts + $microF;

        uopz_set_return('time', static fn(): int => $ts, true);

        uopz_set_return(
            'microtime',
            static function (bool $asFloat = false) use ($ts, $microF, $tsFloat) {
                if ($asFloat) {
                    return $tsFloat;
                }
                // Native format: "0.xxxxxxxx ssssssssss"
                return sprintf('%0.8f %d', $microF, $ts);
            },
            true
        );

        // date() / gmdate() / idate(): replace null timestamp with frozen.
        // Closure::fromCallable bypasses uopz so the hook can call the real
        // function without unhook/rehook trickery.
        uopz_set_return(
            'date',
            static function (string $format, ?int $timestamp = null) use ($ts) {
                static $original = null;
                $original ??= Closure::fromCallable('\date');
                return $original($format, $timestamp ?? $ts);
            },
            true
        );

        uopz_set_return(
            'gmdate',
            static function (string $format, ?int $timestamp = null) use ($ts) {
                static $original = null;
                $original ??= Closure::fromCallable('\gmdate');
                return $original($format, $timestamp ?? $ts);
            },
            true
        );

        uopz_set_return(
            'idate',
            static function (string $format, ?int $timestamp = null) use ($ts) {
                static $original = null;
                $original ??= Closure::fromCallable('\idate');
                return $original($format, $timestamp ?? $ts);
            },
            true
        );

        uopz_set_return(
            'getdate',
            static function (?int $timestamp = null) use ($ts) {
                static $original = null;
                $original ??= Closure::fromCallable('\getdate');
                return $original($timestamp ?? $ts);
            },
            true
        );

        uopz_set_return(
            'localtime',
            static function (?int $timestamp = null, bool $associative = false) use ($ts) {
                static $original = null;
                $original ??= Closure::fromCallable('\localtime');
                return $original($timestamp ?? $ts, $associative);
            },
            true
        );

        // strtotime: relative expressions like "+1 day" use the second arg as
        // base; default is the (real) current time. Pin the base to frozen.
        uopz_set_return(
            'strtotime',
            static function (string $datetime, ?int $baseTimestamp = null) use ($ts) {
                static $original = null;
                $original ??= Closure::fromCallable('\strtotime');
                return $original($datetime, $baseTimestamp ?? $ts);
            },
            true
        );

        // date_create / date_create_immutable: procedural equivalents of the
        // DateTime / DateTimeImmutable constructors. They are regular functions
        // (not magic methods), so uopz hooks them happily — unlike __construct,
        // which uopz refuses with "will not override magic methods".
        //
        // For "now"/null/empty input we substitute the frozen ISO string.
        // For relative input ("+1 day", "yesterday"), a strtotime probe against
        // two distinct base timestamps detects relativity; if found, the
        // frozen-based resolution is used.
        $isoFrozen = $now->format('Y-m-d H:i:s.u');
        $relativiser = static function (string|false|null $datetime) use ($ts, $isoFrozen) {
            if ($datetime === null || $datetime === '' || $datetime === false || strcasecmp(trim((string) $datetime), 'now') === 0) {
                return $isoFrozen;
            }
            // strtotime is hooked too; explicit base values pass through to the
            // real implementation so the probe sees un-frozen parses.
            $base0   = @\strtotime((string) $datetime, 0);
            $baseFrz = @\strtotime((string) $datetime, $ts);
            if ($base0 !== false && $baseFrz !== false && $base0 !== $baseFrz) {
                return \date('Y-m-d H:i:s', $baseFrz);
            }
            return (string) $datetime;
        };

        uopz_set_return(
            'date_create',
            static function ($datetime = 'now', ?\DateTimeZone $timezone = null) use ($relativiser) {
                static $original = null;
                $original ??= Closure::fromCallable('\date_create');
                return $original($relativiser($datetime), $timezone);
            },
            true
        );

        uopz_set_return(
            'date_create_immutable',
            static function ($datetime = 'now', ?\DateTimeZone $timezone = null) use ($relativiser) {
                static $original = null;
                $original ??= Closure::fromCallable('\date_create_immutable');
                return $original($relativiser($datetime), $timezone);
            },
            true
        );

        // mktime / gmmktime: each null component falls back to the
        // corresponding component of the frozen instant.
        uopz_set_return(
            'mktime',
            static function (
                ?int $hour = null,
                ?int $minute = null,
                ?int $second = null,
                ?int $month = null,
                ?int $day = null,
                ?int $year = null
            ) use ($ts) {
                static $original = null;
                $original ??= Closure::fromCallable('\mktime');
                $parts = getdate($ts);
                return $original(
                    $hour ?? $parts['hours'],
                    $minute ?? $parts['minutes'],
                    $second ?? $parts['seconds'],
                    $month ?? $parts['mon'],
                    $day ?? $parts['mday'],
                    $year ?? $parts['year']
                );
            },
            true
        );

        uopz_set_return(
            'gmmktime',
            static function (
                ?int $hour = null,
                ?int $minute = null,
                ?int $second = null,
                ?int $month = null,
                ?int $day = null,
                ?int $year = null
            ) use ($ts) {
                static $original = null;
                $original ??= Closure::fromCallable('\gmmktime');
                // gmdate() is hooked too, so its 1-arg form would fall back to
                // the frozen $ts anyway — but pass $ts explicitly to make the
                // intent unambiguous and survive future refactors.
                $parts = explode(',', gmdate('G,i,s,n,j,Y', $ts));
                return $original(
                    $hour   ?? (int) $parts[0],
                    $minute ?? (int) $parts[1],
                    $second ?? (int) $parts[2],
                    $month  ?? (int) $parts[3],
                    $day    ?? (int) $parts[4],
                    $year   ?? (int) $parts[5]
                );
            },
            true
        );
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
