<?php
declare(strict_types=1);

/**
 * Core cassette engine for record/replay testing.
 *
 * Uses per-request, per-callable sub-queues. Each HTTP request gets its own
 * isolated bucket of recorded return values. Mock mode only consumes entries
 * from the bucket that belongs to the current request index, so a POST request
 * that makes a different number of DB calls than during recording can never
 * shift the queue for subsequent requests.
 *
 * In mock mode, entries are matched by the normalised first argument (SQL query
 * or URL) rather than by sequential position.  This means a call that was
 * recorded but is skipped at replay time (e.g. behind an auth gate) does NOT
 * consume a slot that the next call then inherits.  When multiple entries share
 * the same normalised key (e.g. repeated "SELECT * FROM customer WHERE ID = ?"
 * calls with different bound params) they are served in recording order within
 * that group.  If no key match is found the engine falls back to the next
 * unconsumed entry and logs a warning.
 *
 * Cassette JSON format (array of per-request buckets):
 *   [
 *     {"db_fetch_row": [{"args":[...],"return":"..."},...], "__::curl": [...], "Connection::select": [...]},
 *     {"db_query": [...], "Connection::insert": [...]},
 *     ...
 *   ]
 *
 * .pointer format:  {"_request_index": 3}
 *
 * No production code changes are required. All hooking is done via uopz.
 */
final class Cassette
{
    public const MODE_RECORD = 'record';
    public const MODE_MOCK = 'mock';

    private static string $mode = '';
    private static string $cassettePath = '';
    private static string $pointerPath = '';
    private static string $bucketDir = '';

    /**
     * The bucket being written (record) or read (mock) for the current request.
     *
     * @var array<string, list<array{args: array, return: string}>>
     */
    private static array $current = [];

    /** @var array<string, int> Per-callable read heads — used only by the sequential fallback. */
    private static array $heads = [];

    /**
     * Tracks which entries have already been returned (by index per callable).
     * Prevents the same recorded entry from being served twice regardless of
     * whether it was matched by arg-key or by the sequential fallback.
     *
     * @var array<string, array<int, true>>
     */
    private static array $consumed = [];

    /**
     * Pre-computed arg-key → list-of-indices map for the current bucket.
     * Built once on load() so mock() performs O(1) lookups instead of scanning
     * every stored entry and recomputing normalised keys on each call.
     *
     * @var array<string, array<string, list<int>>>
     */
    private static array $keyIndex = [];

    /** Index of the current request (mock: read slot, record: write slot). */
    private static int $requestIndex = 0;

    /**
     * Project config loaded from .cassette/config.json (shared with CassetteHooks.php).
     *
     * @var array<string, mixed>
     */
    private static array $config = [];

    /** Absolute path to record.log for the active run (empty when no run is active). */
    private static string $logPath = '';

    // -------------------------------------------------------------------
    // Setup
    // -------------------------------------------------------------------

    /**
     * Load a cassette by name and set the active mode.
     *
     * @param string $name     Cassette name, maps to cassettes/{name}.json.
     * @param string $mode     Cassette::MODE_RECORD or Cassette::MODE_MOCK.
     * @param string $basePath Directory where cassette JSON files live.
     */
    public static function load(string $name, string $mode, string $basePath = ''): void
    {
        if ($basePath === '') {
            $basePath = __DIR__ . '/cassettes';
        }

        if (!in_array($mode, [self::MODE_RECORD, self::MODE_MOCK], true)) {
            throw new \InvalidArgumentException("Unknown cassette mode: $mode");
        }

        $runDir = rtrim($basePath, '/') . '/' . $name;

        self::$mode         = $mode;
        self::$cassettePath = $runDir . '/data.json';
        self::$pointerPath  = $runDir . '/data.pointer';
        self::$bucketDir    = $runDir . '/buckets';
        self::$logPath      = $runDir . '/record.log';
        self::$current      = [];
        self::$heads        = [];
        self::$consumed     = [];
        self::$keyIndex     = [];
        self::$requestIndex = 0;
        self::$config       = [];

        if ($mode === self::MODE_RECORD) {
            // Atomically claim the next recording slot using a per-run counter file
            // protected by an exclusive flock.  Without this, concurrent PHP-FPM workers
            // all see the same count of existing buckets and collide on the same index,
            // causing later workers to overwrite earlier workers' buckets.
            if (!is_dir($runDir)) {
                mkdir($runDir, 0775, true);
            }
            $counterPath = $runDir . '/data.counter';
            $counterFh   = fopen($counterPath, 'c+');
            if ($counterFh !== false) {
                flock($counterFh, LOCK_EX);
                $stored = trim((string) fread($counterFh, 32));
                // Use the higher of the stored counter and the highest existing bucket
                // index so the engine recovers gracefully from stale counter files after
                // a manual deletion while buckets/ is still populated.
                $counter            = max((int) $stored, self::highestBucketIndex() + 1);
                self::$requestIndex = $counter;
                rewind($counterFh);
                ftruncate($counterFh, 0);
                fwrite($counterFh, (string) ($counter + 1));
                fflush($counterFh);
                flock($counterFh, LOCK_UN);
                fclose($counterFh);
            }

            // Reset the pointer file so the next mock run starts at index 0.
            if (file_exists(self::$pointerPath)) {
                unlink(self::$pointerPath);
            }

            // When starting a brand-new recording run (no existing buckets), also
            // clear the HTTP log and record log so stale entries from a previous
            // run do not carry over.
            if (self::$requestIndex === 0) {
                $httpLogPath = $runDir . '/http.json';
                if (file_exists($httpLogPath)) {
                    unlink($httpLogPath);
                }
                if (file_exists(self::$logPath)) {
                    unlink(self::$logPath);
                }
            }
        }

        if ($mode === self::MODE_MOCK) {
            // Read the current request index from the pointer file.
            if (file_exists(self::$pointerPath)) {
                $pointer            = json_decode((string) file_get_contents(self::$pointerPath), true) ?? [];
                self::$requestIndex = (int) ($pointer['_request_index'] ?? 0);
            }

            // Load ONLY the bucket for the current request index.  This replaces the
            // previous behaviour of reading and decoding the complete data.json on
            // every mock request — an O(N) cost that grew with the recording size.
            self::$current = self::loadBucket(self::$requestIndex);

            // Pre-compute the arg-key → indices map so mock() can do O(1) lookups
            // instead of scanning the entire bucket and recomputing normalised
            // keys on every intercepted call.
            self::$keyIndex = [];
            foreach (self::$current as $callable => $entries) {
                foreach ($entries as $i => $entry) {
                    $key = self::normalizeArgKey($entry['args']);
                    if ($key !== null) {
                        self::$keyIndex[$callable][$key][] = $i;
                    }
                }
            }
        }
    }

    /**
     * Highest existing bucket index in buckets/ plus any bucket stored in a
     * legacy data.json.  Returns -1 when no buckets exist yet.
     */
    private static function highestBucketIndex(): int
    {
        $max = -1;

        if (is_dir(self::$bucketDir)) {
            foreach (glob(self::$bucketDir . '/*.json.gz') ?: [] as $bucketFile) {
                $idx = (int) basename($bucketFile, '.json.gz');
                if ($idx > $max) {
                    $max = $idx;
                }
            }
        }

        if ($max < 0 && file_exists(self::$cassettePath)) {
            // Legacy single-file recording — bucket count equals array length.
            $raw          = (string) file_get_contents(self::$cassettePath);
            $decompressed = @gzdecode($raw);
            $decoded      = json_decode($decompressed !== false ? $decompressed : $raw, true);
            if (is_array($decoded)) {
                $max = empty($decoded) ? -1 : (int) max(array_keys($decoded));
            }
        }

        return $max;
    }

    /**
     * Load a single bucket from disk.  Prefers the per-bucket format introduced
     * for performance; transparently falls back to extracting the bucket from
     * a legacy data.json written by older cassette versions so existing
     * recordings still replay without re-recording.
     *
     * @return array<string, list<array{args: array, return: string}>>
     */
    private static function loadBucket(int $index): array
    {
        $bucketFile = self::$bucketDir . '/' . $index . '.json.gz';

        if (is_file($bucketFile)) {
            $raw          = (string) file_get_contents($bucketFile);
            $decompressed = @gzdecode($raw);
            $decoded      = json_decode($decompressed !== false ? $decompressed : $raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (!file_exists(self::$cassettePath)) {
            return [];
        }

        $raw          = (string) file_get_contents(self::$cassettePath);
        $decompressed = @gzdecode($raw);
        $decoded      = json_decode($decompressed !== false ? $decompressed : $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded[$index] ?? [];
    }

    /**
     * Advance the request index pointer after each mock request so the
     * next request in the sequence uses its own isolated bucket.
     * Deleting the .pointer file restarts the sequence from request 0.
     */
    public static function savePointer(): void
    {
        if (self::$mode !== self::MODE_MOCK || self::$pointerPath === '') {
            return;
        }

        file_put_contents(
            self::$pointerPath,
            json_encode(['_request_index' => self::$requestIndex + 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Persist the current request bucket to disk (called via shutdown function).
     *
     * Writes a single per-bucket file: buckets/{index}.json.gz.  This avoids
     * the O(N²) read-modify-write cycle of the previous format that decoded
     * and re-compressed the full data.json on every request.  Concurrent
     * PHP-FPM workers are naturally safe because the request index is claimed
     * atomically in load() — each worker writes to a unique file.
     *
     * Uses gzip level 1 instead of 6: roughly three times faster for test
     * data with only a ~10% larger file size.
     */
    public static function save(): void
    {
        if (self::$mode !== self::MODE_RECORD) {
            return;
        }

        if (!is_dir(self::$bucketDir)) {
            mkdir(self::$bucketDir, 0775, true);
        }

        $bucketFile = self::$bucketDir . '/' . self::$requestIndex . '.json.gz';

        file_put_contents(
            $bucketFile,
            gzencode(
                (string) json_encode(self::$current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                1
            )
        );

        $callableCount = array_sum(array_map('count', self::$current));
        self::log('saved bucket #' . self::$requestIndex . " — $callableCount intercepted call(s)");
    }

    // -------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------

    /**
     * Append a timestamped line to {run}/record.log.
     * Silent no-op when no run is active (logPath empty).
     */
    public static function log(string $message): void
    {
        if (self::$logPath === '') {
            return;
        }
        $dir = dirname(self::$logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents(
            self::$logPath,
            '[' . date('H:i:s') . '] ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    // -------------------------------------------------------------------
    // Public API (called from hook closures)
    // -------------------------------------------------------------------

    /**
     * Record a completed external call into the current request bucket.
     *
     * Prefer recordSerialized() from hot paths — the hook has already paid the
     * cost of serializing the return value (to force fresh non-interned string
     * allocations) and can pass that string straight through, avoiding a
     * redundant second serialize() here.
     *
     * @param string $callable  e.g. "__::curl" or "db_fetch_row"
     * @param array  $args      Positional arguments passed to the callable.
     * @param mixed  $return    Actual return value (will be serialized).
     */
    public static function record(string $callable, array $args, mixed $return): void
    {
        self::recordSerialized($callable, $args, self::serializeReturn($return));
    }

    /**
     * Record a call whose return value has already been PHP-serialized.
     *
     * Halves the serialize cost in the record hot path: the hooks serialize
     * once to produce a fresh object graph (via unserialize(serialize(...))
     * — required to work around uopz's interned-string-pool corruption) and
     * pass that same string directly as the tape entry.
     *
     * @param string $callable           e.g. "__::curl" or "db_fetch_row"
     * @param array  $args               Positional arguments passed to the callable.
     * @param string $serializedReturn   Already-serialized return value.
     */
    public static function recordSerialized(string $callable, array $args, string $serializedReturn): void
    {
        self::$current[$callable][] = [
            'args'   => self::serializeArgs($args),
            'return' => $serializedReturn
        ];
    }

    /**
     * Consume an entry for this callable from the current request bucket.
     *
     * Matching strategy (two-tier):
     *
     *   1. Arg-key match — normalise args[0] (SQL query or URL) and find the
     *      first unconsumed recorded entry with the same normalised key.  This
     *      makes the engine resilient to ordering shifts caused by conditional
     *      calls that were recorded but are skipped during replay (e.g. a query
     *      behind an auth gate).
     *
     *   2. Sequential fallback — when no key match is found a warning is logged
     *      and the next unconsumed entry is returned in recording order.  This
     *      preserves backward-compatible behaviour for callables whose first
     *      argument is not a stable string (closures, arrays, empty args).
     *
     * Once an entry is consumed it is never returned again regardless of which
     * path matched it.
     *
     * @param string $callable  e.g. "__::curl" or "db_fetch_row"
     * @param array  $args      Arguments the hook was called with.
     * @return mixed Deserialized return value from the cassette, or null if exhausted.
     */
    public static function mock(string $callable, array $args): mixed
    {
        $entries = self::$current[$callable] ?? [];
        $liveKey = self::normalizeArgKey($args);

        // --- Tier 1: arg-key match ----------------------------------------
        // Use the pre-computed key index for O(1) lookup instead of walking
        // every entry and re-normalising its arg key on each call.  When
        // several entries share the same key they are served in recording
        // order (sequential within the matching group).
        if ($liveKey !== null) {
            $indicesForKey = self::$keyIndex[$callable][$liveKey] ?? [];
            foreach ($indicesForKey as $i) {
                if (isset(self::$consumed[$callable][$i])) {
                    continue;
                }
                self::$consumed[$callable][$i] = true;
                return self::deserializeReturn($entries[$i]['return']);
            }

            // No matching entry found — log and fall through to sequential.
            error_log(
                "Cassette WARNING: no arg-key match for '$callable' " .
                    "key='$liveKey' (bucket " . self::$requestIndex . ') ' .
                    '— falling back to sequential head.'
            );
        }

        // --- Tier 2: sequential fallback ------------------------------------
        // Consume the next unconsumed entry in recording order.
        $head = self::$heads[$callable] ?? 0;
        while ($head < count($entries) && isset(self::$consumed[$callable][$head])) {
            $head++;
        }

        if ($head >= count($entries)) {
            error_log(
                "Cassette WARNING: bucket exhausted for '$callable' at index $head " .
                    '(request #' . ($_SERVER['REQUEST_URI'] ?? 'CLI') . ' / bucket ' .
                    self::$requestIndex .
                    ') — returning null. ' .
                    'Re-record the cassette if this causes unexpected behaviour.'
            );
            self::$heads[$callable] = $head;
            return null;
        }

        self::$consumed[$callable][$head] = true;
        self::$heads[$callable] = $head + 1;

        return self::deserializeReturn($entries[$head]['return']);
    }

    // -------------------------------------------------------------------
    // State inspection
    // -------------------------------------------------------------------

    /** Return the current mode (empty string when inactive). */
    public static function getMode(): string
    {
        return self::$mode;
    }

    /**
     * Cancel the in-progress record/mock cycle without writing anything to disk.
     *
     * Called before the interstitial page exits so no empty bucket is written
     * and the request index for the first real browser request stays at 0.
     */
    public static function abort(): void
    {
        // Roll back the counter so the claimed slot is returned and the next real
        // request can reuse it.  Without this, the interstitial redirect would
        // waste index 0, causing every subsequent bucket to be off-by-one relative
        // to the http.json request log (which does not record the interstitial).
        $counterPath = dirname(self::$cassettePath) . '/data.counter';
        if (self::$cassettePath !== '' && is_file($counterPath)) {
            $fh = fopen($counterPath, 'c+');
            if ($fh !== false) {
                flock($fh, LOCK_EX);
                $stored = (int) trim((string) fread($fh, 32));
                if ($stored > 0) {
                    rewind($fh);
                    ftruncate($fh, 0);
                    fwrite($fh, (string) ($stored - 1));
                    fflush($fh);
                }
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }
        self::$mode = '';
    }

    /** Return true when the cassette is active (either mode). */
    public static function isActive(): bool
    {
        return self::$mode !== '';
    }

    /**
     * Store the project config so CassetteHooks.php can read hook definitions.
     *
     * @param array<string, mixed> $config Decoded .cassette/config.json.
     */
    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    /**
     * Return the stored project config.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return self::$config;
    }

    /** Return the current request bucket (for debugging). */
    public static function getTape(): array
    {
        return self::$current;
    }

    // -------------------------------------------------------------------
    // Serialization helpers
    // -------------------------------------------------------------------

    /**
     * Derive a normalised lookup key from the first element of an argument list.
     *
     * For DB functions args[0] is the SQL query string.  Collapsing whitespace
     * and lower-casing produces a stable key that is unaffected by indentation
     * differences between recording and replay call sites.
     *
     * For curl calls args[0] is the URL string — same normalisation applies.
     *
     * Returns null when no stable string key can be derived (empty, non-string,
     * object, array), which tells mock() to skip key matching entirely.
     *
     * @param array $args Raw argument list (live call args or cassette-stored args).
     */
    private static function normalizeArgKey(array $args): ?string
    {
        $first = $args[0] ?? null;
        if (!is_string($first) || trim($first) === '') {
            return null;
        }
        return strtolower((string) preg_replace('/\s+/', ' ', trim($first)));
    }

    /**
     * Normalize arguments to JSON-safe values.
     *
     * Closures and resources are replaced with placeholder strings.
     */
    private static function serializeArgs(array $args): array
    {
        return array_map(static function (mixed $arg): mixed {
            if ($arg instanceof \Closure) {
                return '__closure__';
            }
            if (is_resource($arg)) {
                return '__resource__';
            }
            if (is_object($arg)) {
                return (array) $arg;
            }
            return $arg;
        }, $args);
    }

    /**
     * Serialize a return value to a PHP serialized string.
     *
     * serialize() preserves the complete structure: arrays stay arrays,
     * stdClass stays stdClass, named classes stay named classes.
     */
    private static function serializeReturn(mixed $return): string
    {
        return serialize($return);
    }

    /**
     * Reconstruct the original return value from a PHP serialized string.
     */
    private static function deserializeReturn(mixed $raw): mixed
    {
        return unserialize((string) $raw);
    }

    /**
     * Strip null bytes from all string keys in arrays and stdClass objects.
     *
     * uopz can corrupt PHP interned strings when hooked closures execute in
     * a substitute function scope — column names returned by PDO (e.g. "password")
     * get extra null bytes and garbage memory appended to their key strings.
     * Stripping everything from the first null byte onwards restores the
     * original key name so attribute access works correctly in record mode.
     */
    public static function sanitizeResult(mixed $value): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                $cleanKey = is_string($k)
                    ? (strstr($k, "\0", before_needle: true) ?: $k)
                    : $k;
                $clean[$cleanKey] = self::sanitizeResult($v);
            }
            return $clean;
        }

        if ($value instanceof \stdClass) {
            $clean = new \stdClass();
            foreach ((array) $value as $k => $v) {
                $cleanKey = strstr($k, "\0", before_needle: true) ?: $k;
                $clean->$cleanKey = self::sanitizeResult($v);
            }
            return $clean;
        }

        return $value;
    }
}
