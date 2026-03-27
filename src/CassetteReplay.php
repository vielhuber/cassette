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
 *   php /var/www/project/_cassette/src/CassetteReplay.php [cassette] [base_url]
 *
 * The base_url is optional — when omitted, the URL recorded during the
 * record run is used automatically (scheme + host from the captured request).
 * Override only when replaying against a different server:
 *
 *   php /var/www/project/_cassette/src/CassetteReplay.php run_001
 *   php /var/www/project/_cassette/src/CassetteReplay.php run_001 https://staging.example.com
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

$cassettesDir = __DIR__ . '/../.data';
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

$log = json_decode($raw, true) ?? [];

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

// Derive default base URL from the first recorded request when no override given.
$defaultBaseUrl = $baseUrlOverride ?? ($log[0]['request']['base_url'] ?? 'http://localhost');

echo 'Replaying ' . count($log) . " request(s)\n\n";

$passed = 0;
$failed = 0;
$cookieJar = tempnam(sys_get_temp_dir(), 'cassette_replay_');

// Directory for per-request diff files (created on demand).
$diffDir = $runDir . '/http';

// Pre-seed the cookie jar from the first request so initial authentication works.
mergeCookiesIntoJar(
    $log[0]['request']['cookies'] ?? [],
    parse_url($defaultBaseUrl, PHP_URL_HOST) ?? 'localhost',
    $cookieJar
);

foreach ($log as $index => $entry) {
    $request = $entry['request'];
    $recorded = $entry['response'];

    // Each request uses its own recorded base_url unless a global override was given.
    $baseUrl = $baseUrlOverride ?? ($request['base_url'] ?? $defaultBaseUrl);

    // Merge this request's recorded cookies into the jar so any cookies set
    // mid-session (e.g. after a POST) are active for subsequent requests.
    mergeCookiesIntoJar($request['cookies'] ?? [], parse_url($baseUrl, PHP_URL_HOST) ?? 'localhost', $cookieJar);

    $label = sprintf('#%d  %-4s  %s%s', $index + 1, $request['method'], $baseUrl, $request['uri']);

    [$actualStatus, $actualBody] = executeRequest($request, $baseUrl, $cookieJar);

    $statusOk = $actualStatus === (int) ($recorded['status'] ?? 200);
    $bodyOk = normalizeBody($actualBody) === normalizeBody((string) ($recorded['body'] ?? ''));

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
    }

    if (!$bodyOk) {
        $diff = computeDiff(normalizeBody((string) ($recorded['body'] ?? '')), normalizeBody($actualBody));
        $diffLines = array_merge($diffLines, $diff);
    }

    // Write the full diff to .data/{name}/http/{step}.diff and show path.
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
 * Merge recorded cookies into a Netscape-format cookie jar file.
 *
 * Reads the existing jar, overwrites entries with matching name+domain,
 * and appends new ones. This preserves any cookies the server has set
 * via Set-Cookie headers during the replay while also injecting the
 * cookies that were present in the original recorded request.
 *
 * @param array<string,string> $cookies  Name → value pairs from the recorded snapshot.
 * @param string               $host     Domain to bind the cookies to.
 * @param string               $jarPath  Path to the Netscape cookie jar file.
 */
function mergeCookiesIntoJar(array $cookies, string $host, string $jarPath): void
{
    if (empty($cookies)) {
        return;
    }

    // Parse existing jar into a keyed map ["domain|name" => line].
    $existing = [];

    if (file_exists($jarPath)) {
        foreach (explode("\n", file_get_contents($jarPath)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) >= 7) {
                $existing[$parts[0] . '|' . $parts[5]] = $line;
            }
        }
    }

    // Merge: recorded cookies overwrite existing entries for same domain+name.
    // Netscape cookie format requires the domain to start with a dot so that
    // curl actually sends the cookie with the request. Without the leading dot
    // curl silently skips the cookie when matching against the request host.
    $dotHost = str_starts_with($host, '.') ? $host : '.' . $host;

    foreach ($cookies as $name => $value) {
        $existing[$dotHost . '|' . $name] = implode("\t", [
            $dotHost, // domain (leading dot = send for this host and subdomains)
            'TRUE', // include subdomains (required when domain starts with dot)
            '/', // path
            'FALSE', // secure
            '0', // expiry (session)
            $name,
            $value
        ]);
    }

    file_put_contents(
        $jarPath,
        implode("\n", array_merge(['# Netscape HTTP Cookie File'], array_values($existing))) . "\n"
    );
}

/**
 * Execute a single HTTP request and return [statusCode, responseBody].
 *
 * @param  array  $request   Recorded request snapshot from _http.json.
 * @param  string $baseUrl   e.g. "http://localhost"
 * @param  string $cookieJar Path to a Netscape cookie jar file for session persistence.
 * @return array{int, string}
 */
function executeRequest(array $request, string $baseUrl, string $cookieJar): array
{
    $ch = curl_init($baseUrl . $request['uri']);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_TIMEOUT => 30,
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

    $safe = ['accept', 'accept-language', 'x-requested-with'];

    if (!$isReencodedForm) {
        $safe[] = 'content-type';
    }

    $forwardHeaders = [];

    foreach ($request['headers'] ?? [] as $name => $value) {
        if (in_array(strtolower($name), $safe, true)) {
            $forwardHeaders[] = "$name: $value";
        }
    }

    if (!empty($forwardHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $forwardHeaders);
    }

    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [$status, $body];
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

    // ISO 8601 / SQL timestamps — with or without seconds
    // (2026-03-24T17:10:41, 2026-03-24 17:10:41, 2026-03-31T11:10).
    $body = preg_replace('/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/', '__TIMESTAMP__', $body) ?? $body;

    // German date/time format (e.g. "26.03.2026 13:42" in page titles / "Stand" values).
    $body = preg_replace('/\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}/', '__TIMESTAMP__', $body) ?? $body;

    // Unix timestamps (10-digit numbers) in JSON/JS assignments.
    $body = preg_replace('/(?<=[:\[,\s])\d{10}(?=[,\]\}\s])/', '__UNIXTS__', $body) ?? $body;

    // Random cache-buster query params on static file URLs (e.g. ?rand=132 or &rand=132 or &amp;rand=132).
    $body = preg_replace('/(?:\?|&amp;|&)rand=\d+/', '&rand=__RAND__', $body) ?? $body;

    // Page render timing in footer (e.g. | 6,25s | or | 0.52s |).
    $body = preg_replace('/\|\s*[\d,.]+s\s*\|/', '| __TIME__ |', $body) ?? $body;

    return trim($body);
}

/**
 * Compute a simple line-based diff between two strings.
 *
 * Returns lines prefixed with "- " (expected) and "+ " (actual).
 *
 * @return list<string>
 */
function computeDiff(string $expected, string $actual): array
{
    $expectedLines = explode("\n", $expected);
    $actualLines = explode("\n", $actual);
    $diff = [];
    $maxLines = max(count($expectedLines), count($actualLines));

    for ($i = 0; $i < $maxLines; $i++) {
        $e = $expectedLines[$i] ?? '';
        $a = $actualLines[$i] ?? '';

        if ($e !== $a) {
            $diff[] = '- ' . $e;
            $diff[] = '+ ' . $a;
        }
    }

    return $diff;
}

/**
 * Return ANSI escape codes only when stdout is a TTY.
 */
function colorize(string $ansi): string
{
    return posix_isatty(STDOUT) ? $ansi : '';
}

