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
        if ($mode !== 'record') {
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
     */
    private static function persist(string $responseBody): void
    {
        $entry = [
            'request' => self::$requestSnapshot,
            'response' => [
                'status' => http_response_code() ?: 200,
                'headers' => headers_list(),
                'body' => $responseBody
            ]
        ];

        $log = [];

        if (file_exists(self::$logPath)) {
            $raw = (string) file_get_contents(self::$logPath);
            // Support gzip-compressed files as well as legacy plain-text files.
            $decompressed = @gzdecode($raw);
            $log = json_decode($decompressed !== false ? $decompressed : $raw, true) ?? [];
        }

        $log[] = $entry;

        $dir = dirname(self::$logPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            self::$logPath,
            gzencode(
                (string) json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                6
            )
        );
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
