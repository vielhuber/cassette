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

    /**
     * All recorded request buckets: list<array<string, list<array{args:array,return:string}>>>.
     *
     * @var list<array<string, list<array{args: array, return: string}>>>
     */
    private static array $allRequests = [];

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

    /** Index of the current request inside $allRequests (mock: read, record: write). */
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

        self::$mode = $mode;
        self::$cassettePath = rtrim($basePath, '/') . '/' . $name . '/data.json';
        self::$pointerPath = rtrim($basePath, '/') . '/' . $name . '/data.pointer';
        self::$logPath     = rtrim($basePath, '/') . '/' . $name . '/record.log';
        self::$allRequests = [];
        self::$current = [];
        self::$heads = [];
        self::$consumed = [];
        self::$requestIndex = 0;
        self::$config = [];

        if ($mode === self::MODE_RECORD) {
            // Load existing buckets so subsequent requests are appended correctly.
            if (file_exists(self::$cassettePath)) {
                $raw = (string) file_get_contents(self::$cassettePath);
                // Support gzip-compressed files (written by save()) as well as
                // legacy plain-text files recorded before compression was added.
                $decompressed = @gzdecode($raw);
                $decoded = json_decode($decompressed !== false ? $decompressed : $raw, true);
                // Normalize to a dense list so every bucket is addressable by its
                // integer index.  Sparse-encoded JSON objects ({"1":…}) are produced
                // when index 0 is missing; convert them instead of discarding.
                if (is_array($decoded)) {
                    if (!array_is_list($decoded)) {
                        $maxIdx     = empty($decoded) ? -1 : (int) max(array_keys($decoded));
                        $normalized = array_fill(0, $maxIdx + 1, null);
                        foreach ($decoded as $i => $bucket) {
                            $normalized[(int) $i] = $bucket;
                        }
                        $decoded = $normalized;
                    }
                    self::$allRequests = $decoded;
                }
            }

            // Atomically claim the next recording slot using a per-run counter file
            // protected by an exclusive flock.  Without this, concurrent PHP-FPM workers
            // all see the same count of existing buckets and collide on the same index,
            // causing later workers to overwrite earlier workers' buckets during recording.
            $counterPath = dirname(self::$cassettePath) . '/data.counter';
            if (!is_dir(dirname(self::$cassettePath))) {
                mkdir(dirname(self::$cassettePath), 0775, true);
            }
            $counterFh   = fopen($counterPath, 'c+');
            if ($counterFh !== false) {
                flock($counterFh, LOCK_EX);
                $stored  = trim((string) fread($counterFh, 32));
                // Use the higher of the stored counter and the actual bucket count so
                // the engine recovers gracefully from stale counter files after e.g.
                // a manual deletion of data.counter while data.json still exists.
                $counter = max((int) $stored, count(self::$allRequests));
                self::$requestIndex = $counter;
                rewind($counterFh);
                ftruncate($counterFh, 0);
                fwrite($counterFh, (string) ($counter + 1));
                fflush($counterFh);
                flock($counterFh, LOCK_UN);
                fclose($counterFh);
            }
            self::$current = [];

            // Reset the pointer file so the next mock run starts at index 0.
            if (file_exists(self::$pointerPath)) {
                unlink(self::$pointerPath);
            }

            // When starting a brand-new recording run (no existing data buckets),
            // also clear the HTTP log and record log so stale entries from a
            // previous run do not carry over.
            if (self::$requestIndex === 0) {
                $httpLogPath = dirname(self::$cassettePath) . '/http.json';
                if (file_exists($httpLogPath)) {
                    unlink($httpLogPath);
                }
                if (file_exists(self::$logPath)) {
                    unlink(self::$logPath);
                }
            }
        }

        if ($mode === self::MODE_MOCK) {
            if (!file_exists(self::$cassettePath)) {
                // data.json does not exist yet — treat all buckets as empty.
                // Hooks will log warnings and return null for unrecorded calls.
                // Run `cassette record` first to populate data.json.
                self::$allRequests = [];
                self::$current = [];
                return;
            }

            $raw = (string) file_get_contents(self::$cassettePath);
            // Support gzip-compressed files as well as legacy plain-text files.
            $decompressed = @gzdecode($raw);
            $decoded = json_decode($decompressed !== false ? $decompressed : $raw, true);
            // Normalize sparse-encoded JSON objects ({"1":…, "16":…}) to dense lists.
            // Sparse encoding occurs when index 0 is missing (ghost request that claimed
            // a slot but never saved), causing json_encode to produce a JSON object.
            if (is_array($decoded) && !array_is_list($decoded)) {
                $maxIdx     = empty($decoded) ? -1 : (int) max(array_keys($decoded));
                $normalized = array_fill(0, $maxIdx + 1, null);
                foreach ($decoded as $i => $bucket) {
                    $normalized[(int) $i] = $bucket;
                }
                $decoded = $normalized;
            }
            self::$allRequests = $decoded ?? [];

            // Read the current request index from the pointer file.
            if (file_exists(self::$pointerPath)) {
                $pointer = json_decode((string) file_get_contents(self::$pointerPath), true) ?? [];
                self::$requestIndex = (int) ($pointer['_request_index'] ?? 0);
            }

            // Load the bucket for this request and reset per-callable heads.
            self::$current = self::$allRequests[self::$requestIndex] ?? [];
            self::$heads = [];
            self::$consumed = [];
        }
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
     * Persist recorded buckets to disk (called via shutdown function after
     * the request completes).
     */
    public static function save(): void
    {
        if (self::$mode !== self::MODE_RECORD) {
            return;
        }

        $dir = dirname(self::$cassettePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Hold an exclusive lock for the entire read-modify-write cycle so that
        // concurrent PHP-FPM workers do not overwrite each other's buckets.
        $lockPath = $dir . '/data.lock';
        $lockFh   = fopen($lockPath, 'c+');
        if ($lockFh !== false) {
            flock($lockFh, LOCK_EX);
        }

        // Re-read the latest data.json while holding the lock so we merge our
        // bucket into the current state rather than stomping on buckets that
        // other workers have already written.
        $allRequests = [];
        if (file_exists(self::$cassettePath)) {
            $raw          = (string) file_get_contents(self::$cassettePath);
            $decompressed = @gzdecode($raw);
            $decoded      = json_decode($decompressed !== false ? $decompressed : $raw, true);
            if (is_array($decoded)) {
                if (!array_is_list($decoded)) {
                    // Normalize sparse-encoded JSON objects produced by a previous save
                    // where index 0 was missing (ghost request that claimed a slot but
                    // never wrote any intercepted calls).
                    $maxIdx     = empty($decoded) ? -1 : (int) max(array_keys($decoded));
                    $normalized = array_fill(0, $maxIdx + 1, null);
                    foreach ($decoded as $i => $bucket) {
                        $normalized[(int) $i] = $bucket;
                    }
                    $decoded = $normalized;
                }
                $allRequests = $decoded;
            }
        }

        // Attach this request's bucket at the pre-claimed slot.
        $allRequests[self::$requestIndex] = self::$current;

        // PHP encodes sparse integer-keyed arrays (e.g. [16 => data]) as JSON
        // objects ({"16":…}) instead of JSON arrays ([…]).  A JSON object then
        // fails the array_is_list() check on re-read, causing every subsequent
        // save() to discard all previously written buckets.  Fill any index gaps
        // with null so the array is always contiguous and round-trips as a JSON
        // array.
        $maxIdx = empty($allRequests) ? 0 : (int) max(array_keys($allRequests));
        $dense  = array_fill(0, $maxIdx + 1, null);
        foreach ($allRequests as $i => $bucket) {
            $dense[(int) $i] = $bucket;
        }

        file_put_contents(
            self::$cassettePath,
            gzencode(
                (string) json_encode($dense, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                6
            )
        );

        if ($lockFh !== false) {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }

        $callableCount = array_sum(array_map('count', self::$current));
        self::log('saved bucket #' . self::$requestIndex . " — $callableCount intercepted call(s), total " . count($allRequests) . ' bucket(s)');
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
     * @param string $callable  e.g. "__::curl" or "db_fetch_row"
     * @param array  $args      Positional arguments passed to the callable.
     * @param mixed  $return    Actual return value (will be serialized).
     */
    public static function record(string $callable, array $args, mixed $return): void
    {
        self::$current[$callable][] = [
            'args' => self::serializeArgs($args),
            'return' => self::serializeReturn($return)
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
        // Find the first unconsumed entry whose normalised key equals the
        // caller's key.  When several entries share the same key they are
        // served in recording order (sequential within the matching group).
        if ($liveKey !== null) {
            foreach ($entries as $i => $entry) {
                if (isset(self::$consumed[$callable][$i])) {
                    continue;
                }
                if (self::normalizeArgKey($entry['args']) === $liveKey) {
                    self::$consumed[$callable][$i] = true;
                    return self::deserializeReturn($entry['return']);
                }
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
