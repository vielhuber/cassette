<?php
declare(strict_types=1);

/**
 * Records incoming HTTP requests and outgoing responses for regression testing.
 *
 * In record mode: captures the full request snapshot at startup, then uses
 * ob_start() with a callback that fires on the final buffer flush to capture
 * the complete response body and headers. Appends the pair to {name}_http.json.
 *
 * In replay/compare mode the actual HTTP replaying is done externally by
 * CassetteReplay.php — this class is only active during record mode.
 *
 * Uses an ob_start() callback instead of register_shutdown_function so that
 * the capture fires synchronously when the outermost output buffer is flushed
 * (e.g. by WordPress's wp_ob_end_flush_all), before headers are actually sent.
 */
final class CassetteHttpRecorder
{
    private static string $logPath = '';
    private static array $requestSnapshot = [];

    /**
     * Start recording for this request.
     *
     * Must be called early — before any output or framework bootstrapping —
     * so that the ob_start() wraps all subsequent output.
     *
     * @param string $cassetteName  e.g. "run_001"
     * @param string $mode          Cassette::MODE_RECORD or Cassette::MODE_MOCK
     * @param string $basePath      Directory where cassette files live.
     */
    public static function start(string $cassetteName, string $mode, string $basePath): void
    {
        if ($mode !== Cassette::MODE_RECORD) {
            return;
        }

        // Skip recording when the request URI matches an entry in the ignore list.
        $configPath = dirname($basePath) . '/config.json';
        if (is_file($configPath)) {
            $config = json_decode((string) file_get_contents($configPath), true) ?? [];
            $ignorePatterns = $config['ignoreUrls'] ?? [];
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            foreach ($ignorePatterns as $pattern) {
                if (str_contains($requestUri, (string) $pattern)) {
                    return;
                }
            }
        }

        self::$logPath = rtrim($basePath, '/') . '/' . $cassetteName . '/http.json';

        // On the very first browser request of a fresh recording session, show an
        // interstitial page that clears all cookies and redirects to the site root.
        // This ensures the recorded session starts from a clean, unauthenticated state.
        $interstitialFlagPath = rtrim($basePath, '/') . '/' . $cassetteName . '/.started';
        $isHtmlRequest = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html');
        if ($isHtmlRequest && !file_exists($interstitialFlagPath)) {
            $dir = dirname(self::$logPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($interstitialFlagPath, '1');
            $baseUrl = self::detectBaseUrl();

            // Clear all cookies server-side so HttpOnly cookies (e.g. Laravel
            // session) are expired — JS cannot reach HttpOnly cookies at all.
            $host    = $_SERVER['HTTP_HOST'] ?? '';
            $expired = 'Thu, 01 Jan 1970 00:00:00 GMT';
            foreach (array_keys($_COOKIE) as $cookieName) {
                $encoded = rawurlencode((string) $cookieName);
                // Expire without domain (covers cookies set without explicit domain).
                header("Set-Cookie: {$encoded}=; expires={$expired}; path=/; SameSite=Lax", false);
                header("Set-Cookie: {$encoded}=; expires={$expired}; path=/; HttpOnly; SameSite=Lax", false);
                // Expire with explicit domain (covers cookies set with domain=).
                if ($host !== '') {
                    header("Set-Cookie: {$encoded}=; expires={$expired}; path=/; domain={$host}; SameSite=Lax", false);
                    header("Set-Cookie: {$encoded}=; expires={$expired}; path=/; domain={$host}; HttpOnly; SameSite=Lax", false);
                }
            }

            header('Content-Type: text/html; charset=utf-8');
            echo self::renderInterstitial($baseUrl);

            // Prevent the shutdown handler from writing an empty bucket-0 so
            // the first real browser request correctly lands at index 0.
            Cassette::abort();

            // uopz.exit=1 would suppress exit() — allow it explicitly so the
            // interstitial page actually terminates without the app rendering behind it.
            if (function_exists('uopz_allow_exit')) {
                uopz_allow_exit(true);
            }
            exit;
        }

        self::$requestSnapshot = self::captureRequest();

        // The callback fires with PHP_OUTPUT_HANDLER_FINAL when the outermost
        // buffer is flushed for the last time. At that point we have both the
        // full response body and can still read headers_list().
        ob_start(
            static function (string $buffer, int $phase): string {
                if ($phase & PHP_OUTPUT_HANDLER_FINAL) {
                    self::persist($buffer);
                }
                return $buffer;
            },
            0,
            PHP_OUTPUT_HANDLER_STDFLAGS
        );

        // ob_start() prevents PHP from physically sending bytes to the client,
        // which keeps headers_sent() returning false for the entire request.
        // In a real (non-buffered) replay request, headers_sent() becomes true
        // immediately after the first echo — app code that uses headers_sent()
        // to choose between "direct echo" and "store in cookie for next request"
        // (e.g. flash-message helpers) therefore behaves differently during
        // recording than during replay. Fix: override headers_sent() via uopz
        // to return true once any content has been written into the output buffer,
        // mirroring exactly what happens in a non-buffered request.
        if (function_exists('uopz_set_return')) {
            uopz_set_return('headers_sent', static function (): bool {
                return ob_get_length() > 0;
            }, true);
        }
    }

    // -------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------

    /**
     * Append the request/response pair to the HTTP log file.
     *
     * Holds an exclusive flock for the whole read-modify-write cycle so
     * concurrent PHP-FPM workers do not overwrite each other's entries
     * (mirrors the locking strategy in Cassette::save()).
     */
    private static function persist(string $responseBody): void
    {
        // Drop responses with no body — typically broken asset URLs (404
        // images), HEAD-style probes, or routes that streamed binary content
        // outside the output buffer. They contribute nothing to regression
        // coverage (replay has no body to diff against) and would otherwise
        // pollute http.json plus leave an orphan bucket on disk. Tell
        // Cassette to skip the bucket too so save() does not land an empty
        // file in buckets/.
        if ($responseBody === '') {
            Cassette::skipBucket();
            return;
        }

        $entry = [
            // Bucket index assigned to this request by Cassette::load(). Embed
            // it here so replay can pair HTTP entries with the exact recorded
            // bucket — concurrent FPM workers may claim bucket slots in a
            // different order than they finish writing http.json.
            'bucket' => Cassette::getRequestIndex(),
            'request' => self::$requestSnapshot,
            'response' => [
                'status' => http_response_code() ?: 200,
                'headers' => headers_list(),
                'body' => $responseBody
            ]
        ];

        $dir = dirname(self::$logPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $lockPath = $dir . '/http.lock';
        $lockFh   = fopen($lockPath, 'c+');

        if ($lockFh !== false) {
            flock($lockFh, LOCK_EX);
        }

        $log = [];

        if (file_exists(self::$logPath)) {
            $raw = (string) file_get_contents(self::$logPath);
            // Support gzip-compressed files as well as legacy plain-text files.
            $decompressed = @gzdecode($raw);
            $log          = json_decode($decompressed !== false ? $decompressed : $raw, true) ?? [];
        }

        $log[] = $entry;

        // gzip level 1: roughly 3x faster than the default 6 for ~10% larger
        // output, a worthwhile trade for hot-path recording of test data.
        file_put_contents(
            self::$logPath,
            gzencode(
                (string) json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                1
            )
        );

        if ($lockFh !== false) {
            flock($lockFh, LOCK_UN);
            fclose($lockFh);
        }
    }

    /**
     * Build a snapshot of the current HTTP request.
     */
    private static function captureRequest(): array
    {
        // Reconstruct headers from $_SERVER (HTTP_* keys).
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            }
        }

        // CONTENT_TYPE and CONTENT_LENGTH are not prefixed with HTTP_ in $_SERVER.
        foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $serverKey) {
            if (!empty($_SERVER[$serverKey])) {
                $headers[str_replace('_', '-', $serverKey)] = $_SERVER[$serverKey];
            }
        }

        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'uri' => $_SERVER['REQUEST_URI'] ?? '/',
            'base_url' => self::detectBaseUrl(),
            'get' => $_GET,
            'post' => $_POST,
            'body' => (string) file_get_contents('php://input'),
            'cookies' => $_COOKIE,
            'headers' => $headers
        ];
    }

    /**
     * Render the recording interstitial page.
     *
     * Clears all cookies via JavaScript and auto-redirects to the site root
     * after 3 seconds so the recorded session starts from a clean state.
     */
    private static function renderInterstitial(string $baseUrl): string
    {
        $safeUrl = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Cassette — Recording</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    background: #111;
                    color: #ff3333;
                    font-family: monospace;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    height: 100vh;
                    text-align: center;
                }
                h1 { font-size: 3rem; letter-spacing: 0.15em; }
                p { color: #666; margin-top: 1.5rem; font-size: 1rem; }
                span { color: #ff3333; }
            </style>
        </head>
        <body>
            <div>
                <h1>⏺ RECORD STARTING.</h1>
                <p>Clearing cookies&hellip; redirecting in <span id="t">3</span>s</p>
            </div>
            <script>
                // Clear all cookies for this domain.
                document.cookie.split(';').forEach(function (c) {
                    var key = c.trim().split('=')[0];
                    var domain = location.hostname;
                    var paths = ['/', location.pathname];
                    paths.forEach(function (path) {
                        document.cookie = key + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=' + path;
                        document.cookie = key + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=' + path + ';domain=' + domain;
                        document.cookie = key + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=' + path + ';domain=.' + domain;
                    });
                });
                // Clear localStorage and sessionStorage.
                try { localStorage.clear(); } catch (e) {}
                try { sessionStorage.clear(); } catch (e) {}
                var remaining = 3;
                var el = document.getElementById('t');
                var interval = setInterval(function () {
                    remaining--;
                    el.textContent = remaining;
                    if (remaining <= 0) {
                        clearInterval(interval);
                        window.location.href = '{$safeUrl}';
                    }
                }, 1000);
            </script>
        </body>
        </html>
        HTML;
    }

    /**
     * Reconstruct the base URL (scheme + host) from server variables.
     */
    private static function detectBaseUrl(): string
    {
        $scheme = 'http';

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO'];
        } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
            $scheme = $_SERVER['REQUEST_SCHEME'];
        }

        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        return $scheme . '://' . $host;
    }
}
