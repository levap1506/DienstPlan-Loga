<?php
/**
 * LOGA Portal - Persons Manager
 * 
 * Manages the loga_persons table: upserts person data from LOGA fetches,
 * maps PNRs to local user IDs, tracks special users, and provides
 * query methods for the dashboard.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';

class LogaPersons {
    private mysqli $conn;
    private LogaLogger $logger;

    public function __construct(mysqli $conn, ?LogaLogger $logger = null) {
        $this->conn = $conn;
        $this->logger = $logger ?? LogaLogger::getInstance();
    }

    /**
     * Sync persons from a LOGA fetch result into loga_persons table.
     * Called after each fetch — upserts all discovered persons.
     * 
     * @param array $personsData  Raw persons data from LogaFetcher (the full response array)
     * @return array Summary: ['total' => int, 'inserted' => int, 'updated' => int]
     */
    public function syncFromFetchData(array $personsData): array {
        $inserted = 0;
        $updated = 0;

        // Pre-load local user PNR→ID map
        $localMap = $this->getLocalUserMap();
        $specialPnrs = array_keys(LOGA_SPECIAL_USERS);

        $stmt = $this->conn->prepare(
            "INSERT INTO loga_persons (pnr, full_name, valid_from, valid_to, local_user_id, is_special_user, shift_count, absence_count, last_seen_at, raw_data)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                full_name = VALUES(full_name),
                valid_from = VALUES(valid_from),
                valid_to = VALUES(valid_to),
                local_user_id = VALUES(local_user_id),
                is_special_user = VALUES(is_special_user),
                shift_count = VALUES(shift_count),
                absence_count = VALUES(absence_count),
                last_seen_at = NOW(),
                raw_data = VALUES(raw_data)"
        );

        if (!$stmt) {
            $this->logger->error("Failed to prepare loga_persons upsert: " . $this->conn->error, 'LogaPersons');
            return ['total' => count($personsData), 'inserted' => 0, 'updated' => 0];
        }

        foreach ($personsData as $person) {
            $pnr = $person['manAkPnrVertnr']['pnr'] ?? null;
            if (!$pnr) continue;

            $fullName = $person['fullName'] ?? null;

            // Extract validity dates (take first validity period)
            $validFrom = null;
            $validTo = null;
            if (!empty($person['valid']) && is_array($person['valid'])) {
                $firstValid = $person['valid'][0] ?? [];
                $validFrom = $firstValid['dateFrom'] ?? null;
                $validTo = $firstValid['dateTo'] ?? null;
            }

            // Map to local user
            $localUserId = $localMap[$pnr] ?? null;
            $isSpecial = in_array($pnr, $specialPnrs) ? 1 : 0;

            // Count shifts
            $shiftCount = 0;
            if (isset($person['shiftData']) && is_array($person['shiftData'])) {
                foreach ($person['shiftData'] as $dayData) {
                    if (isset($dayData['shifts']) && is_array($dayData['shifts'])) {
                        $shiftCount += count($dayData['shifts']);
                    }
                }
            }

            // Count absences
            $absenceCount = 0;
            if (isset($person['personAbsenceDataMap']) && is_array($person['personAbsenceDataMap'])) {
                $absenceCount = count($person['personAbsenceDataMap']);
            }

            // Store raw data (strip heavy shiftData/absenceData to keep it manageable)
            $rawForStorage = $person;
            // Keep shiftData summary only
            if (isset($rawForStorage['shiftData'])) {
                $rawForStorage['_shiftDataDays'] = count($rawForStorage['shiftData']);
                unset($rawForStorage['shiftData']);
            }
            if (isset($rawForStorage['personAbsenceDataMap'])) {
                $rawForStorage['_absenceDataCount'] = count($rawForStorage['personAbsenceDataMap']);
                // Keep absence keys (types) for reference
                $rawForStorage['_absenceTypes'] = array_keys($rawForStorage['personAbsenceDataMap']);
                unset($rawForStorage['personAbsenceDataMap']);
            }
            if (isset($rawForStorage['absenceRequestDataMap'])) {
                $rawForStorage['_absenceRequestCount'] = count($rawForStorage['absenceRequestDataMap']);
                unset($rawForStorage['absenceRequestDataMap']);
            }
            $rawJson = json_encode($rawForStorage, JSON_UNESCAPED_UNICODE);

            $stmt->bind_param(
                'ssssiiiis',
                $pnr,
                $fullName,
                $validFrom,
                $validTo,
                $localUserId,
                $isSpecial,
                $shiftCount,
                $absenceCount,
                $rawJson
            );

            if ($stmt->execute()) {
                if ($stmt->affected_rows === 1) {
                    $inserted++;
                } elseif ($stmt->affected_rows === 2) {
                    // ON DUPLICATE KEY UPDATE counts as 2 affected rows when data changed
                    $updated++;
                }
            } else {
                $this->logger->error("Failed to upsert person PNR {$pnr}: " . $stmt->error, 'LogaPersons');
            }
        }

        $stmt->close();

        $total = count($personsData);
        $this->logger->info(
            "Persons sync: {$total} total, {$inserted} inserted, {$updated} updated",
            'LogaPersons'
        );

        return [
            'total'    => $total,
            'inserted' => $inserted,
            'updated'  => $updated,
        ];
    }

    /**
     * Get all persons from the database.
     * 
     * @param string $search  Optional search filter (name or PNR)
     * @param string $filter  'all' | 'mapped' | 'unmapped' | 'special'
     * @param string $orderBy Column to order by
     * @return array
     */
    public function getAll(string $search = '', string $filter = 'all', string $orderBy = 'full_name'): array {
        $where = ['1=1'];
        $params = [];
        $types = '';

        if ($search !== '') {
            $where[] = "(full_name LIKE ? OR pnr LIKE ?)";
            $searchLike = "%{$search}%";
            $params[] = $searchLike;
            $params[] = $searchLike;
            $types .= 'ss';
        }

        switch ($filter) {
            case 'mapped':
                $where[] = "local_user_id IS NOT NULL";
                break;
            case 'unmapped':
                $where[] = "local_user_id IS NULL AND is_special_user = 0";
                break;
            case 'special':
                $where[] = "is_special_user = 1";
                break;
        }

        // Whitelist order column
        $validOrders = ['full_name', 'pnr', 'last_seen_at', 'shift_count', 'absence_count', 'valid_from', 'created_at'];
        if (!in_array($orderBy, $validOrders)) $orderBy = 'full_name';

        $sql = "SELECT id, pnr, full_name, valid_from, valid_to, local_user_id, is_special_user,
                       shift_count, absence_count, last_seen_at, created_at, updated_at
                FROM loga_persons
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$orderBy} ASC";

        if (!empty($params)) {
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
        } else {
            $result = $this->conn->query($sql);
        }

        $persons = [];
        while ($row = $result->fetch_assoc()) {
            $persons[] = $row;
        }

        return $persons;
    }

    /**
     * Get a single person's full record including raw_data.
     */
    public function getByPnr(string $pnr): ?array {
        $stmt = $this->conn->prepare(
            "SELECT * FROM loga_persons WHERE pnr = ? LIMIT 1"
        );
        $stmt->bind_param('s', $pnr);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row && $row['raw_data']) {
            $row['raw_data_decoded'] = json_decode($row['raw_data'], true);
        }

        return $row;
    }

    /**
     * Get aggregate statistics for the persons table.
     */
    public function getStats(): array {
        $sql = "SELECT 
                    COUNT(*) AS total,
                    SUM(local_user_id IS NOT NULL) AS mapped,
                    SUM(local_user_id IS NULL AND is_special_user = 0) AS unmapped,
                    SUM(is_special_user = 1) AS special,
                    SUM(shift_count) AS total_shifts,
                    SUM(absence_count) AS total_absences,
                    MAX(last_seen_at) AS last_sync_at,
                    MIN(valid_from) AS earliest_valid_from,
                    MAX(valid_to) AS latest_valid_to
                FROM loga_persons";
        $result = $this->conn->query($sql);
        return $result ? $result->fetch_assoc() : [];
    }

    /**
     * Get a merged PNR -> display data map from local users and synced LOGA names.
     *
     * @return array<string, array{pnr:string, loga_person_id:?int, local_user_id:?int, local_name:?string, loga_name:?string, display_name:string}>
     */
    public function getDisplayDataByPnr(): array {
        $map = [];

        $userResult = $this->conn->query(
            "SELECT CAST(pnr AS CHAR) AS pnr, id, name
             FROM user
             WHERE pnr IS NOT NULL AND pnr != ''
             ORDER BY (CURDATE() BETWEEN gueltigab AND gueltigbis) DESC,
                      gueltigbis DESC,
                      gueltigab DESC,
                      id DESC"
        );

        if ($userResult) {
            while ($row = $userResult->fetch_assoc()) {
                $pnr = (string)($row['pnr'] ?? '');
                if ($pnr === '' || isset($map[$pnr])) {
                    continue;
                }

                $localName = trim((string)($row['name'] ?? ''));
                $map[$pnr] = [
                    'pnr' => $pnr,
                    'loga_person_id' => null,
                    'local_user_id' => isset($row['id']) ? (int)$row['id'] : null,
                    'local_name' => ($localName !== '') ? $localName : null,
                    'loga_name' => null,
                    'display_name' => ($localName !== '') ? $localName : "PNR {$pnr}",
                ];
            }
        }

        if ($this->tableExists()) {
            $personResult = $this->conn->query(
                "SELECT id, pnr, full_name
                 FROM loga_persons
                 ORDER BY updated_at DESC, id DESC"
            );

            if ($personResult) {
                while ($row = $personResult->fetch_assoc()) {
                    $pnr = (string)($row['pnr'] ?? '');
                    if ($pnr === '') {
                        continue;
                    }

                    $logaName = trim((string)($row['full_name'] ?? ''));
                    if (!isset($map[$pnr])) {
                        $map[$pnr] = [
                            'pnr' => $pnr,
                            'loga_person_id' => isset($row['id']) ? (int)$row['id'] : null,
                            'local_user_id' => null,
                            'local_name' => null,
                            'loga_name' => ($logaName !== '') ? $logaName : null,
                            'display_name' => ($logaName !== '') ? $logaName : "PNR {$pnr}",
                        ];
                        continue;
                    }

                    $map[$pnr]['loga_person_id'] = isset($row['id']) ? (int)$row['id'] : null;
                    if ($logaName !== '') {
                        $map[$pnr]['loga_name'] = $logaName;
                        if (empty($map[$pnr]['local_name'])) {
                            $map[$pnr]['display_name'] = $logaName;
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Ensure that all supplied PNRs exist in loga_persons and return their IDs.
     *
     * @param array<string, array{full_name?:?string, local_name?:?string, loga_name?:?string, display_name?:?string}> $personSeeds
     * @return array<string, int>
     */
    public function ensurePersonIdsByPnr(array $personSeeds): array {
        if (!$this->tableExists() || empty($personSeeds)) {
            return [];
        }

        $normalizedSeeds = [];
        foreach ($personSeeds as $pnr => $seed) {
            $pnr = trim((string)$pnr);
            if ($pnr === '') {
                continue;
            }
            $normalizedSeeds[$pnr] = $seed;
        }

        if (empty($normalizedSeeds)) {
            return [];
        }

        $existingMap = $this->getPersonIdsByPnrs(array_keys($normalizedSeeds));
        $localUserMap = $this->getLocalUserMap();
        $specialPnrs = array_keys(LOGA_SPECIAL_USERS);

        $insertStmt = $this->conn->prepare(
            "INSERT INTO loga_persons (
                pnr, full_name, valid_from, valid_to, local_user_id, is_special_user,
                shift_count, absence_count, last_seen_at, raw_data
            ) VALUES (?, ?, NULL, NULL, ?, ?, 0, 0, NULL, NULL)
            ON DUPLICATE KEY UPDATE
                full_name = COALESCE(loga_persons.full_name, VALUES(full_name)),
                local_user_id = COALESCE(loga_persons.local_user_id, VALUES(local_user_id)),
                is_special_user = VALUES(is_special_user)"
        );

        if (!$insertStmt) {
            $this->logger->error("Failed to prepare loga_persons ensure upsert: " . $this->conn->error, 'LogaPersons');
            return $existingMap;
        }

        foreach ($normalizedSeeds as $pnr => $seed) {
            if (isset($existingMap[$pnr])) {
                continue;
            }

            $fullName = trim((string)($seed['loga_name'] ?? $seed['full_name'] ?? $seed['display_name'] ?? $seed['local_name'] ?? ''));
            $fullName = ($fullName !== '') ? $fullName : null;
            $localUserId = $localUserMap[$pnr] ?? null;
            $isSpecial = in_array($pnr, $specialPnrs, true) ? 1 : 0;

            $insertStmt->bind_param('ssii', $pnr, $fullName, $localUserId, $isSpecial);
            if (!$insertStmt->execute()) {
                $this->logger->error("Failed to ensure loga_persons row for PNR {$pnr}: " . $insertStmt->error, 'LogaPersons');
            }
        }

        $insertStmt->close();

        return $this->getPersonIdsByPnrs(array_keys($normalizedSeeds));
    }

    /**
     * @param string[] $pnrs
     * @return array<string, int>
     */
    private function getPersonIdsByPnrs(array $pnrs): array {
        $pnrs = array_values(array_filter(array_map(static fn($pnr) => trim((string)$pnr), $pnrs), static fn($pnr) => $pnr !== ''));
        if (empty($pnrs)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($pnrs), '?'));
        $stmt = $this->conn->prepare(
            "SELECT id, pnr FROM loga_persons WHERE pnr IN ({$placeholders})"
        );

        if (!$stmt) {
            $this->logger->error("Failed to prepare loga_persons ID lookup: " . $this->conn->error, 'LogaPersons');
            return [];
        }

        $types = str_repeat('s', count($pnrs));
        $stmt->bind_param($types, ...$pnrs);
        $stmt->execute();
        $result = $stmt->get_result();

        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[(string)$row['pnr']] = (int)$row['id'];
        }
        $stmt->close();

        return $map;
    }

    /**
     * Get PNR→local user.id map from the user table.
     */
    private function getLocalUserMap(): array {
        $map = [];
        $result = $this->conn->query("SELECT id, pnr FROM user WHERE pnr IS NOT NULL AND pnr != ''");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $map[$row['pnr']] = (int)$row['id'];
            }
        }
        return $map;
    }

    /**
     * Check if the loga_persons table exists.
     */
    public function tableExists(): bool {
        $result = $this->conn->query("SHOW TABLES LIKE 'loga_persons'");
        return $result && $result->num_rows > 0;
    }
}
