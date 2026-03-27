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
 *     {"db_fetch_row": [{"args":[...],"return":"..."},...], "__::curl": [...]},
 *     {"db_fetch_row": [...], "db_query": [...]},
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
        self::$allRequests = [];
        self::$current = [];
        self::$heads = [];
        self::$consumed = [];
        self::$requestIndex = 0;

        if ($mode === self::MODE_RECORD) {
            // Load existing buckets so subsequent requests are appended correctly.
            if (file_exists(self::$cassettePath)) {
                $raw = (string) file_get_contents(self::$cassettePath);
                $decoded = json_decode($raw, true);
                // Support both the new list-of-buckets format and a legacy
                // flat map (old cassette files).  Legacy files are discarded so
                // a clean recording starts from scratch.
                if (is_array($decoded) && array_is_list($decoded)) {
                    self::$allRequests = $decoded;
                }
            }

            // The recording index for this PHP request is the next slot.
            self::$requestIndex = count(self::$allRequests);
            self::$current = [];

            // Reset the pointer file so the next mock run starts at index 0.
            if (file_exists(self::$pointerPath)) {
                unlink(self::$pointerPath);
            }
        }

        if ($mode === self::MODE_MOCK) {
            if (!file_exists(self::$cassettePath)) {
                throw new \RuntimeException('Cassette not found: ' . self::$cassettePath);
            }

            $raw = (string) file_get_contents(self::$cassettePath);
            self::$allRequests = json_decode($raw, true) ?? [];

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

        // Attach the bucket for this request at its slot.
        self::$allRequests[self::$requestIndex] = self::$current;

        $dir = dirname(self::$cassettePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            self::$cassettePath,
            json_encode(self::$allRequests, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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

    /** Return true when the cassette is active (either mode). */
    public static function isActive(): bool
    {
        return self::$mode !== '';
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
}
