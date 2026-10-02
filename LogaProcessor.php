<?php
/**
 * LOGA Portal - Data Processor
 * 
 * Processes cached LOGA JSON data into the local database.
 * Handles shift type registration, absence type registration,
 * and data extraction for other modules.
 * This is the "process" mode — reads from JSON cache, writes to DB.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaCache.php';

class LogaProcessor {
    private mysqli $conn;
    private LogaLogger $logger;
    private LogaCache $cache;

    public function __construct(mysqli $conn, ?LogaLogger $logger = null, ?LogaCache $cache = null) {
        $this->conn = $conn;
        $this->logger = $logger ?? LogaLogger::getInstance();
        $this->cache = $cache ?? new LogaCache($this->logger);
    }

    /**
     * Process cached JSON data for a given month.
     * Registers new shift types and absence types in the DB.
     * 
     * @param string $monthKey Month key (YYYY-MM)
     * @return array Result with keys: success, personsTable, shiftList, absenceTypes, personsShifts, personsAbsences
     */
    public function process(string $monthKey): array {
        $cached = $this->cache->read($monthKey);
        if (!$cached) {
            // Try reading even stale data
            $allData = $this->cache->getAll();
            if ($allData && isset($allData['months'][$monthKey])) {
                $cached = $allData['months'][$monthKey];
                $this->logger->info("Using stale cache for {$monthKey}", 'LogaProcessor');
            } else {
                throw new \RuntimeException("No cached data found for {$monthKey}. Run 'fetch' mode first.");
            }
        }

        $personsData = $cached['personsData'] ?? [];
        $persons = $cached['persons'] ?? [];

        if (empty($personsData)) {
            $this->logger->info("No persons data to process for {$monthKey}", 'LogaProcessor');
            return [
                'success'          => true,
                'personsTable'     => [],
                'shiftList'        => [],
                'absenceTypes'     => [],
                'personsShifts'    => [],
                'personsAbsences'  => [],
            ];
        }

        $this->logger->info("Processing {$monthKey}: " . count($personsData) . " person records", 'LogaProcessor');

        // Process each data type
        $personsTable = $this->processPersonsTable($personsData);
        $shiftList = $this->processShifts($personsData);
        $absenceTypes = $this->processAbsenceTypes($personsData);
        $personsShifts = $this->processPersonsShifts($personsData, $shiftList);
        $personsAbsences = $this->processPersonsAbsences($personsData, $absenceTypes);

        $this->logger->info(
            "Processing complete: " . count($personsTable) . " persons, "
            . count($shiftList) . " shift types, "
            . count($absenceTypes) . " absence types",
            'LogaProcessor'
        );

        return [
            'success'          => true,
            'personsTable'     => $personsTable,
            'shiftList'        => $shiftList,
            'absenceTypes'     => $absenceTypes,
            'personsShifts'    => $personsShifts,
            'personsAbsences'  => $personsAbsences,
        ];
    }

    /**
     * Process raw personsData directly (not from cache).
     * Used when data was just fetched and is still in memory.
     */
    public function processRaw(array $personsData): array {
        $personsTable = $this->processPersonsTable($personsData);
        $shiftList = $this->processShifts($personsData);
        $absenceTypes = $this->processAbsenceTypes($personsData);
        $personsShifts = $this->processPersonsShifts($personsData, $shiftList);
        $personsAbsences = $this->processPersonsAbsences($personsData, $absenceTypes);

        return [
            'success'          => true,
            'personsTable'     => $personsTable,
            'shiftList'        => $shiftList,
            'absenceTypes'     => $absenceTypes,
            'personsShifts'    => $personsShifts,
            'personsAbsences'  => $personsAbsences,
        ];
    }

    // ─── Persons Table ──────────────────────────────────────────────────────

    /**
     * Extract a clean persons table from raw data.
     */
    public function processPersonsTable(array $allResults): array {
        $table = [];
        foreach ($allResults as $person) {
            $table[] = [
                'pnr'      => $person['manAkPnrVertnr']['pnr'] ?? null,
                'fullName' => $person['fullName'] ?? null,
                'valid'    => array_map(fn($v) => [
                    'dateFrom' => $v['dateFrom'] ?? null,
                    'dateTo'   => $v['dateTo'] ?? null,
                ], $person['valid'] ?? []),
            ];
        }

        $this->logger->info("Processed " . count($table) . " persons", 'LogaProcessor');
        return $table;
    }

    // ─── Shift Types ────────────────────────────────────────────────────────

    /**
     * Collect unique shift types and register new ones in loga_shiftlist.
     */
    public function processShifts(array $allResults): array {
        $uniqueShifts = [];
        foreach ($allResults as $person) {
            if (!isset($person['shiftData']) || !is_array($person['shiftData'])) continue;

            foreach ($person['shiftData'] as $day) {
                if (!isset($day['shifts']) || !is_array($day['shifts'])) continue;
                foreach ($day['shifts'] as $shift) {
                    $id = $shift['id'] ?? null;
                    $sc = $shift['shortcut'] ?? null;
                    if ($id && $sc) {
                        $uniqueShifts[$id] = $sc;
                    }
                }
            }
        }

        asort($uniqueShifts);

        // Get existing records
        $existing = [];
        $result = $this->conn->query("SELECT id, originalId, shortcut FROM loga_shiftlist");
        while ($row = $result->fetch_assoc()) {
            $existing[$row['originalId'] . '-' . $row['shortcut']] = (int)$row['id'];
        }

        // Insert new shift types
        $shiftList = [];
        $stmt = $this->conn->prepare("INSERT INTO loga_shiftlist (originalId, shortcut) VALUES (?, ?)");
        $newCount = 0;

        foreach ($uniqueShifts as $origId => $shortcut) {
            $key = $origId . '-' . $shortcut;
            if (!isset($existing[$key])) {
                $stmt->bind_param("ss", $origId, $shortcut);
                $stmt->execute();
                $existing[$key] = $stmt->insert_id;
                $newCount++;
                $this->logger->info("New shift type registered: {$shortcut} ({$origId})", 'LogaProcessor');
            }

            $shiftList[] = [
                'id'         => $existing[$key],
                'originalId' => $origId,
                'shortcut'   => $shortcut,
            ];
        }

        $stmt->close();

        if ($newCount > 0) {
            $this->logger->info("Registered {$newCount} new shift types", 'LogaProcessor');
        }
        $this->logger->info("Processed " . count($shiftList) . " total shift types", 'LogaProcessor');

        return $shiftList;
    }

    // ─── Absence Types ──────────────────────────────────────────────────────

    /**
     * Collect unique absence types and register new ones in loga_absencelist.
     */
    public function processAbsenceTypes(array $allResults): array {
        $uniqueAbsences = [];

        foreach ($allResults as $person) {
            // Regular absences
            if (isset($person['personAbsenceDataMap']) && is_array($person['personAbsenceDataMap'])) {
                foreach ($person['personAbsenceDataMap'] as $day) {
                    if (!isset($day['absenceData'])) continue;
                    foreach ($day['absenceData'] as $abs) {
                        $sym = $abs['symbol'] ?? null;
                        if ($sym) {
                            $uniqueAbsences[$sym] = [
                                'symbol'          => $sym,
                                'symbolKurzel'    => $abs['symbolKurzel'] ?? null,
                                'symbolBez'       => $abs['symbolBez'] ?? null,
                                'symbolDisplayed' => $abs['symbolDisplayed'] ?? null,
                            ];
                        }
                    }
                }
            }

            // Absence requests
            if (isset($person['absenceRequestDataMap']) && is_array($person['absenceRequestDataMap'])) {
                foreach ($person['absenceRequestDataMap'] as $day) {
                    if (!isset($day['data'])) continue;
                    foreach ($day['data'] as $req) {
                        $sym = $req['symbol'] ?? null;
                        if ($sym) {
                            $uniqueAbsences[$sym] = [
                                'symbol'          => $sym,
                                'symbolKurzel'    => $req['symbolKurzel'] ?? null,
                                'symbolBez'       => $req['symbolBez'] ?? null,
                                'symbolDisplayed' => $req['symbolDisplayed'] ?? null,
                            ];
                        }
                    }
                }
            }
        }

        // Get existing records
        $existing = [];
        $result = $this->conn->query("SELECT id, symbol FROM loga_absencelist");
        while ($row = $result->fetch_assoc()) {
            $existing[$row['symbol']] = (int)$row['id'];
        }

        // Insert new absence types
        $absenceTypes = [];
        $stmt = $this->conn->prepare(
            "INSERT INTO loga_absencelist (symbol, symbolKurzel, symbolBez, symbolDisplayed, used, created_at) VALUES (?, ?, ?, ?, 0, NOW())"
        );
        $newCount = 0;

        foreach ($uniqueAbsences as $sym => $data) {
            if (!isset($existing[$sym])) {
                $stmt->bind_param("ssss", $data['symbol'], $data['symbolKurzel'], $data['symbolBez'], $data['symbolDisplayed']);
                $stmt->execute();
                $existing[$sym] = $stmt->insert_id;
                $newCount++;
                $this->logger->info("New absence type registered: {$sym} - {$data['symbolBez']}", 'LogaProcessor');
            }

            $data['id'] = $existing[$sym];
            $absenceTypes[] = $data;
        }

        $stmt->close();

        if ($newCount > 0) {
            $this->logger->info("Registered {$newCount} new absence types", 'LogaProcessor');
        }
        $this->logger->info("Processed " . count($absenceTypes) . " total absence types", 'LogaProcessor');

        return $absenceTypes;
    }

    // ─── Persons Shifts ─────────────────────────────────────────────────────

    /**
     * Map persons to their shifts (by date + shift DB ID).
     */
    public function processPersonsShifts(array $allResults, array $shiftList): array {
        $shiftMap = [];
        foreach ($shiftList as $shift) {
            $normalizedId = trim(mb_convert_encoding($shift['originalId'], 'UTF-8'));
            $shiftMap[$normalizedId] = $shift['id'];
        }

        $personsShifts = [];
        foreach ($allResults as $person) {
            $pnr = $person['manAkPnrVertnr']['pnr'] ?? null;
            $name = $person['fullName'] ?? null;
            if (!$pnr || !$name || !isset($person['shiftData'])) continue;

            $dates = [];
            foreach ($person['shiftData'] as $date => $day) {
                if (!isset($day['shifts']) || !is_array($day['shifts'])) continue;
                foreach ($day['shifts'] as $shift) {
                    $origId = $shift['id'] ?? null;
                    if ($origId === null || !isset($shiftMap[$origId])) continue;

                    // Dienstsplit: several persons share one duty on the same day.
                    // LOGA annotates each part with a shiftSplitData block.
                    $split    = is_array($shift['shiftSplitData'] ?? null) ? $shift['shiftSplitData'] : null;
                    $interval = is_array($shift['timeInterval'] ?? null) ? $shift['timeInterval'] : null;

                    $dates[] = [
                        'date'        => $date,
                        'id'          => $shiftMap[$origId],
                        'splitId'     => $split['splitShift_id'] ?? null,
                        'splitOrder'  => isset($split['order']) ? (int)$split['order'] : null,
                        'timeFrom'    => self::normalizeTime($interval['timeFrom'] ?? null),
                        'timeTo'      => self::normalizeTime($interval['timeTo'] ?? null),
                        'endsNextDay' => !empty($interval['endsNextDay']),
                    ];
                }
            }

            usort($dates, fn($a, $b) => strcmp($a['date'], $b['date']));

            $personsShifts[] = [
                'pnr'      => $pnr,
                'fullName' => $name,
                'dates'    => $dates,
            ];
        }

        $this->logger->info("Processed shift assignments for " . count($personsShifts) . " persons", 'LogaProcessor');
        return $personsShifts;
    }

    /**
     * Normalize a LOGA time value ("08:30:00.000") into DB TIME format ("08:30:00").
     */
    public static function normalizeTime(?string $value): ?string {
        if ($value === null || $value === '') return null;
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $value, $m)) {
            return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], (int)($m[3] ?? 0));
        }
        return null;
    }

    // ─── Persons Absences ───────────────────────────────────────────────────

    /**
     * Map persons to their absences (by date + absence DB ID).
     */
    public function processPersonsAbsences(array $allResults, array $absenceTypes): array {
        $absenceIdMap = [];
        foreach ($absenceTypes as $abs) {
            $absenceIdMap[$abs['symbol']] = $abs['id'];
        }

        $personsAbsences = [];
        foreach ($allResults as $person) {
            $pnr = $person['manAkPnrVertnr']['pnr'] ?? null;
            $name = $person['fullName'] ?? null;
            if (!$pnr || !$name) continue;

            $dates = [];

            // Regular absences
            if (isset($person['personAbsenceDataMap']) && is_array($person['personAbsenceDataMap'])) {
                foreach ($person['personAbsenceDataMap'] as $date => $day) {
                    if (!isset($day['absenceData'])) continue;
                    foreach ($day['absenceData'] as $abs) {
                        $sym = $abs['symbol'] ?? null;
                        $interval = $abs['interval'] ?? null;
                        if ($sym && isset($absenceIdMap[$sym]) && is_array($interval)) {
                            $start = new \DateTime($interval['dateFrom'] ?? $date);
                            $end = new \DateTime($interval['dateTo'] ?? $date);
                            while ($start <= $end) {
                                $dates[] = [
                                    'date'       => $start->format('Y-m-d'),
                                    'shortcutId' => $absenceIdMap[$sym],
                                    'type'       => 'absence',
                                ];
                                $start->modify('+1 day');
                            }
                        }
                    }
                }
            }

            // Absence requests
            if (isset($person['absenceRequestDataMap']) && is_array($person['absenceRequestDataMap'])) {
                foreach ($person['absenceRequestDataMap'] as $date => $day) {
                    if (!isset($day['data'])) continue;
                    foreach ($day['data'] as $req) {
                        $sym = $req['symbol'] ?? null;
                        $interval = $req['interval'] ?? null;
                        if ($sym && isset($absenceIdMap[$sym]) && is_array($interval)) {
                            $start = new \DateTime($interval['dateFrom'] ?? $date);
                            $end = new \DateTime($interval['dateTo'] ?? $date);
                            while ($start <= $end) {
                                $dates[] = [
                                    'date'       => $start->format('Y-m-d'),
                                    'shortcutId' => $absenceIdMap[$sym],
                                    'type'       => 'request',
                                ];
                                $start->modify('+1 day');
                            }
                        }
                    }
                }
            }

            // Deduplicate
            $unique = [];
            foreach ($dates as $entry) {
                $key = $entry['date'] . '|' . $entry['shortcutId'];
                if (!isset($unique[$key])) {
                    $unique[$key] = $entry;
                }
            }

            $dates = array_values($unique);
            usort($dates, fn($a, $b) => strcmp($a['date'], $b['date']));

            if (!empty($dates)) {
                $personsAbsences[] = [
                    'pnr'      => $pnr,
                    'fullName' => $name,
                    'dates'    => $dates,
                ];
            }
        }

        $this->logger->info("Processed absence assignments for " . count($personsAbsences) . " persons", 'LogaProcessor');
        return $personsAbsences;
    }

    // ─── DB Helpers ─────────────────────────────────────────────────────────

    /**
     * Get user PNR → UID mapping.
     */
    public function getPnrToUidMap(): array {
        $map = [];
        $result = $this->conn->query("SELECT id, pnr FROM user WHERE pnr IS NOT NULL");
        while ($row = $result->fetch_assoc()) {
            $map[$row['pnr']] = (int)$row['id'];
        }
        return $map;
    }

    /**
     * Get user UID → name mapping.
     */
    public function getUidToNameMap(): array {
        $map = [];
        $result = $this->conn->query("SELECT id, name FROM user WHERE pnr IS NOT NULL");
        while ($row = $result->fetch_assoc()) {
            $map[(int)$row['id']] = $row['name'];
        }
        return $map;
    }

    /**
     * Get cellvalue abbreviations mapping.
     */
    public function getCellValueAbbrs(): array {
        $map = [];
        $result = $this->conn->query("SELECT id, abbr FROM cellvalue");
        while ($row = $result->fetch_assoc()) {
            $map[(int)$row['id']] = $row['abbr'];
        }
        return $map;
    }

    /**
     * Get IDs for exclusive cellvalue abbreviations (e.g. 'hd', 'vd').
     * These are shift types that only one user may hold on any given day.
     *
     * @param  string[] $abbrs  Abbreviations to look up (lowercase, must match cellvalue.abbr)
     * @return array            [ abbr => id, ... ]
     */
    public function getExclusiveCellValueIds(array $abbrs): array {
        if (empty($abbrs)) return [];
        $placeholders = implode(',', array_fill(0, count($abbrs), '?'));
        $stmt = $this->conn->prepare("SELECT id, abbr FROM cellvalue WHERE abbr IN ({$placeholders})");
        $types = str_repeat('s', count($abbrs));
        $stmt->bind_param($types, ...$abbrs);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[$row['abbr']] = (int)$row['id'];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Get shift cellvalue IDs (dienstart > 0, abwesend = 0).
     */
    public function getShiftCellValues(): array {
        $map = [];
        $result = $this->conn->query("SELECT id, abbr FROM cellvalue WHERE dienstart > 0 AND abwesend = 0");
        while ($row = $result->fetch_assoc()) {
            $map[(int)$row['id']] = $row['abbr'];
        }
        return $map;
    }

    /**
     * Get valid absence types with cellvalueid mappings.
     */
    public function getValidAbsenceMappings(): array {
        $map = [];
        $result = $this->conn->query("SELECT id, symbol, cellvalueid FROM loga_absencelist WHERE cellvalueid IS NOT NULL");
        while ($row = $result->fetch_assoc()) {
            $map[(int)$row['id']] = (int)$row['cellvalueid'];
        }
        return $map;
    }

    /**
     * Get all absence type info.
     */
    public function getAllAbsenceTypes(): array {
        $map = [];
        $result = $this->conn->query("SELECT id, symbol, cellvalueid FROM loga_absencelist");
        while ($row = $result->fetch_assoc()) {
            $map[(int)$row['id']] = [
                'symbol'      => $row['symbol'],
                'cellvalueid' => $row['cellvalueid'],
            ];
        }
        return $map;
    }

    /**
     * Get shift-to-cellvalue mapping from loga_shiftlist.
     */
    public function getShiftToCellvalueMap(): array {
        $map = [];
        $result = $this->conn->query("SELECT id, shortcut, cellvalueid FROM loga_shiftlist WHERE cellvalueid IS NOT NULL");
        while ($row = $result->fetch_assoc()) {
            $map[(int)$row['id']] = [
                'cellvalueid' => (int)$row['cellvalueid'],
                'shortcut'    => $row['shortcut'],
            ];
        }
        return $map;
    }

    /**
     * Call internal DienstPlan API (for updating calendar entries).
     */
    public function callInternalApi(string $action, array $params, bool $isApiCall = false): array {
        if ($isApiCall && function_exists('handleUpdateCalendar')) {
            // Direct call when running inside api.php context
            $_POST['optionData'] = $params['optionData'];
            $_POST['targetDatum'] = $params['targetDatum'];
            $_POST['targetPerson'] = $params['targetPerson'];
            // Split (Dienstsplit) metadata — optional
            foreach (['splitId', 'splitOrder', 'timeFrom', 'timeTo', 'endsNextDay'] as $splitKey) {
                $_POST[$splitKey] = $params[$splitKey] ?? null;
            }

            ob_start();
            handleUpdateCalendar();
            $output = ob_get_clean();
            $response = json_decode($output, true);

            if (!$response) {
                throw new \RuntimeException("Invalid API response from direct call");
            }
            return $response;
        }

        // HTTP call for standalone mode
        $payload = http_build_query(array_merge(['action' => $action], $params));

        $headers = "Content-Type: application/x-www-form-urlencoded\r\n";
        $sessionId = session_id();
        if ($sessionId) {
            $headers .= 'Cookie: ' . session_name() . '=' . $sessionId . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => $headers,
                'content'       => $payload,
                'timeout'       => 60,
                'ignore_errors' => true,
            ],
        ]);

        $apiUrl = $this->resolveApiUrl();
        $response = @file_get_contents($apiUrl, false, $context);

        if ($response === false) {
            throw new \RuntimeException("Failed to reach DienstPlan API");
        }

        $decoded = json_decode($response, true);
        if ($decoded === null) {
            throw new \RuntimeException("Invalid JSON from API: " . substr($response, 0, 200));
        }

        if (isset($decoded['error'])) {
            throw new \RuntimeException($decoded['error']);
        }

        return $decoded;
    }

    /**
     * Resolve the internal API URL.
     */
    private function resolveApiUrl(): string {
        static $url = null;
        if ($url !== null) return $url;

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $scriptDir = rtrim(str_replace('\\', '/', $scriptDir), '/');
        $basePath = preg_replace('#/loga$#', '', $scriptDir);
        $url = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath . '/api.php';

        return $url;
    }
}
