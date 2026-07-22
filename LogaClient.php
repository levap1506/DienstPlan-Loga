<?php
/**
 * LOGA Portal - HTTP Client
 * 
 * cURL wrapper with: cookie/header management, automatic xsrf appending,
 * configurable timeouts, retry logic, rate limiting, and circuit breaker.
 * All LOGA HTTP calls go through this class.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';

class LogaClient {
    private LogaLogger $logger;

    /** @var string Current JSESSIONID */
    private string $sessionId = '';

    /** @var string Current XSRF token */
    private string $xsrfToken = '';

    /** @var int Consecutive failure count for circuit breaker */
    private int $consecutiveFailures = 0;

    /** @var bool Circuit breaker tripped */
    private bool $circuitOpen = false;

    /** @var float Timestamp of last request (for rate limiting) */
    private float $lastRequestTime = 0;

    public function __construct(?LogaLogger $logger = null) {
        $this->logger = $logger ?? LogaLogger::getInstance();
    }

    /**
     * Set session credentials for authenticated requests.
     */
    public function setCredentials(string $sessionId, string $xsrfToken): void {
        $this->sessionId = $sessionId;
        $this->xsrfToken = $xsrfToken;
    }

    public function getSessionId(): string {
        return $this->sessionId;
    }

    public function getXsrfToken(): string {
        return $this->xsrfToken;
    }

    public function setSessionId(string $sessionId): void {
        $this->sessionId = $sessionId;
    }

    public function setXsrfToken(string $xsrfToken): void {
        $this->xsrfToken = $xsrfToken;
    }

    /**
     * Perform a GWT-RPC request (login, xsrf token).
     * 
     * @param string $url       Full URL
     * @param string $payload   GWT-RPC serialized payload
     * @param string $type      Header type: 'login', 'afterlogin', 'xsrf'
     * @param bool   $captureHeaders Whether to capture response headers (for cookies)
     * @param string $cookie    Override cookie string (for login flow)
     * @return string Raw response body (or headers+body if captureHeaders)
     */
    public function gwtRequest(string $url, string $payload, string $type, bool $captureHeaders = false, string $cookie = '', array $runtimeConfig = []): string {
        $headers = $this->buildHeaders($type, $runtimeConfig);
        $cookieStr = $cookie ?: $this->buildCookieString();

        return $this->execute($url, $payload, $headers, $captureHeaders, $cookieStr);
    }

    /**
     * Perform a JSON API request (data fetching, shift operations).
     * Automatically appends ?xsrf= and applies rate limiting.
     * 
     * @param string $endpoint  Relative path after LOGA_BASE_URL (e.g. 'private/api/...')
     * @param array  $data      Request body (will be JSON-encoded)
     * @param bool   $withDelay Apply inter-request delay
     * @return array Decoded JSON response
     */
    public function apiRequest(string $endpoint, array $data, bool $withDelay = true): array {
        $this->checkCircuitBreaker();

        if ($withDelay) {
            $this->rateLimit(LOGA_REQUEST_DELAY_MS);
        }

        $url = LOGA_BASE_URL . $endpoint . '?xsrf=' . $this->xsrfToken;
        $payload = json_encode($data);
        $headers = $this->buildHeaders('api');
        $cookie = $this->buildCookieString();

        $response = $this->execute($url, $payload, $headers, false, $cookie);

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            $this->recordFailure();
            throw new RuntimeException("Invalid JSON response from {$endpoint}: " . substr($response, 0, 300));
        }

        // Reset circuit breaker on success
        $this->consecutiveFailures = 0;

        return $decoded;
    }

    /**
     * Perform an API request with automatic retry logic.
     * 
     * @param string $endpoint  API endpoint
     * @param array  $data      Request body
     * @param int    $maxRetries Max retry attempts
     * @param int    $retryDelay Seconds between retries
     * @return array Decoded JSON response
     */
    public function apiRequestWithRetry(string $endpoint, array $data, int $maxRetries = 0, int $retryDelay = 0): array {
        $maxRetries = $maxRetries ?: LOGA_MAX_RETRIES;
        $retryDelay = $retryDelay ?: LOGA_RETRY_DELAY;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->apiRequest($endpoint, $data);
            } catch (Exception $e) {
                $this->logger->debug(
                    "Attempt {$attempt}/{$maxRetries} failed: " . $e->getMessage(),
                    'LogaClient'
                );

                if ($attempt < $maxRetries) {
                    $this->logger->info("Retrying in {$retryDelay}s...", 'LogaClient');
                    sleep($retryDelay);
                } else {
                    throw $e; // Final attempt failed
                }
            }
        }

        // Should never reach here, but just in case
        throw new RuntimeException("All {$maxRetries} attempts failed for {$endpoint}");
    }

    /**
     * Make a raw cURL request (low-level, used by gwtRequest and apiRequest).
     */
    private function execute(string $url, string $payload, array $headers, bool $captureHeaders, string $cookie): string {
        $this->checkCircuitBreaker();

        $ch = curl_init();

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_TIMEOUT        => LOGA_CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => LOGA_CURL_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        if ($captureHeaders) {
            $options[CURLOPT_HEADER] = true;
        }

        if ($cookie) {
            $options[CURLOPT_COOKIE] = $cookie;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $this->lastRequestTime = microtime(true);

        if ($error) {
            $this->recordFailure();
            throw new RuntimeException("cURL error for {$url}: {$error}");
        }

        if ($httpCode !== 200) {
            $this->recordFailure();
            $preview = is_string($response) ? trim(preg_replace('/\s+/', ' ', substr($response, 0, 400))) : '';
            throw new RuntimeException("HTTP {$httpCode} for {$url}" . ($preview ? " | {$preview}" : ''));
        }

        // Success — reset failure count
        $this->consecutiveFailures = 0;

        return $response;
    }

    /**
     * Perform a simple GET request (for runtime config detection).
     */
    public function get(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/javascript,*/*',
                'Accept-Language: de,de-DE;q=0.9,en;q=0.8',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error) {
            throw new RuntimeException("GET failed for {$url}: {$error}");
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("GET HTTP {$httpCode} for {$url}");
        }

        return $response;
    }

    // ─── Header Builders ────────────────────────────────────────────────────

    /**
     * Build headers for a specific request type.
     */
    public function buildHeaders(string $type, array $runtimeConfig = []): array {
        $common = [
            "Origin: https://schwarzw.pi-asp.de",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0",
            "Accept-Encoding: gzip, deflate, br, zstd",
            "Accept-Language: de,de-DE;q=0.9,en;q=0.8,en-GB;q=0.7,en-US;q=0.6",
            "Connection: keep-alive",
        ];

        $logaVersion = $runtimeConfig['logaVersion'] ?? '';

        return match ($type) {
            'login' => array_merge($common, [
                "Referer: https://schwarzw.pi-asp.de/loga3/public/logout",
                "Content-Type: text/x-gwt-rpc; charset=UTF-8",
                "X-GWT-Module-Base: https://schwarzw.pi-asp.de/loga3/bts/{$logaVersion}/Login/",
                "X-GWT-Permutation: " . ($runtimeConfig['loginPermutation'] ?? ''),
                "Accept: */*",
            ]),
            'afterlogin' => array_merge($common, [
                "Referer: https://schwarzw.pi-asp.de/loga3/public/logout",
                "Content-Type: application/x-www-form-urlencoded",
                "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
            ]),
            'xsrf' => array_merge($common, [
                "Referer: https://schwarzw.pi-asp.de/loga3/private/layout?action=afterlogin",
                "Content-Type: text/x-gwt-rpc; charset=UTF-8",
                "X-GWT-Module-Base: https://schwarzw.pi-asp.de/loga3/bts/{$logaVersion}/L2Main/",
                "X-GWT-Permutation: " . ($runtimeConfig['xsrfPermutation'] ?? ''),
                "Accept: */*",
            ]),
            'api' => array_merge($common, [
                "Referer: https://schwarzw.pi-asp.de/loga3/private/layout?action=afterlogin",
                "Content-Type: application/json",
                "Accept: application/json, text/plain, */*",
            ]),
            default => throw new \InvalidArgumentException("Unknown header type: {$type}"),
        };
    }

    /**
     * Build the full cookie string for authenticated requests.
     */
    public function buildCookieString(): string {
        return sprintf(
            "JSESSIONID=%s; LOGIN_LANGUAGE=de; LOGIN_COUNTRY=DE; LOGIN_DISPLAY_NAME_COOKIE=Deutsch%%20(Deutschland); LOGIN_COUNTRY_ICON_COOKIE=de; LOGIN_LANG_SHORTCUT_COOKIE=D",
            $this->sessionId
        );
    }

    // ─── Rate Limiting & Circuit Breaker ────────────────────────────────────

    /**
     * Apply rate limiting delay between requests.
     */
    private function rateLimit(int $delayMs): void {
        if ($this->lastRequestTime > 0) {
            $elapsed = (microtime(true) - $this->lastRequestTime) * 1000;
            if ($elapsed < $delayMs) {
                $waitMs = $delayMs - $elapsed;
                usleep((int)($waitMs * 1000));
            }
        }
    }

    /**
     * Apply batch delay (longer pause between batch groups).
     */
    public function batchDelay(): void {
        $this->rateLimit(LOGA_BATCH_DELAY_MS);
    }

    /**
     * Apply post-login delay.
     */
    public function postLoginDelay(): void {
        usleep(LOGA_POST_LOGIN_DELAY_MS * 1000);
    }

    /**
     * Record a failure for circuit breaker tracking.
     */
    private function recordFailure(): void {
        $this->consecutiveFailures++;
        if ($this->consecutiveFailures >= LOGA_CIRCUIT_BREAKER_THRESHOLD) {
            $this->circuitOpen = true;
            $this->logger->error(
                "Circuit breaker tripped after {$this->consecutiveFailures} consecutive failures — aborting all requests",
                'LogaClient'
            );
        }
    }

    /**
     * Check if circuit breaker is open (too many failures).
     */
    private function checkCircuitBreaker(): void {
        if ($this->circuitOpen) {
            throw new RuntimeException(
                "LOGA appears unavailable — circuit breaker tripped after {$this->consecutiveFailures} consecutive failures. "
                . "Use force=1 to retry."
            );
        }
    }

    /**
     * Reset the circuit breaker (for force mode).
     */
    public function resetCircuitBreaker(): void {
        $this->consecutiveFailures = 0;
        $this->circuitOpen = false;
    }

    /**
     * Check if circuit breaker is currently open.
     */
    public function isCircuitBreakerOpen(): bool {
        return $this->circuitOpen;
    }
}
