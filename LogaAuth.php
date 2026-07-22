<?php
/**
 * LOGA Portal - Authentication
 * 
 * Handles the 3-step LOGA authentication flow:
 * 1. GWT-RPC Login → initial JSESSIONID
 * 2. Afterlogin redirect → final JSESSIONID
 * 3. GWT-RPC getNewXsrfToken → XSRF token
 * 
 * Supports session caching (30 min TTL) and automatic re-authentication.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaClient.php';
require_once __DIR__ . '/LogaRuntimeConfig.php';

class LogaAuth {
    private LogaClient $client;
    private LogaLogger $logger;
    private array $runtimeConfig;

    public function __construct(LogaClient $client, ?LogaLogger $logger = null) {
        $this->client = $client;
        $this->logger = $logger ?? LogaLogger::getInstance();
    }

    /**
     * Authenticate with LOGA. Tries cached session first, then full login.
     * 
     * @param bool $force  Force fresh login (ignore cached session)
     * @return bool True on success
     */
    public function authenticate(bool $force = false): bool {
        // Resolve runtime config (GWT version + permutation tokens)
        $rtConfig = new LogaRuntimeConfig($this->client, $this->logger);
        $this->runtimeConfig = $rtConfig->resolve($force);

        // Try cached session first
        if (!$force && $this->loadCachedSession()) {
            if ($this->validateSession()) {
                $this->logger->info("Reusing cached LOGA session", 'LogaAuth');
                $this->saveSession();
                return true;
            }
            $this->logger->info("Cached session invalid, performing fresh login", 'LogaAuth');
        }

        // Full authentication flow
        $this->logger->info("Authenticating with LOGA...", 'LogaAuth');

        // Step 1: GWT-RPC Login
        $sessionId = $this->performLogin();
        if (!$sessionId) {
            // If login failed, try re-resolving runtime config (version may have changed)
            $this->logger->info("Login failed, re-resolving runtime config...", 'LogaAuth');
            $this->runtimeConfig = $rtConfig->resolve(true);
            $sessionId = $this->performLogin();

            if (!$sessionId) {
                $this->logger->error("Authentication failed after config refresh", 'LogaAuth');
                return false;
            }
        }

        $this->client->setSessionId($sessionId);
        $this->logger->info("Login successful", 'LogaAuth');

        // Post-login delay to avoid overwhelming the server
        $this->client->postLoginDelay();

        // Step 2: Fetch XSRF token
        $xsrfToken = $this->fetchXsrfToken();
        if (!$xsrfToken) {
            $this->logger->error("XSRF token retrieval failed", 'LogaAuth');
            return false;
        }

        $this->client->setXsrfToken($xsrfToken);
        $this->logger->info("XSRF token obtained", 'LogaAuth');

        // Save session for reuse
        $this->saveSession();

        return true;
    }

    /**
     * Get the resolved runtime config.
     */
    public function getRuntimeConfig(): array {
        return $this->runtimeConfig;
    }

    // ─── Login Steps ─────────────────────────────────────────────────────────

    /**
     * Step 1: GWT-RPC Login + Afterlogin.
     * Returns the final JSESSIONID or empty string on failure.
     */
    private function performLogin(): string {
        try {
            $version = $this->runtimeConfig['logaVersion'];
            $moduleBase = LOGA_BASE_URL . "bts/{$version}/Login/";

            // Build GWT-RPC login payload
            $payload = "7|1|9|{$moduleBase}|{$this->runtimeConfig['loginStrongName']}|_|getLoginResponse|a|Z|"
                     . LOGA_USERNAME . "|" . LOGA_PASSWORD . "||1|2|3|4|5|5|5|5|5|6|7|8|9|9|0|";

            $loginUrl = LOGA_BASE_URL . "bts/{$version}/Login/LoginSrv";

            // Send login request (capture headers for JSESSIONID)
            $response = $this->client->gwtRequest($loginUrl, $payload, 'login', true, '', $this->runtimeConfig);

            // Extract initial JSESSIONID from Set-Cookie header
            if (!preg_match('/Set-Cookie:\s*JSESSIONID=([^;]+)/i', $response, $m)) {
                $this->logger->error("No JSESSIONID in login response", 'LogaAuth');
                return '';
            }

            $initialSession = $m[1];
            $this->logger->debug("Initial session obtained: " . substr($initialSession, 0, 10) . "...", 'LogaAuth');

            // Step 2: Afterlogin — get final session
            $cookie = "JSESSIONID={$initialSession}; LOGIN_LANGUAGE=de; LOGIN_COUNTRY=DE";
            $afterLoginUrl = LOGA_BASE_URL . 'private/layout?action=afterlogin';

            $afterResponse = $this->client->gwtRequest($afterLoginUrl, '', 'afterlogin', true, $cookie, $this->runtimeConfig);

            // Extract final JSESSIONID
            if (preg_match('/Set-Cookie:\s*JSESSIONID=([^;]+)/i', $afterResponse, $m)) {
                $this->logger->debug("Final session obtained: " . substr($m[1], 0, 10) . "...", 'LogaAuth');
                return $m[1];
            }

            // If no new session, the initial one might still work
            return $initialSession;

        } catch (\Exception $e) {
            $this->logger->error("Login error: " . $e->getMessage(), 'LogaAuth');
            return '';
        }
    }

    /**
     * Step 3: Fetch XSRF token via GWT-RPC.
     */
    private function fetchXsrfToken(): string {
        try {
            $version = $this->runtimeConfig['logaVersion'];
            $moduleBase = LOGA_BASE_URL . "bts/{$version}/L2Main/";

            $payload = "7|1|4|{$moduleBase}|{$this->runtimeConfig['xsrfStrongName']}|_|getNewXsrfToken|1|2|3|4|0|";

            $xsrfUrl = LOGA_BASE_URL . 'private/xsrf';
            $headers = $this->client->buildHeaders('xsrf', $this->runtimeConfig);
            $cookie = $this->client->buildCookieString();

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $xsrfUrl,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_TIMEOUT        => LOGA_CURL_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_COOKIE         => $cookie,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error || $httpCode !== 200) {
                $this->logger->error("XSRF request failed: HTTP {$httpCode}, error: {$error}", 'LogaAuth');
                return '';
            }

            if (preg_match('/\["3","([A-F0-9]{32})"\]/', $response, $m)) {
                return $m[1];
            }

            $this->logger->error("Invalid XSRF response format: " . substr($response, 0, 200), 'LogaAuth');
            return '';

        } catch (\Exception $e) {
            $this->logger->error("XSRF error: " . $e->getMessage(), 'LogaAuth');
            return '';
        }
    }

    // ─── Session Caching ─────────────────────────────────────────────────────

    /**
     * Load cached session from file.
     */
    private function loadCachedSession(): bool {
        if (!file_exists(LOGA_SESSION_FILE)) {
            $this->logger->debug("No cached session file", 'LogaAuth');
            return false;
        }

        $data = json_decode(@file_get_contents(LOGA_SESSION_FILE), true);
        if (!$data || !isset($data['sessionId'], $data['xsrfToken'], $data['timestamp'])) {
            $this->logger->debug("Invalid session cache format", 'LogaAuth');
            return false;
        }

        $age = time() - $data['timestamp'];
        if ($age > LOGA_SESSION_TTL) {
            $this->logger->debug("Cached session expired (age: {$age}s)", 'LogaAuth');
            @unlink(LOGA_SESSION_FILE);
            return false;
        }

        $this->client->setSessionId($data['sessionId']);
        $this->client->setXsrfToken($data['xsrfToken']);

        $this->logger->debug("Loaded cached session (age: {$age}s)", 'LogaAuth');
        return true;
    }

    /**
     * Validate the current session by requesting a fresh XSRF token.
     */
    private function validateSession(): bool {
        try {
            $newToken = $this->fetchXsrfToken();
            if ($newToken) {
                $this->client->setXsrfToken($newToken);
                $this->logger->debug("Session validated, XSRF token refreshed", 'LogaAuth');
                return true;
            }
            return false;
        } catch (\Exception $e) {
            $this->logger->debug("Session validation failed: " . $e->getMessage(), 'LogaAuth');
            return false;
        }
    }

    /**
     * Save current session to cache file.
     */
    private function saveSession(): void {
        $data = [
            'sessionId' => $this->client->getSessionId(),
            'xsrfToken' => $this->client->getXsrfToken(),
            'timestamp' => time(),
        ];

        $dir = dirname(LOGA_SESSION_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        @file_put_contents(LOGA_SESSION_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        $this->logger->debug("Session saved to cache", 'LogaAuth');
    }
}
