#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cassette HTTP replay — regression test runner.
 *
 * Reads the recorded HTTP exchanges from {cassette}_http.json, replays each
 * request against a running server (which must have mock mode active via
 * config.json), then compares the actual responses to the recorded ones.
 *
 * Usage:
 *   php /var/www/project/vendor/vielhuber/cassette/src/CassetteReplay.php [cassette] [base_url]
 *
 * The base_url is optional — when omitted, the URL recorded during the
 * record run is used automatically (scheme + host from the captured request).
 * Override only when replaying against a different server:
 *
 *   php /var/www/project/vendor/vielhuber/cassette/src/CassetteReplay.php run_001
 *   php /var/www/project/vendor/vielhuber/cassette/src/CassetteReplay.php run_001 https://staging.example.com
 *
 * Prerequisites:
 *   1. config.json must have "mode": "mock" so the server uses mocked DB/curl.
 *   2. The .pointer file will be reset automatically before the run starts.
 *   3. Auth/session is handled via a cookie jar that persists across requests.
 *
 * Exit code: 0 = all passed, 1 = one or more failures.
 */

// Allow exit()/die() to actually terminate the process even when the uopz extension
// is loaded (it disables exit by default to support testing code that calls exit).
if (function_exists('uopz_allow_exit')) {
    uopz_allow_exit(true);
}

$cassetteName = $argv[1] ?? 'run_001';
$baseUrlOverride = isset($argv[2]) ? rtrim($argv[2], '/') : null;

// Honour CASSETTE_ROOT env var (set by the CLI binary) so path-repository symlink installs
// work correctly. Falls back to the standard Composer layout.
$__cassetteRoot = (string) (getenv('CASSETTE_ROOT') ?: '');
$cassettesDir = ($__cassetteRoot !== '' ? rtrim($__cassetteRoot, '/') : dirname(__DIR__, 4)) . '/.cassette/runs';
unset($__cassetteRoot);
$httpLogPath = $cassettesDir . '/' . $cassetteName . '/http.json';
$pointerPath = $cassettesDir . '/' . $cassetteName . '/data.pointer';

// -------------------------------------------------------------------
// Load the HTTP log
// -------------------------------------------------------------------

if (!file_exists($httpLogPath)) {
    fwrite(STDERR, "HTTP log not found: $httpLogPath\n");
    fwrite(STDERR, "Run record mode first to capture request/response pairs.\n");
    exit(1);
}

$raw = file_get_contents($httpLogPath);

if ($raw === false) {
    fwrite(STDERR, "Could not read HTTP log: $httpLogPath\n");
    exit(1);
}

// Support gzip-compressed files as well as legacy plain-text files.
$decompressed = @gzdecode($raw);
$log = json_decode($decompressed !== false ? $decompressed : $raw, true) ?? [];

if (empty($log)) {
    fwrite(STDERR, "No recorded HTTP exchanges in: $httpLogPath\n");
    exit(1);
}

// -------------------------------------------------------------------
// Reset pointer so mock replay starts from request bucket 0
// -------------------------------------------------------------------

// Ensure the run directory exists (it always should at this point, but
// guards against edge cases like a partial deletion).
$runDir = dirname($pointerPath);
if (!is_dir($runDir)) {
    mkdir($runDir, 0775, true);
}

file_put_contents($pointerPath, json_encode(['_request_index' => 0], JSON_PRETTY_PRINT));

// -------------------------------------------------------------------
// Replay
// -------------------------------------------------------------------

// Load ignoreUrls from config.json so entries that were recorded before the
// pattern was added (or slipped through) are silently skipped during replay.
$cassetteConfigPath = dirname($cassettesDir) . '/config.json';
$ignoreUrls = [];
$requestTimeoutSeconds = 30; // default
if (is_file($cassetteConfigPath)) {
    $cassetteConfig = json_decode((string) file_get_contents($cassetteConfigPath), true) ?? [];
    $ignoreUrls = $cassetteConfig['ignoreUrls'] ?? [];
    // screenshot.timeout is in ms; convert to seconds for curl.
    if (isset($cassetteConfig['screenshot']['timeout'])) {
        $requestTimeoutSeconds = (int) ceil($cassetteConfig['screenshot']['timeout'] / 1000);
    }
}

// Filter out ignored entries before replaying so the pointer stays in sync.
$log = array_values(array_filter($log, static function (array $entry) use ($ignoreUrls): bool {
    $uri = $entry['request']['uri'] ?? '/';
    foreach ($ignoreUrls as $pattern) {
        if (str_contains($uri, (string) $pattern)) {
            return false;
        }
    }
    return true;
}));

// Derive default base URL from the first recorded request when no override given.
$defaultBaseUrl = $baseUrlOverride ?? ($log[0]['request']['base_url'] ?? 'http://localhost');

echo 'Replaying ' . count($log) . " request(s)\n\n";

$passed = 0;
$failed = 0;
$cookieJar = tempnam(sys_get_temp_dir(), 'cassette_replay_');

// Directory for per-request diff files (created on demand).
$diffDir = $runDir . '/http';

// Track the last GET response body so CSRF tokens can be extracted for POST requests.
$lastGetBody = '';

foreach ($log as $index => $entry) {
    $request = $entry['request'];
    $recorded = $entry['response'];

    // Each request uses its own recorded base_url unless a global override was given.
    $baseUrl = $baseUrlOverride ?? ($request['base_url'] ?? $defaultBaseUrl);

    // Rebuild the cookie jar from THIS request's recorded cookies only.
    // Each recorded "cookies" snapshot is exactly what the browser would have
    // sent at that point of the original session, so replaying that set
    // verbatim mirrors recording faithfully and avoids state leakage between
    // requests — most notably, transient flash cookies (Set-Cookie deletions
    // that curl's COOKIEJAR persistence does NOT honour reliably across calls)
    // never accumulate from one replay step to the next.
    rebuildCookieJar(
        $request['cookies'] ?? [],
        parse_url($baseUrl, PHP_URL_HOST) ?? 'localhost',
        $cookieJar
    );

    // For POST requests that include a Laravel CSRF token, extract the fresh
    // _token from the last GET response and inject it into the POST data.
    // This prevents HTTP 419 (Page Expired) caused by replaying a stale token.
    if ($request['method'] === 'POST' && isset($request['post']['_token']) && $lastGetBody !== '') {
        if (
            preg_match('/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\']/', $lastGetBody, $m) ||
            preg_match('/<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']_token["\']/', $lastGetBody, $m)
        ) {
            $freshToken = $m[1];
            // Update both the parsed post array and the raw URL-encoded body,
            // since executeRequest() sends whichever is non-empty first.
            $request['post']['_token'] = $freshToken;
            if (($request['body'] ?? '') !== '') {
                $request['body'] = preg_replace(
                    '/(?<=^|&)_token=[^&]*/',
                    '_token=' . urlencode($freshToken),
                    $request['body']
                ) ?? $request['body'];
            }
        }
    }

    $fullUrl = $baseUrl . $request['uri'];
    $displayUrl = mb_strlen($fullUrl) > 100 ? mb_substr($fullUrl, 0, 100) . '…' : $fullUrl;
    $label = sprintf('#%d  %-4s  %s', $index + 1, $request['method'], $displayUrl);

    // Point the FPM-side mock at the bucket recorded for THIS request. With
    // concurrent requests during recording, bucket-claim order can diverge
    // from http.json append order, so http.json[$index] may correspond to a
    // different bucket index than $index. The recording embeds 'bucket' in
    // each entry; fall back to $index for backwards compatibility.
    $bucketIndex = $entry['bucket'] ?? $index;
    file_put_contents($pointerPath, json_encode(['_request_index' => $bucketIndex], JSON_PRETTY_PRINT));

    [$actualStatus, $actualBody, $curlError] = executeRequest($request, $baseUrl, $cookieJar, $requestTimeoutSeconds);

    if ($request['method'] === 'GET') {
        $lastGetBody = $actualBody;
    }

    // Skip body diff for entries recorded with an empty body — the recording
    // did not capture a response (e.g. streamed/binary output). The request
    // is still sent to advance the mock pointer correctly.
    $recordedBody = (string) ($recorded['body'] ?? '');
    if ($recordedBody === '') {
        echo colorize("\033[33mSKIP\033[0m") . "  $label  (empty recorded body)\n";
        $passed++;
        continue;
    }

    $statusOk = $actualStatus === (int) ($recorded['status'] ?? 200);
    $bodyOk = normalizeBody($actualBody) === normalizeBody($recordedBody);

    if ($statusOk && $bodyOk) {
        echo colorize("\033[32mPASS\033[0m") . "  $label\n";
        $passed++;
        continue;
    }

    echo colorize("\033[31mFAIL\033[0m") . "  $label\n";
    $failed++;

    $diffLines = [];

    if (!$statusOk) {
        $diffLines[] = "Status: expected {$recorded['status']}, got $actualStatus";
        if ($actualStatus === 0 && $curlError !== '') {
            $diffLines[] = "curl error: $curlError";
        }
    }

    if (!$bodyOk) {
        $diff = computeDiff(normalizeBody($recordedBody), normalizeBody($actualBody));
        $diffLines = array_merge($diffLines, $diff);
    }

    // Write the full diff to .cassette/runs/{name}/http/{step}.diff and show path.
    if (!empty($diffLines)) {
        if (!is_dir($diffDir)) {
            mkdir($diffDir, 0775, true);
        }

        $diffFile = sprintf('%s/%03d.diff', $diffDir, $index + 1);
        $header = sprintf(
            "Request #%d  %s  %s%s\n%s\n\n",
            $index + 1,
            $request['method'],
            $baseUrl,
            $request['uri'],
            str_repeat('-', 60)
        );
        file_put_contents($diffFile, $header . implode("\n", $diffLines) . "\n");
        echo "       Diff    : $diffFile\n";

        // Save the raw actual body so 'php cli accept' can update http.json without re-requesting.
        $actualFile = sprintf('%s/%03d.actual', $diffDir, $index + 1);
        file_put_contents($actualFile, $actualBody);
    }
}

@unlink($cookieJar);

echo "\n";
echo colorize("\033[32m") . "$passed passed\033[0m, ";
echo colorize("\033[31m") . "$failed failed\033[0m ";
echo 'out of ' . count($log) . " request(s).\n";

exit($failed > 0 ? 1 : 0);

// -------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------

/**
 * Replace the cookie jar file with EXACTLY the recorded cookie set.
 *
 * Each recorded $request['cookies'] snapshot is the literal Cookie header the
 * original browser sent at that point in the session — replaying it verbatim
 * is the closest possible mirror of recording. Unlike a merge approach this
 * also drops any cookies that the server set during the previous replay step
 * (e.g. transient flash messages) but that the original session no longer
 * carried by the next request — curl's own Set-Cookie deletion handling does
 * not always honour `Max-Age=0` reliably when the jar was pre-populated, and
 * leftover cookies leak into later requests producing spurious diffs.
 *
 * @param array<string,string> $cookies  Name → value pairs from the recorded snapshot.
 * @param string               $host     Domain to bind the cookies to.
 * @param string               $jarPath  Path to the Netscape cookie jar file.
 */
function rebuildCookieJar(array $cookies, string $host, string $jarPath): void
{
    // Netscape cookie format requires the domain to start with a dot so that
    // curl actually sends the cookie with the request. Without the leading dot
    // curl silently skips the cookie when matching against the request host.
    $dotHost = str_starts_with($host, '.') ? $host : '.' . $host;

    $lines = ['# Netscape HTTP Cookie File'];
    foreach ($cookies as $name => $value) {
        $lines[] = implode("\t", [
            $dotHost,        // domain (leading dot = send for this host and subdomains)
            'TRUE',          // include subdomains (required when domain starts with dot)
            '/',             // path
            'FALSE',         // secure
            '0',             // expiry (session)
            $name,
            $value,
        ]);
    }

    file_put_contents($jarPath, implode("\n", $lines) . "\n");
}

/**
 * Read a single cookie value out of the Netscape cookie jar.
 *
 * Returns the URL-decoded value (which is what the browser would expose to JS
 * via document.cookie / what an axios `xsrfHeaderName` interceptor sends as a
 * header). Returns null when no cookie of that name is in the jar.
 */
function extractCookieFromJar(string $jarPath, string $name): ?string
{
    if (!file_exists($jarPath)) {
        return null;
    }

    foreach (explode("\n", (string) file_get_contents($jarPath)) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode("\t", $line);
        if (count($parts) >= 7 && $parts[5] === $name) {
            return urldecode($parts[6]);
        }
    }

    return null;
}

/**
 * Execute a single HTTP request and return [statusCode, responseBody].
 *
 * @param  array  $request   Recorded request snapshot from _http.json.
 * @param  string $baseUrl   e.g. "http://localhost"
 * @param  string $cookieJar Path to a Netscape cookie jar file for session persistence.
 * @return array{int, string}
 */
function executeRequest(array $request, string $baseUrl, string $cookieJar, int $timeoutSeconds = 30): array
{
    $ch = curl_init($baseUrl . $request['uri']);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    if ($request['method'] === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $rawBody = $request['body'] ?? '';

        if ($rawBody !== '') {
            // Raw body recorded (e.g. JSON, plain text) — send as-is and forward
            // the original Content-Type so the server parses it correctly.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
        } elseif (!empty($request['post'])) {
            // Form was multipart/form-data in the browser but php://input was empty
            // (PHP parsed it into $_POST directly). Replay as URL-encoded instead —
            // DO NOT forward the recorded Content-Type header in this case, or PHP
            // will try to parse the URL-encoded body as multipart and miss $_POST.
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($request['post']));
        }
    }

    // Forward safe request headers only (avoid host/connection/encoding issues).
    // Skip Content-Type when we re-encoded a multipart form as URL-encoded above,
    // so that PHP's default application/x-www-form-urlencoded parsing kicks in.
    $isReencodedForm = $request['method'] === 'POST' && ($request['body'] ?? '') === '' && !empty($request['post']);

    // x-xsrf-token / x-csrf-token / referer are critical for Laravel Sanctum
    // and CSRF middleware on /api/* routes — without them the server rejects
    // authenticated XHR requests with 401/419 even when cookies are correct.
    $safe = ['accept', 'accept-language', 'x-requested-with', 'x-csrf-token', 'referer'];

    if (!$isReencodedForm) {
        $safe[] = 'content-type';
    }

    // Pull the current XSRF-TOKEN out of the cookie jar (server may have rotated
    // it during replay) and use that as the X-XSRF-TOKEN header. The cookie
    // value is URL-encoded; the header carries the decoded value.
    $freshXsrf = extractCookieFromJar($cookieJar, 'XSRF-TOKEN');

    $forwardHeaders = [];
    $sentXsrf = false;

    foreach ($request['headers'] ?? [] as $name => $value) {
        $lower = strtolower($name);
        if ($lower === 'x-xsrf-token') {
            $forwardHeaders[] = 'X-XSRF-TOKEN: ' . ($freshXsrf ?? $value);
            $sentXsrf = true;
            continue;
        }
        if (in_array($lower, $safe, true)) {
            $forwardHeaders[] = "$name: $value";
        }
    }

    // The recording may have been captured without an explicit X-XSRF-TOKEN
    // header (axios reads the cookie at runtime) — always send it when the
    // jar carries one, so stateful Sanctum requests authenticate.
    if (!$sentXsrf && $freshXsrf !== null) {
        $forwardHeaders[] = 'X-XSRF-TOKEN: ' . $freshXsrf;
    }

    if (!empty($forwardHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
    }

    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    return [$status, $body, $curlError];
}

/**
 * Normalize dynamic content before comparing bodies.
 *
 * Strips values that legitimately differ between record and replay:
 * timestamps, WordPress nonces, Unix timestamps in JS/JSON contexts.
 * Add more patterns here as needed.
 */
function normalizeBody(string $body): string
{
    // WordPress nonce values (10-char hex strings in hidden fields, JSON, and URL params).
    $body = preg_replace('/value="[0-9a-f]{10}"/', 'value="__NONCE__"', $body) ?? $body;
    $body = preg_replace('/"nonce"\s*:\s*"[0-9a-f]{10}"/', '"nonce":"__NONCE__"', $body) ?? $body;
    $body = preg_replace('/_wpnonce=[0-9a-f]{10}/', '_wpnonce=__NONCE__', $body) ?? $body;

    // Laravel CSRF token (40-char random string in hidden input named "_token").
    $body = preg_replace('/(<input[^>]+name=["\']_token["\'][^>]+value=["\'])[^"\']*(["\'])/', '$1__TOKEN__$2', $body) ?? $body;
    $body = preg_replace('/(<input[^>]+value=["\'])[^"\']*(["\'][^>]+name=["\']_token["\'])/', '$1__TOKEN__$2', $body) ?? $body;

    // ISO 8601 / SQL timestamps — with or without seconds
    // (2026-03-24T17:10:41, 2026-03-24 17:10:41, 2026-03-31T11:10).
    $body = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/', '__TIMESTAMP__', $body) ?? $body;

    // German date/time format (e.g. "26.03.2026 13:42" in page titles / "Stand" values).
    $body = preg_replace('/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/', '__TIMESTAMP__', $body) ?? $body;

    // ISO 8601 date only (2026-03-29)
    $body = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/', '__DATE__', $body) ?? $body;

    // German date only (29.03.2026)
    $body = preg_replace('/\b\d{2}\.\d{2}\.\d{4}\b/', '__DATE__', $body) ?? $body;

    // Time only (12:34 or 12:34:56)
    $body = preg_replace('/\b\d{2}:\d{2}(:\d{2})?\b/', '__TIME__', $body) ?? $body;

    // Random cache-buster query params on static file URLs (e.g. ?rand=132 or &rand=132 or &amp;rand=132).
    $body = preg_replace('/(?:\?|&amp;|&)rand=\d+/', '&rand=__RAND__', $body) ?? $body;

    // Google Maps embed URL: cache-buster timestamp in the "4v" parameter (e.g. !4v1775890090000).
    $body = preg_replace('/!4v\d{13}/', '!4v__MAPS_TS__', $body) ?? $body;

    // Page render timing in footer (e.g. | 6,25s | or | 0.52s |).
    $body = preg_replace('/\|\s*[\d,.]+s\s*\|/', '| __TIME__ |', $body) ?? $body;

    // App version markers in the footer (e.g. "v7.88", "v12.3.1") — bumped on
    // every deploy so we mask them rather than diff against a stale value.
    $body = preg_replace('/\bv\d+(?:\.\d+)+\b/', '__VERSION__', $body) ?? $body;

    // Generic duration values (e.g. "0.464s" in metainfo spans).
    $body = preg_replace('/\b\d+[.,]\d+s\b/', '__DURATION__', $body) ?? $body;

    // Uptime / runtime values in hours (e.g. "214,23h").
    $body = preg_replace('/\b\d+[.,]\d+h\b/', '__UPTIME__', $body) ?? $body;

    // <input type="date"> values change daily (e.g. default = today).
    // Normalise value="YYYY-MM-DD" in any date input regardless of other attributes.
    $body = (string) preg_replace_callback(
        '/<input\b([^>]*)\btype="date"([^>]*)>/i',
        static function (array $m): string {
            $before = (string) preg_replace('/\bvalue="[^"]*"/', 'value="__DATE__"', $m[1]);
            $after  = (string) preg_replace('/\bvalue="[^"]*"/', 'value="__DATE__"', $m[2]);
            return '<input' . $before . 'type="date"' . $after . '>';
        },
        $body
    ) ?? $body;

    return trim($body);
}

/**
 * Compute a unified-style line diff between two strings using LCS.
 *
 * Returns lines prefixed with "- " (expected only) and "+ " (actual only).
 * Unchanged lines are omitted. A context of 3 surrounding lines is shown
 * to help locate each change.
 *
 * @return list<string>
 */
function computeDiff(string $expected, string $actual): array
{
    $expectedLines = explode("\n", $expected);
    $actualLines   = explode("\n", $actual);

    $m = count($expectedLines);
    $n = count($actualLines);

    // Build LCS table (only store two rows to save memory).
    // $dp[$i][$j] = length of LCS for $expectedLines[0..$i-1] vs $actualLines[0..$j-1].
    $prev = array_fill(0, $n + 1, 0);
    $curr = array_fill(0, $n + 1, 0);
    $table = [];
    for ($i = 0; $i <= $m; $i++) {
        for ($j = 0; $j <= $n; $j++) {
            if ($i === 0 || $j === 0) {
                $curr[$j] = 0;
            } elseif ($expectedLines[$i - 1] === $actualLines[$j - 1]) {
                $curr[$j] = $prev[$j - 1] + 1;
            } else {
                $curr[$j] = max($prev[$j], $curr[$j - 1]);
            }
        }
        $table[$i] = $curr;
        $prev = $curr;
    }

    // Back-track to produce edit operations:
    //   'K' = keep (both), 'D' = delete (expected only), 'A' = add (actual only).
    $edits = [];
    $i = $m;
    $j = $n;
    while ($i > 0 || $j > 0) {
        if ($i > 0 && $j > 0 && $expectedLines[$i - 1] === $actualLines[$j - 1]) {
            $edits[] = ['K', $expectedLines[$i - 1]];
            $i--;
            $j--;
        } elseif ($j > 0 && ($i === 0 || $table[$i][$j - 1] >= $table[$i - 1][$j])) {
            $edits[] = ['A', $actualLines[$j - 1]];
            $j--;
        } else {
            $edits[] = ['D', $expectedLines[$i - 1]];
            $i--;
        }
    }
    $edits = array_reverse($edits);

    // Render with 3-line context around each changed block.
    $CONTEXT = 3;
    $total = count($edits);
    $showLine = array_fill(0, $total, false);

    for ($idx = 0; $idx < $total; $idx++) {
        if ($edits[$idx][0] !== 'K') {
            for ($c = max(0, $idx - $CONTEXT); $c <= min($total - 1, $idx + $CONTEXT); $c++) {
                $showLine[$c] = true;
            }
        }
    }

    $result = [];
    $lastShown = -1;
    for ($idx = 0; $idx < $total; $idx++) {
        if (!$showLine[$idx]) {
            continue;
        }
        if ($lastShown >= 0 && $idx > $lastShown + 1) {
            $result[] = '  @@ ...';
        }
        [$op, $line] = $edits[$idx];
        if ($op === 'K') {
            $result[] = '    ' . $line;
        } elseif ($op === 'D') {
            $result[] = '-   ' . $line;
        } else {
            $result[] = '+   ' . $line;
        }
        $lastShown = $idx;
    }

    return $result;
}

/**
 * Return ANSI escape codes only when stdout is a TTY.
 */
function colorize(string $ansi): string
{
    return posix_isatty(STDOUT) ? $ansi : '';
}

