<?php
/**
 * LOGA Portal - Absence Puller
 * 
 * Pulls absence data from processed LOGA cache into the local DienstPlan database.
 * Maps LOGA absence symbols to local cellvalue IDs via loga_absencelist.
 * Detects conflicts where an absence would overwrite an existing shift.
 * Supports preview mode and 4-strategy conflict resolution.
 * Also cleans up local absences no longer present in LOGA.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaProcessor.php';

class LogaAbsencePuller {
    private mysqli $conn;
    private LogaLogger $logger;
    private LogaProcessor $processor;

    public function __construct(mysqli $conn, ?LogaLogger $logger = null, ?LogaProcessor $processor = null) {
        $this->conn = $conn;
        $this->logger = $logger ?? LogaLogger::getInstance();
        $this->processor = $processor ?? new LogaProcessor($conn, $this->logger);
    }

    /**
     * Preview absence sync (dry run).
     * Returns conflicts (absences that would overwrite shifts) and safe changes.
     * 
     * @param array  $personsAbsences Processed absence data from LogaProcessor
     * @param string $dateFrom        Start date YYYY-MM-DD
     * @param string $dateTo          End date YYYY-MM-DD
     * @return array { conflicts: [...], safeChanges: int, unmapped: [...], summary: {...} }
     */
    public function preview(array $personsAbsences, string $dateFrom, string $dateTo): array {
        $this->logger->info("Preview absence pull for {$dateFrom} to {$dateTo}", 'AbsencePuller');

        $validAbsences = $this->processor->getValidAbsenceMappings();
        $allAbsenceTypes = $this->processor->getAllAbsenceTypes();
        $pnrToUid = $this->processor->getPnrToUidMap();
        $uidToName = $this->processor->getUidToNameMap();
        $shiftCellValues = $this->processor->getShiftCellValues();
        $cellValueAbbrs = $this->processor->getCellValueAbbrs();

        $conflicts = [];
        $safeChanges = 0;
        $matchCount = 0;
        $unmappedSymbols = [];

        foreach ($personsAbsences as $person) {
            $uid = $pnrToUid[$person['pnr']] ?? null;
            if (!$uid) continue;
            $userName = $uidToName[$uid] ?? "PNR {$person['pnr']}";

            foreach ($person['dates'] as $entry) {
                $symbolId = $entry['shortcutId'];

                // Check if absence type is mapped
                if (!isset($validAbsences[$symbolId])) {
                    $sym = $allAbsenceTypes[$symbolId]['symbol'] ?? 'unknown';
                    $unmappedSymbols[$sym] = true;
                    continue;
                }

                $date = $entry['date'];
                $absenceCellId = $validAbsences[$symbolId];
                $localValid = $this->getLocalPlanEntry($date, $uid);

                // Already matching
                if ($localValid !== null && (int)$localValid === $absenceCellId) {
                    $matchCount++;
                    continue;
                }

                // Check if existing value is a shift (would be overwritten)
                if ($localValid !== null && isset($shiftCellValues[(int)$localValid])) {
                    $absenceSymbol = $allAbsenceTypes[$symbolId]['symbol'] ?? 'unknown';
                    $conflicts[] = [
                        'uid'            => $uid,
                        'date'           => $date,
                        'userName'       => $userName,
                        'currentShift'   => $shiftCellValues[(int)$localValid],
                        'currentShiftId' => (int)$localValid,
                        'absenceSymbol'  => $absenceSymbol,
                        'absenceCellId'  => $absenceCellId,
                        'key'            => $uid . '|' . $date,
                    ];
                } else {
                    $safeChanges++;
                }
            }
        }

        // Count local absences that would be deleted (no longer in LOGA)
        $orphanedCount = $this->countOrphanedLocalAbsences($personsAbsences, $pnrToUid, $dateFrom, $dateTo);

        $this->logger->info(
            "Preview complete: " . count($conflicts) . " conflicts, {$safeChanges} safe changes, "
            . "{$matchCount} matches, {$orphanedCount} orphaned, " . count($unmappedSymbols) . " unmapped",
            'AbsencePuller'
        );

        return [
            'conflicts'   => $conflicts,
            'safeChanges' => $safeChanges,
            'matches'     => $matchCount,
            'orphaned'    => $orphanedCount,
            'unmapped'    => array_keys($unmappedSymbols),
            'summary'     => [
                'totalConflicts' => count($conflicts),
                'safeChanges'    => $safeChanges,
                'matches'        => $matchCount,
                'orphaned'       => $orphanedCount,
                'unmapped'       => count($unmappedSymbols),
            ],
        ];
    }

    /**
     * Apply absence pull with conflict resolution.
     * 
     * @param array  $personsAbsences Processed absence data
     * @param string $dateFrom        Start date YYYY-MM-DD
     * @param string $dateTo          End date YYYY-MM-DD
     * @param string $strategy        sync-clean-only|keep-local-all|keep-remote-all|per-conflict
     * @param array  $resolutions     Per-conflict: ['uid|date' => 'keep-local'|'keep-remote'|'skip']
     * @param bool   $isApiCall       Running inside API context
     * @return array Result summary
     */
    public function apply(
        array $personsAbsences,
        string $dateFrom,
        string $dateTo,
        string $strategy = 'sync-clean-only',
        array $resolutions = [],
        bool $isApiCall = false
    ): array {
        $this->logger->info("Applying absence pull ({$strategy}) for {$dateFrom} to {$dateTo}", 'AbsencePuller');

        $validAbsences = $this->processor->getValidAbsenceMappings();
        $allAbsenceTypes = $this->processor->getAllAbsenceTypes();
        $pnrToUid = $this->processor->getPnrToUidMap();
        $uidToName = $this->processor->getUidToNameMap();
        $shiftCellValues = $this->processor->getShiftCellValues();
        $cellValueAbbrs = $this->processor->getCellValueAbbrs();

        $counts = [
            'inserted'  => 0,
            'updated'   => 0,
            'deleted'   => 0,
            'skipped'   => 0,
            'keptLocal' => 0,
            'unmapped'  => 0,
            'errors'    => 0,
        ];

        foreach ($personsAbsences as $person) {
            $uid = $pnrToUid[$person['pnr']] ?? null;
            if (!$uid) continue;
            $userName = $uidToName[$uid] ?? "PNR {$person['pnr']}";

            foreach ($person['dates'] as $entry) {
                $symbolId = $entry['shortcutId'];

                if (!isset($validAbsences[$symbolId])) {
                    $counts['unmapped']++;
                    continue;
                }

                $date = $entry['date'];
                $absenceCellId = $validAbsences[$symbolId];
                $localValid = $this->getLocalPlanEntry($date, $uid);

                // Already matching
                if ($localValid !== null && (int)$localValid === $absenceCellId) {
                    $counts['skipped']++;
                    continue;
                }

                // Check if overwriting a shift
                $isConflict = ($localValid !== null && isset($shiftCellValues[(int)$localValid]));

                if ($isConflict) {
                    $key = $uid . '|' . $date;
                    $action = $this->resolveConflict($strategy, $key, $resolutions);

                    if ($action === 'keep-local') {
                        $counts['keptLocal']++;
                        $shiftAbbr = $shiftCellValues[(int)$localValid];
                        $this->logger->info("Keeping shift '{$shiftAbbr}' for {$userName} on {$date}", 'AbsencePuller');
                        continue;
                    }
                    if ($action === 'skip') {
                        $counts['skipped']++;
                        continue;
                    }

                    // keep-remote → overwrite
                    $shiftAbbr = $shiftCellValues[(int)$localValid];
                    $absSymbol = $allAbsenceTypes[$symbolId]['symbol'] ?? '?';
                    $this->logger->info("Overwriting shift '{$shiftAbbr}' with absence '{$absSymbol}' for {$userName} on {$date}", 'AbsencePuller');
                }

                // Apply absence
                $result = $this->applyPlanEntry($uid, $date, $absenceCellId, $isApiCall);
                if ($result) {
                    $op = strtoupper($result['operation'] ?? '');
                    if ($op === 'INSERT') $counts['inserted']++;
                    elseif ($op === 'UPDATE') $counts['updated']++;
                    else $counts['skipped']++;

                    $absSymbol = $allAbsenceTypes[$symbolId]['symbol'] ?? '?';
                    $absType = $entry['type'] ?? 'absence';
                    $this->logger->info("Applied {$absType}: {$userName} on {$date} as '{$absSymbol}'", 'AbsencePuller');
                } else {
                    $counts['errors']++;
                }
            }
        }

        // Cleanup local absences not in LOGA
        $deletedCount = $this->cleanupLocalAbsences($personsAbsences, $pnrToUid, $uidToName, $dateFrom, $dateTo);
        $counts['deleted'] = $deletedCount;

        // Log unmapped absence types
        $this->logUnmappedTypes($allAbsenceTypes);

        $this->logger->info(
            "Absence pull complete: {$counts['inserted']} inserted, {$counts['updated']} updated, "
            . "{$counts['deleted']} deleted, {$counts['skipped']} skipped, "
            . "{$counts['keptLocal']} kept local, {$counts['unmapped']} unmapped, {$counts['errors']} errors",
            'AbsencePuller'
        );

        return $counts;
    }

    // ─── Internal Helpers ───────────────────────────────────────────────────

    private function getLocalPlanEntry(string $date, int $uid): ?int {
        $stmt = $this->conn->prepare("SELECT valid FROM plan WHERE datum = ? AND uid = ?");
        $stmt->bind_param("si", $date, $uid);
        $stmt->execute();
        $stmt->store_result();

        $valid = null;
        if ($stmt->num_rows > 0) {
            $stmt->bind_result($valid);
            $stmt->fetch();
        }
        $stmt->free_result();
        $stmt->close();

        return $valid !== null ? (int)$valid : null;
    }

    private function resolveConflict(string $strategy, string $key, array $resolutions): string {
        return match ($strategy) {
            'keep-local-all'  => 'keep-local',
            'keep-remote-all' => 'keep-remote',
            'per-conflict'    => $resolutions[$key] ?? 'skip',
            default           => 'skip', // sync-clean-only
        };
    }

    /**
     * Count orphaned local absences (in DB but not in LOGA).
     */
    private function countOrphanedLocalAbsences(array $personsAbsences, array $pnrToUid, string $dateFrom, string $dateTo): int {
        // Build LOGA absence lookup
        $logaAbsences = [];
        foreach ($personsAbsences as $person) {
            $uid = $pnrToUid[$person['pnr']] ?? null;
            if (!$uid) continue;
            foreach ($person['dates'] as $entry) {
                $logaAbsences[$uid . '|' . $entry['date']] = true;
            }
        }

        // Query local absence entries
        $uidValues = array_values($pnrToUid);
        if (empty($uidValues)) return 0;

        $uidPlaceholders = implode(',', array_fill(0, count($uidValues), '?'));
        $query = "SELECT p.uid, p.datum FROM plan p JOIN cellvalue cv ON p.valid = cv.id WHERE p.datum BETWEEN ? AND ? AND cv.abwesend = 1 AND p.uid IN ({$uidPlaceholders})";

        $stmt = $this->conn->prepare($query);
        $types = 'ss' . str_repeat('i', count($uidValues));
        $params = array_merge([$dateFrom, $dateTo], $uidValues);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $orphanedCount = 0;
        while ($row = $result->fetch_assoc()) {
            $key = $row['uid'] . '|' . $row['datum'];
            if (!isset($logaAbsences[$key])) {
                $orphanedCount++;
            }
        }
        $stmt->close();

        return $orphanedCount;
    }

    /**
     * Delete local absences that no longer exist in LOGA.
     * Uses direct SQL DELETE + plan_history INSERT.
     */
    private function cleanupLocalAbsences(
        array $personsAbsences,
        array $pnrToUid,
        array $uidToName,
        string $dateFrom,
        string $dateTo
    ): int {
        // Build LOGA absence lookup
        $logaAbsences = [];
        foreach ($personsAbsences as $person) {
            $uid = $pnrToUid[$person['pnr']] ?? null;
            if (!$uid) continue;
            foreach ($person['dates'] as $entry) {
                $logaAbsences[$uid . '|' . $entry['date']] = true;
            }
        }

        // Find local absence entries not in LOGA
        $uidValues = array_values($pnrToUid);
        if (empty($uidValues)) return 0;

        $uidPlaceholders = implode(',', array_fill(0, count($uidValues), '?'));
        $query = "SELECT p.id, p.datum, p.uid, p.valid FROM plan p JOIN cellvalue cv ON p.valid = cv.id WHERE p.datum BETWEEN ? AND ? AND cv.abwesend = 1 AND p.uid IN ({$uidPlaceholders})";

        $stmt = $this->conn->prepare($query);
        $types = 'ss' . str_repeat('i', count($uidValues));
        $params = array_merge([$dateFrom, $dateTo], $uidValues);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $toDelete = [];
        while ($row = $result->fetch_assoc()) {
            $key = $row['uid'] . '|' . $row['datum'];
            if (!isset($logaAbsences[$key])) {
                $toDelete[] = $row;
            }
        }
        $stmt->close();

        if (empty($toDelete)) return 0;

        $this->logger->info("Found " . count($toDelete) . " local absences to delete (not in LOGA)", 'AbsencePuller');

        $changedBy = $_SESSION['member_id'] ?? ($_SESSION['uid'] ?? 1);
        $deleteStmt = $this->conn->prepare("DELETE FROM plan WHERE datum = ? AND uid = ? AND valid = ?");
        $histStmt = $this->conn->prepare(
            "INSERT INTO plan_history (plan_id, datum, uid, old_value, new_value, action_type, changed_by) VALUES (?, ?, ?, ?, NULL, 'DELETE', ?)"
        );

        $deletedCount = 0;
        foreach ($toDelete as $entry) {
            try {
                $deleteStmt->bind_param("sii", $entry['datum'], $entry['uid'], $entry['valid']);
                $deleteStmt->execute();

                if ($deleteStmt->affected_rows > 0) {
                    $deletedCount++;
                    $histStmt->bind_param("isiii", $entry['id'], $entry['datum'], $entry['uid'], $entry['valid'], $changedBy);
                    $histStmt->execute();

                    $userName = $uidToName[$entry['uid']] ?? "UID {$entry['uid']}";
                    $this->logger->info("Deleted absence: {$userName} on {$entry['datum']} (not in LOGA)", 'AbsencePuller');
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to delete absence UID {$entry['uid']} on {$entry['datum']}: " . $e->getMessage(), 'AbsencePuller');
            }
        }

        $deleteStmt->close();
        $histStmt->close();

        return $deletedCount;
    }

    /**
     * Apply a single plan entry via internal API.
     */
    private function applyPlanEntry(int $uid, string $date, int $cellValueId, bool $isApiCall): ?array {
        try {
            return $this->processor->callInternalApi('update_calendar', [
                'optionData'   => $cellValueId,
                'targetDatum'  => $date,
                'targetPerson' => $uid,
            ], $isApiCall);
        } catch (\Exception $e) {
            $this->logger->error("Failed to apply plan entry for UID {$uid} on {$date}: " . $e->getMessage(), 'AbsencePuller');
            return null;
        }
    }

    /**
     * Log unmapped absence types.
     */
    private function logUnmappedTypes(array $allAbsenceTypes): void {
        $unmapped = [];
        foreach ($allAbsenceTypes as $id => $type) {
            if ($type['cellvalueid'] === null) {
                $unmapped[] = $type['symbol'];
            }
        }
        if (!empty($unmapped)) {
            $this->logger->info("Unmapped absence types needing cellvalueid: " . implode(', ', $unmapped), 'AbsencePuller');
        }
    }
}
