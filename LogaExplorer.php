<?php
/**
 * LOGA Portal - API Explorer
 * 
 * Fetches data from newly discovered LOGA endpoints (dashboard/kontos,
 * dashboard/anwesenheits, dashboard/zeitubersicht, etc.) and stores
 * raw JSON responses for inspection. The idea is to show the raw data first
 * so you can decide on permanent table structures later.
 * 
 * All endpoints are accessed via authenticated LOGA session.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaClient.php';
require_once __DIR__ . '/LogaAuth.php';
require_once __DIR__ . '/LogaRuntimeConfig.php';

class LogaExplorer {
    private LogaClient $client;
    private LogaAuth $auth;
    private LogaLogger $logger;
    private ?mysqli $conn;
    private ?string $requestDateFrom = null;
    private ?string $requestDateTo = null;

    /**
     * Body type for POST requests:
     *   'none'          → GET request, no body
     *   'xsrf'          → POST with {"xsrf":"<token>"}
     *   'clientContext'  → POST with clientContext + path payload
     */

    /**
     * Available endpoints to explore.
     * Paths verified against real LOGA HAR captures.
     */
    private const ENDPOINTS = [
        // ─── Dashboard Info (verified GET, HTTP 200) ───
        'rest-of-holiday' => [
            'label'    => 'Resturlaub (Urlaubstage)',
            'path'     => 'private/api/dashboard/info/restOfHoliday',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'Dashboard Info',
        ],
        'hours-of-work' => [
            'label'    => 'Mitarbeiter Stunden',
            'path'     => 'private/api/dashboard/info/hoursOfWork',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'Dashboard Info',
        ],

        // ─── Dashboard Common (verified GET, HTTP 200) ───
        'dashboard-settings' => [
            'label'    => 'Dashboard Settings (full config)',
            'path'     => 'private/api/dashboard/common/loadSetting',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'Dashboard Common',
        ],
        'security-design-mode' => [
            'label'    => 'Security Design Mode',
            'path'     => 'private/api/dashboard/common/loadSecurityDesignMode',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'Dashboard Common',
        ],
        'welcome-message' => [
            'label'    => 'Welcome Message',
            'path'     => 'private/api/dashboard/common/loadWelcomeMessage',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'Dashboard Common',
        ],
        'application-text' => [
            'label'    => 'Application Text',
            'path'     => 'private/api/dashboard/common/loadApplicationText',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'Dashboard Common',
        ],
        'algo-information' => [
            'label'    => 'Algorithm Information',
            'path'     => 'private/api/dashboard/common/loadAlgoInformation',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'Dashboard Common',
        ],
        'pinned-sds' => [
            'label'    => 'Pinned SDs',
            'path'     => 'private/api/dashboard/pinnedSDs',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'Dashboard Common',
        ],

        // ─── Dashboard POST with XSRF body (verified POST, HTTP 200) ───
        'digital-assistant-params' => [
            'label'    => 'Digital Assistant Params',
            'path'     => 'private/api/dashboard/common/loadDigitalAssistantParams',
            'method'   => 'POST',
            'bodyType' => 'xsrf',
            'category' => 'Dashboard Assistant',
        ],
        'assistant-limit' => [
            'label'    => 'Assistant Limit by ASP',
            'path'     => 'private/api/dashboard/digitalAssistant/getLimitByAsp',
            'method'   => 'POST',
            'bodyType' => 'xsrf',
            'category' => 'Dashboard Assistant',
        ],
        'assistant-events' => [
            'label'    => 'Assistant Panel Events',
            'path'     => 'private/api/dashboard/digitalAssistant/loadPanelEvents',
            'method'   => 'POST',
            'bodyType' => 'xsrf',
            'category' => 'Dashboard Assistant',
        ],

        // ─── LOGA Navigation (GET, may return 500 depending on user state) ───
        'loga-last-abwesenheit' => [
            'label'    => 'Letzte Änderung An-/Abwesenheit',
            'path'     => 'private/api/dashboard/loga/loadLastPersonBrgGrp/LOGA_AN_UND_ABWESENHEIT',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'LOGA Navigation',
        ],
        'loga-last-tabellen' => [
            'label'    => 'Letzte Änderung Tabellen',
            'path'     => 'private/api/dashboard/loga/loadLastPersonBrgGrp/LOGA_TABELLEN',
            'method'   => 'GET',
            'bodyType' => 'none',
            'category' => 'LOGA Navigation',
        ],

        // ─── Konten API (verified POST with clientContext, HTTP 200) ───
        'konten-settings' => [
            'label'    => 'Konten Settings',
            'path'     => 'private/api/kontenApi/loadSettings',
            'method'   => 'POST',
            'bodyType' => 'clientContext',
            'category' => 'Konten (Stunden/Urlaub)',
        ],
        'konten-person-data' => [
            'label'    => 'Person Kontodaten (Stunden/Urlaub pro MA)',
            'path'     => 'private/api/kontenApi/loadpersonkontodata',
            'method'   => 'POST',
            'bodyType' => 'clientContextPathExt',
            'category' => 'Konten (Stunden/Urlaub)',
        ],
        'konten-objekt-sum' => [
            'label'    => 'Objekt Konten Summe (Gesamt)',
            'path'     => 'private/api/kontenApi/loadObjektKontenSum',
            'method'   => 'POST',
            'bodyType' => 'clientContextPathExt',
            'category' => 'Konten (Stunden/Urlaub)',
        ],
        'konten-sollzeit-sum' => [
            'label'    => 'Sollzeit Konten Summe',
            'path'     => 'private/api/kontenApi/loadSollzeitKontenSum',
            'method'   => 'POST',
            'bodyType' => 'clientContextPathExt',
            'category' => 'Konten (Stunden/Urlaub)',
        ],

        // ─── SPEP Data Service (verified POST, various body formats from HAR) ───
        'spep-persons' => [
            'label'    => 'SPEP Objekt Persons',
            'path'     => 'private/api/spepdataservice/loadspepobjektpersons',
            'method'   => 'POST',
            'bodyType' => 'clientContextPath',
            'category' => 'SPEP Data',
        ],
        'spep-persons-data' => [
            'label'    => 'SPEP Persons Data (Schichten)',
            'path'     => 'private/api/spepdataservice/loadspepobjektpersonsdata',
            'method'   => 'POST',
            'bodyType' => 'clientContextPath',
            'category' => 'SPEP Data',
        ],
        'spep-objekts' => [
            'label'    => 'SPEP Objekts',
            'path'     => 'private/api/spepdataservice/loadobjekts',
            'method'   => 'POST',
            'bodyType' => 'flatContext',
            'category' => 'SPEP Data',
        ],
        'spep-objekt-status' => [
            'label'    => 'SPEP Objekt Status',
            'path'     => 'private/api/spepdataservice/loadobjektstatus',
            'method'   => 'POST',
            'bodyType' => 'clientContextPathsApproval',
            'category' => 'SPEP Data',
        ],
        'spep-objekt-data' => [
            'label'    => 'SPEP Objekt Data',
            'path'     => 'private/api/spepdataservice/loadobjektdata',
            'method'   => 'POST',
            'bodyType' => 'clientContextPath',
            'category' => 'SPEP Data',
        ],
        'spep-objekt-groups' => [
            'label'    => 'SPEP Objekt Groups',
            'path'     => 'private/api/spepdataservice/loadspepobjektgroups',
            'method'   => 'POST',
            'bodyType' => 'clientContextPath',
            'category' => 'SPEP Data',
        ],
        'spep-shifts' => [
            'label'    => 'SPEP Objekt Shifts',
            'path'     => 'private/api/spepdataservice/loadspepobjektshifts',
            'method'   => 'POST',
            'bodyType' => 'clientContextPath',
            'category' => 'SPEP Data',
        ],
        'spep-quali-status' => [
            'label'    => 'SPEP Quali Status',
            'path'     => 'private/api/spepdataservice/loadqualistatus',
            'method'   => 'POST',
            'bodyType' => 'clientContextPathsApproval',
            'category' => 'SPEP Data',
        ],
        'spep-tabs' => [
            'label'    => 'SPEP Tabs',
            'path'     => 'private/api/spepdataservice/loadtabs',
            'method'   => 'POST',
            'bodyType' => 'flatContext',
            'category' => 'SPEP Data',
        ],
        'spep-role' => [
            'label'    => 'SPEP Role',
            'path'     => 'private/api/spepdataservice/loadrole',
            'method'   => 'POST',
            'bodyType' => 'flatContext',
            'category' => 'SPEP Data',
        ],
        'spep-actual-user' => [
            'label'    => 'SPEP Actual User Data',
            'path'     => 'private/api/spepdataservice/loadActualUserData',
            'method'   => 'POST',
            'bodyType' => 'flatContextNull',
            'category' => 'SPEP Data',
        ],
        'spep-reference-plan' => [
            'label'    => 'SPEP Reference Plan Data',
            'path'     => 'private/api/spepdataservice/loadreferenceplandata',
            'method'   => 'POST',
            'bodyType' => 'clientContextObjektId',
            'category' => 'SPEP Data',
        ],
        'spep-rule-results' => [
            'label'    => 'SPEP Rule Results',
            'path'     => 'private/api/spepdataservice/loadruleresults',
            'method'   => 'POST',
            'bodyType' => 'clientContextPathsString',
            'category' => 'SPEP Data',
        ],

        // ─── Objekt Day Comments ───
        'objekt-day-comments' => [
            'label'    => 'Objekt Day Comments',
            'path'     => 'private/api/objektDayComments/loadAll',
            'method'   => 'POST',
            'bodyType' => 'clientContextObjektIds',
            'category' => 'SPEP Data',
        ],
    ];

    public function __construct(LogaClient $client, LogaAuth $auth, ?LogaLogger $logger = null, ?mysqli $conn = null) {
        $this->client = $client;
        $this->auth = $auth;
        $this->logger = $logger ?? LogaLogger::getInstance();
        $this->conn = $conn;
    }

    public function setRequestInterval(string $dateFrom, string $dateTo): void {
        $this->requestDateFrom = $dateFrom;
        $this->requestDateTo = $dateTo;
    }

    public function clearRequestInterval(): void {
        $this->requestDateFrom = null;
        $this->requestDateTo = null;
    }

    /**
     * Get the list of available endpoints (for UI).
     */
    public static function getEndpointList(): array {
        $list = [];
        foreach (self::ENDPOINTS as $key => $ep) {
            $list[] = [
                'key'      => $key,
                'label'    => $ep['label'],
                'path'     => $ep['path'],
                'method'   => $ep['method'],
                'category' => $ep['category'],
            ];
        }
        return $list;
    }

    /**
     * Get endpoints grouped by category.
     */
    public static function getEndpointsByCategory(): array {
        $grouped = [];
        foreach (self::ENDPOINTS as $key => $ep) {
            $cat = $ep['category'];
            if (!isset($grouped[$cat])) $grouped[$cat] = [];
            $grouped[$cat][] = [
                'key'    => $key,
                'label'  => $ep['label'],
                'path'   => $ep['path'],
                'method' => $ep['method'],
            ];
        }
        return $grouped;
    }

    /**
     * Build the request body for a given bodyType.
     *
     * Body types (from HAR analysis):
     *   'none'                    → no body (GET)
     *   'xsrf'                    → {"xsrf":"<token>"}
     *   'flatContext'             → flat interval/man/contextRoleId (no clientContext wrapper)
     *   'flatContextNull'         → flat interval/man, contextRoleId=null
     *   'clientContext'            → clientContext only
     *   'clientContextPath'        → clientContext + simple path array
     *   'clientContextPathExt'     → clientContext + extended path with boolean flags (kontenApi)
     *   'clientContextPathsApproval' → clientContext + paths array with ext flags + approvalInterval
     *   'clientContextPathsString' → clientContext + paths as "/" joined string
     *   'clientContextObjektId'    → clientContext + objektId
     *   'clientContextObjektIds'   → clientContext + objektIds array
     */
    private function buildRequestBody(string $bodyType): ?string {
        $interval = [
            'dateFrom' => $this->requestDateFrom ?? date('Y-m-01'),
            'dateTo'   => $this->requestDateTo ?? date('Y-m-t'),
        ];
        $clientContext = [
            'interval'      => $interval,
            'countryNls'    => LOGA_COUNTRY_NLS,
            'man'           => LOGA_MANDANT,
            'contextRoleId' => LOGA_CONTEXT_ROLE_ID,
        ];

        return match ($bodyType) {
            'none' => null,
            'xsrf' => json_encode(['xsrf' => $this->client->getXsrfToken()]),
            'flatContext' => json_encode($clientContext),
            'flatContextNull' => json_encode([
                'interval'      => $interval,
                'countryNls'    => LOGA_COUNTRY_NLS,
                'man'           => LOGA_MANDANT,
                'contextRoleId' => null,
            ]),
            'clientContext' => json_encode([
                'clientContext' => $clientContext,
            ]),
            'clientContextPath' => json_encode([
                'clientContext' => $clientContext,
                'path' => ['path' => LOGA_OBJEKT_PATH],
            ]),
            'clientContextPathExt' => json_encode([
                'clientContext' => $clientContext,
                'path' => [
                    'path'          => LOGA_OBJEKT_PATH,
                    'objektGroups'  => false,
                    'shiftGroups'   => false,
                    'dienstGroups'  => false,
                    'shiftGrTpl'    => false,
                ],
                'reload' => false,
            ]),
            'clientContextPathsApproval' => json_encode([
                'clientContext' => $clientContext,
                'paths' => [[
                    'path'          => LOGA_OBJEKT_PATH,
                    'objektGroups'  => false,
                    'shiftGroups'   => false,
                    'dienstGroups'  => false,
                    'shiftGrTpl'    => false,
                ]],
                'approvalInterval' => $interval,
            ]),
            'clientContextPathsString' => json_encode([
                'clientContext' => $clientContext,
                'paths' => [implode('/', LOGA_OBJEKT_PATH)],
            ]),
            'clientContextObjektId' => json_encode([
                'clientContext' => $clientContext,
                'objektId' => LOGA_OBJEKT_ID,
            ]),
            'clientContextObjektIds' => json_encode([
                'clientContext' => $clientContext,
                'objektIds' => [LOGA_OBJEKT_ID],
            ]),
            default => null,
        };
    }

    /**
     * Fetch data from a single endpoint.
     * Uses proper headers and cookies matching the real LOGA browser session.
     * 
     * @param string $endpointKey  Key from ENDPOINTS
     * @param bool   $saveToDb     Also save to loga_explorer_data table
     * @return array ['success' => bool, 'endpoint' => string, 'data' => mixed, 'raw' => string, 'httpStatus' => int, 'durationMs' => int, 'size' => int]
     */
    public function fetch(string $endpointKey, bool $saveToDb = true): array {
        if (!isset(self::ENDPOINTS[$endpointKey])) {
            return ['success' => false, 'error' => "Unknown endpoint: {$endpointKey}"];
        }

        $ep = self::ENDPOINTS[$endpointKey];
        $this->logger->info("Explorer: Fetching '{$ep['label']}' ({$ep['path']})", 'LogaExplorer');

        $startTime = microtime(true);

        try {
            $url = LOGA_BASE_URL . $ep['path'] . '?xsrf=' . $this->client->getXsrfToken();
            $headers = $this->client->buildHeaders('api');
            $cookie = $this->client->buildCookieString();
            $bodyType = $ep['bodyType'] ?? 'none';
            $body = $this->buildRequestBody($bodyType);

            $ch = curl_init();
            $curlOpts = [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_TIMEOUT        => LOGA_CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => LOGA_CURL_CONNECT_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_COOKIE         => $cookie,
            ];

            if ($ep['method'] === 'POST' && $body !== null) {
                $curlOpts[CURLOPT_POST] = true;
                $curlOpts[CURLOPT_POSTFIELDS] = $body;
            }

            curl_setopt_array($ch, $curlOpts);

            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            if ($response === false) {
                throw new \RuntimeException("cURL error: {$curlError}");
            }

            $size = strlen($response);

            // Try to decode as JSON
            $decoded = json_decode($response, true);
            $isJson = (json_last_error() === JSON_ERROR_NONE);

            $result = [
                'success'    => ($httpCode >= 200 && $httpCode < 400),
                'endpoint'   => $ep['path'],
                'label'      => $ep['label'],
                'category'   => $ep['category'],
                'method'     => $ep['method'],
                'httpStatus' => $httpCode,
                'durationMs' => $durationMs,
                'size'       => $size,
                'isJson'     => $isJson,
                'data'       => $isJson ? $decoded : null,
                'raw'        => $response,
            ];

            // Save to DB if requested
            if ($saveToDb && $this->conn) {
                $this->saveToDb($endpointKey, $ep, $response, $httpCode, $durationMs, $size);
            }

            $this->logger->info(
                "Explorer: {$ep['label']} → HTTP {$httpCode}, {$size} bytes, {$durationMs}ms",
                'LogaExplorer'
            );

            return $result;

        } catch (\Exception $e) {
            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $this->logger->error("Explorer: {$ep['label']} failed: " . $e->getMessage(), 'LogaExplorer');
            return [
                'success'    => false,
                'endpoint'   => $ep['path'],
                'label'      => $ep['label'],
                'error'      => $e->getMessage(),
                'httpStatus' => 0,
                'durationMs' => $durationMs,
            ];
        }
    }

    /**
     * Fetch multiple endpoints.
     * 
     * @param array $keys   Endpoint keys to fetch (or empty for all)
     * @param bool  $saveToDb
     * @return array Results keyed by endpoint key
     */
    public function fetchMultiple(array $keys = [], bool $saveToDb = true): array {
        if (empty($keys)) {
            $keys = array_keys(self::ENDPOINTS);
        }

        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->fetch($key, $saveToDb);
            // Rate-limit between requests
            usleep(LOGA_REQUEST_DELAY_MS * 1000);
        }

        return $results;
    }

    /**
     * Get previously saved explorer data from DB.
     */
    public function getHistory(int $limit = 50): array {
        if (!$this->conn) return [];

        $stmt = $this->conn->prepare(
            "SELECT id, endpoint, endpoint_label, http_status, response_size, duration_ms, fetched_by, created_at
             FROM loga_explorer_data
             ORDER BY created_at DESC
             LIMIT ?"
        );
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /**
     * Get a single saved response by ID (for viewing full raw JSON).
     */
    public function getSavedResponse(int $id): ?array {
        if (!$this->conn) return null;

        $stmt = $this->conn->prepare(
            "SELECT * FROM loga_explorer_data WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /**
     * Save response to the explorer_data table.
     */
    private function saveToDb(string $key, array $ep, string $response, int $httpCode, int $durationMs, int $size): void {
        $user = $_SESSION['username'] ?? $_SESSION['name'] ?? 'system';

        $stmt = $this->conn->prepare(
            "INSERT INTO loga_explorer_data (endpoint, endpoint_label, request_params, response_data, response_size, http_status, duration_ms, fetched_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $paramsJson = json_encode(['key' => $key, 'method' => $ep['method']]);

        $stmt->bind_param(
            'ssssiiis',
            $ep['path'],
            $ep['label'],
            $paramsJson,
            $response,
            $size,
            $httpCode,
            $durationMs,
            $user
        );

        if (!$stmt->execute()) {
            $this->logger->error("Failed to save explorer data: " . $stmt->error, 'LogaExplorer');
        }
        $stmt->close();
    }

    /**
     * Check if the explorer table exists.
     */
    public function tableExists(): bool {
        if (!$this->conn) return false;
        $result = $this->conn->query("SHOW TABLES LIKE 'loga_explorer_data'");
        return $result && $result->num_rows > 0;
    }
}
