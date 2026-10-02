<?php
/**
 * LOGA Portal - Shift Puller
 * 
 * Pulls shift data from processed LOGA cache into the local DienstPlan database.
 * Compares LOGA shifts with local plan entries, detects differences,
 * supports preview mode and 4-strategy conflict resolution:
 *   1) sync-clean-only  — skip any conflicts, apply only non-conflicting
 *   2) keep-local-all   — always keep local values
 *   3) keep-remote-all  — always overwrite with LOGA values
 *   4) per-conflict      — apply per-item resolution from UI
 * 
 * Also handles deletion detection (local shifts not in LOGA).
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaProcessor.php';

class LogaShiftPuller {
    private mysqli $conn;
    private LogaLogger $logger;
    private LogaProcessor $processor;

    public function __construct(mysqli $conn, ?LogaLogger $logger = null, ?LogaProcessor $processor = null) {
        $this->conn = $conn;
        $this->logger = $logger ?? LogaLogger::getInstance();
        $this->processor = $processor ?? new LogaProcessor($conn, $this->logger);
    }

    /**
     * Preview shift differences (dry run).
     * Returns an array of differences for the UI conflict resolution panel.
     * 
     * @param array  $personsShifts Processed shift data from LogaProcessor
     * @param string $dateFrom      Start date YYYY-MM-DD
     * @param string $dateTo        End date YYYY-MM-DD
     * @return array { differences: [...], summary: {...} }
     */
    public function preview(array $personsShifts, string $dateFrom, string $dateTo): array {
        $this->logger->info("Preview shift pull for {$dateFrom} to {$dateTo}", 'ShiftPuller');

        $shiftToCellvalue = $this->processor->getShiftToCellvalueMap();
        $pnrToUid = $this->processor->getPnrToUidMap();
        $uidToName = $this->processor->getUidToNameMap();
        $cellValueAbbrs = $this->processor->getCellValueAbbrs();

        $differences = [];
        $matchCount = 0;

        // Check each LOGA shift against local DB
        foreach ($personsShifts as $person) {
            $uid = $pnrToUid[$person['pnr']] ?? null;
            if (!$uid) continue;
            $userName = $uidToName[$uid] ?? "PNR {$person['pnr']}";

            // Group shifts by date: take first mapped cellvalue per date
            $dateShifts = $this->groupShiftsByDate($person['dates'], $shiftToCellvalue);

            foreach ($dateShifts as $date => $shiftInfo) {
                $logaCellId = (int)$shiftInfo['cellvalueid'];
                $localRow = $this->getLocalPlanRow($date, $uid);
                $localValid = $localRow !== null ? (int)$localRow['valid'] : null;

                if ($localValid !== null && $localValid === $logaCellId && $this->splitMetaMatches($localRow, $shiftInfo)) {
                    $matchCount++;
                    continue;
                }

                $localAbbr = ($localValid !== null) ? ($cellValueAbbrs[$localValid] ?? '-') : '-';
                $cloudAbbr = $cellValueAbbrs[$logaCellId] ?? $shiftInfo['shortcut'];

                $differences[] = [
                    'uid'         => $uid,
                    'date'        => $date,
                    'userName'    => $userName,
                    'cloudShift'  => $cloudAbbr,
                    'cloudCellId' => $logaCellId,
                    'localShift'  => $localAbbr,
                    'localCellId' => $localValid,
                    'key'         => $uid . '|' . $date,
                    'action'      => ($localValid !== null && $localValid === $logaCellId)
                                        ? 'split-update'
                                        : (($localValid !== null) ? 'update' : 'insert'),
                    'splitId'     => $shiftInfo['splitId'] ?? null,
                    'splitOrder'  => $shiftInfo['splitOrder'] ?? null,
                    'timeFrom'    => $shiftInfo['timeFrom'] ?? null,
                    'timeTo'      => $shiftInfo['timeTo'] ?? null,
                    'endsNextDay' => !empty($shiftInfo['endsNextDay']),
                ];
            }
        }

        // Deletion detection: local shifts no longer in LOGA
        $deletions = $this->detectDeletions($personsShifts, $pnrToUid, $uidToName, $cellValueAbbrs, $shiftToCellvalue, $dateFrom, $dateTo);
        $differences = array_merge($differences, $deletions);

        $this->logger->info(
            "Preview complete: " . count($differences) . " differences, {$matchCount} matches",
            'ShiftPuller'
        );

        return [
            'differences' => $differences,
            'summary'     => [
                'total'        => count($differences),
                'matches'      => $matchCount,
                'inserts'      => count(array_filter($differences, fn($d) => ($d['action'] ?? '') === 'insert')),
                'updates'      => count(array_filter($differences, fn($d) => in_array(($d['action'] ?? ''), ['update', 'split-update'], true))),
                'splitUpdates' => count(array_filter($differences, fn($d) => ($d['action'] ?? '') === 'split-update')),
                'deletions'    => count(array_filter($differences, fn($d) => ($d['action'] ?? '') === 'delete')),
            ],
        ];
    }

    /**
     * Apply shift pull with a given conflict resolution strategy.
     * 
     * @param array  $personsShifts Processed shift data from LogaProcessor
     * @param string $dateFrom      Start date YYYY-MM-DD
     * @param string $dateTo        End date YYYY-MM-DD
     * @param string $strategy      One of: sync-clean-only, keep-local-all, keep-remote-all, per-conflict
     * @param array  $resolutions   For per-conflict strategy: ['uid|date' => 'keep-local'|'keep-remote'|'skip']
     * @param bool   $isApiCall     Whether running inside API context (direct function call)
     * @return array Result summary
     */
    public function apply(
        array $personsShifts,
        string $dateFrom,
        string $dateTo,
        string $strategy = 'sync-clean-only',
        array $resolutions = [],
        bool $isApiCall = false
    ): array {
        $this->logger->info("Applying shift pull ({$strategy}) for {$dateFrom} to {$dateTo}", 'ShiftPuller');

        $shiftToCellvalue = $this->processor->getShiftToCellvalueMap();
        $pnrToUid = $this->processor->getPnrToUidMap();
        $uidToName = $this->processor->getUidToNameMap();
        $cellValueAbbrs = $this->processor->getCellValueAbbrs();

        // ── Exclusive-cellvalue enforcement ─────────────────────────────────
        // 'hd' and 'vd' may be held by at most ONE user per day.
        // This rule cannot be bypassed by any strategy.
        $exclusiveIds   = array_values($this->processor->getExclusiveCellValueIds(['hd', 'vd']));
        // ownership map:  "cellvalueid|date" => uid (who currently owns it)
        $exclusiveOwners = $this->loadExclusiveOwnership($exclusiveIds, $dateFrom, $dateTo);
        // ────────────────────────────────────────────────────────────────────

        $counts = [
            'inserted'   => 0,
            'updated'    => 0,
            'deleted'    => 0,
            'skipped'    => 0,
            'keptLocal'  => 0,
            'errors'     => 0,
        ];

        // ── Phase 1: Deletions first ─────────────────────────────────────────
        // Process removals before inserts so that exclusive slots freed by a
        // deletion are immediately available for the incoming insert on the
        // same date (e.g. user A loses 'hd', user B gains 'hd').
        $deletionResult = $this->applyDeletions($personsShifts, $pnrToUid, $uidToName, $cellValueAbbrs, $shiftToCellvalue, $dateFrom, $dateTo, $strategy, $resolutions, $isApiCall);
        $counts['deleted']   += $deletionResult['deleted'];
        $counts['keptLocal'] += $deletionResult['keptLocal'];
        $counts['errors']    += $deletionResult['errors'];

        // Refresh ownership map: deletions may have freed exclusive slots
        if (!empty($deletionResult['deleted'])) {
            $exclusiveOwners = $this->loadExclusiveOwnership($exclusiveIds, $dateFrom, $dateTo);
        }

        // ── Phase 2: Inserts / Updates / Split metadata ──────────────────────
        // Build the work list first and process value-matching rows before
        // conflicts/inserts. This guarantees that an existing split owner is
        // updated with its split_id before a co-owner is inserted on the same
        // date, so the exclusive-cellvalue guard can recognise the shared split.
        $work = [];
        foreach ($personsShifts as $person) {
            $uid = $pnrToUid[$person['pnr']] ?? null;
            if (!$uid) continue;
            $userName = $uidToName[$uid] ?? "PNR {$person['pnr']}";

            $dateShifts = $this->groupShiftsByDate($person['dates'], $shiftToCellvalue);

            foreach ($dateShifts as $date => $shiftInfo) {
                $localRow = $this->getLocalPlanRow($date, $uid);
                $valueMatches = ($localRow !== null && (int)$localRow['valid'] === (int)$shiftInfo['cellvalueid']);
                $work[] = [
                    'uid'          => $uid,
                    'userName'     => $userName,
                    'date'         => $date,
                    'shiftInfo'    => $shiftInfo,
                    'localRow'     => $localRow,
                    'valueMatches' => $valueMatches,
                    'metaMatches'  => $valueMatches && $this->splitMetaMatches($localRow, $shiftInfo),
                ];
            }
        }
        // Stable sort (PHP 8): rows whose value already matches first.
        usort($work, fn($a, $b) => ($b['valueMatches'] ? 1 : 0) <=> ($a['valueMatches'] ? 1 : 0));

        foreach ($work as $item) {
            $uid          = $item['uid'];
            $userName     = $item['userName'];
            $date         = $item['date'];
            $shiftInfo    = $item['shiftInfo'];
            $localRow     = $item['localRow'];
            $logaCellId   = (int)$shiftInfo['cellvalueid'];
            $localValid   = $localRow !== null ? (int)$localRow['valid'] : null;

            // Already fully matching (value + split metadata)
            if ($item['metaMatches']) {
                $counts['skipped']++;
                continue;
            }

            $localAbbr = ($localValid !== null) ? ($cellValueAbbrs[$localValid] ?? '-') : '-';
            $cloudAbbr = $cellValueAbbrs[$logaCellId] ?? $shiftInfo['shortcut'];
            $key = $uid . '|' . $date;

            // Value unchanged, only split metadata differs → update in place.
            if ($item['valueMatches']) {
                $result = $this->applyPlanEntry($uid, $date, $logaCellId, $isApiCall, $shiftInfo);
                if ($result) {
                    $counts['updated']++;
                    $this->logger->info("Updated split metadata: {$userName} on {$date} - '{$cloudAbbr}'", 'ShiftPuller');
                    if (in_array($logaCellId, $exclusiveIds, true)) {
                        $exclusiveOwners[$logaCellId . '|' . $date] = ['uid' => $uid, 'splitId' => $shiftInfo['splitId'] ?? null];
                    }
                } else {
                    $counts['errors']++;
                }
                continue;
            }

            $isConflict = ($localValid !== null);

            // Resolve conflict based on strategy
            if ($isConflict) {
                $action = $this->resolveConflict($strategy, $key, $resolutions);
                if ($action === 'keep-local') {
                    $counts['keptLocal']++;
                    $this->logger->info("Keeping local shift '{$localAbbr}' for {$userName} on {$date}", 'ShiftPuller');
                    continue;
                }
                if ($action === 'skip') {
                    $counts['skipped']++;
                    continue;
                }
                // action === 'keep-remote' → fall through to apply
            }

            // ── Exclusive-cellvalue guard ────────────────────────────────
            // A duty may legitimately be covered by several persons when they are
            // parts of the same split (identical split_id).
            if (in_array($logaCellId, $exclusiveIds, true)) {
                $ownerKey = $logaCellId . '|' . $date;
                $owner = $exclusiveOwners[$ownerKey] ?? null;
                $sameSplit = $owner !== null
                    && !empty($shiftInfo['splitId'])
                    && !empty($owner['splitId'])
                    && $owner['splitId'] === $shiftInfo['splitId'];
                if ($owner !== null && (int)$owner['uid'] !== $uid && !$sameSplit) {
                    $ownerName = $uidToName[(int)$owner['uid']] ?? "UID {$owner['uid']}";
                    $this->logger->error(
                        "BLOCKED exclusive shift '{$cloudAbbr}' for {$userName} on {$date}: "
                        . "already held by {$ownerName} — rule cannot be overridden",
                        'ShiftPuller'
                    );
                    $counts['skipped']++;
                    continue;
                }
            }
            // ─────────────────────────────────────────────────────────────

            $result = $this->applyPlanEntry($uid, $date, $logaCellId, $isApiCall, $shiftInfo);
            if ($result) {
                $op = strtoupper($result['operation'] ?? '');
                if ($op === 'INSERT') $counts['inserted']++;
                elseif ($op === 'UPDATE') $counts['updated']++;
                else $counts['skipped']++;

                $actionLabel = ($op === 'INSERT') ? 'Inserted' : 'Updated';
                $this->logger->info("{$actionLabel} shift: {$userName} on {$date} - '{$localAbbr}' → '{$cloudAbbr}'", 'ShiftPuller');

                // Update in-run ownership so subsequent writes in this batch are also blocked
                if (in_array($logaCellId, $exclusiveIds, true)) {
                    $exclusiveOwners[$logaCellId . '|' . $date] = ['uid' => $uid, 'splitId' => $shiftInfo['splitId'] ?? null];
                }
            } else {
                $counts['errors']++;
            }
        }

        $this->logger->info(
            "Shift pull complete: {$counts['inserted']} inserted, {$counts['updated']} updated, "
            . "{$counts['deleted']} deleted, {$counts['skipped']} unchanged, "
            . "{$counts['keptLocal']} kept local, {$counts['errors']} errors",
            'ShiftPuller'
        );

        return $counts;
    }

    // ─── Internal Helpers ───────────────────────────────────────────────────

    /**
     * Group shifts by date, taking the first mapped cellvalue per date.
     * Split metadata (Dienstsplit) of the chosen shift is preserved. If a later
     * shift on the same day carries split data and the stored one does not, the
     * split-annotated shift wins.
     */
    private function groupShiftsByDate(array $dates, array $shiftToCellvalue): array {
        $dateShifts = [];
        foreach ($dates as $entry) {
            $shiftId = $entry['id'];
            if (!isset($shiftToCellvalue[$shiftId])) {
                continue;
            }
            $date = $entry['date'];
            $candidate = [
                'cellvalueid' => $shiftToCellvalue[$shiftId]['cellvalueid'],
                'shortcut'    => $shiftToCellvalue[$shiftId]['shortcut'],
                'splitId'     => $entry['splitId'] ?? null,
                'splitOrder'  => $entry['splitOrder'] ?? null,
                'timeFrom'    => $entry['timeFrom'] ?? null,
                'timeTo'      => $entry['timeTo'] ?? null,
                'endsNextDay' => !empty($entry['endsNextDay']) ? 1 : 0,
            ];

            if (!isset($dateShifts[$date])) {
                $dateShifts[$date] = $candidate;
            } elseif (!empty($candidate['splitId']) && empty($dateShifts[$date]['splitId'])) {
                $dateShifts[$date] = $candidate;
            }
        }
        return $dateShifts;
    }

    /**
     * Get the local plan row for a given date + uid, including split metadata.
     */
    private function getLocalPlanRow(string $date, int $uid): ?array {
        $stmt = $this->conn->prepare(
            "SELECT valid, split_id, split_order, time_from, time_to, ends_next_day "
            . "FROM plan WHERE datum = ? AND uid = ? LIMIT 1"
        );
        $stmt->bind_param("si", $date, $uid);
        $stmt->execute();
        $stmt->store_result();

        $row = null;
        if ($stmt->num_rows > 0) {
            $valid = null;
            $splitId = null;
            $splitOrder = null;
            $timeFrom = null;
            $timeTo = null;
            $endsNextDay = 0;
            $stmt->bind_result($valid, $splitId, $splitOrder, $timeFrom, $timeTo, $endsNextDay);
            $stmt->fetch();
            $row = [
                'valid'         => (int)$valid,
                'split_id'      => $splitId,
                'split_order'   => $splitOrder !== null ? (int)$splitOrder : null,
                'time_from'     => $timeFrom,
                'time_to'       => $timeTo,
                'ends_next_day' => (int)$endsNextDay,
            ];
        }

        $stmt->free_result();
        $stmt->close();

        return $row;
    }

    /**
     * Compare the split metadata of a local plan row with an incoming LOGA shift.
     */
    private function splitMetaMatches(?array $localRow, array $shiftInfo): bool {
        if ($localRow === null) {
            return false;
        }

        $localSplit = $localRow['split_id'] ?? null;
        $incoming   = $shiftInfo['splitId'] ?? null;

        // Neither side is a split: equality of the value is enough. Times are
        // not tracked for regular duties, so do not treat them as a difference.
        if (($localSplit === null || $localSplit === '') && ($incoming === null || $incoming === '')) {
            return true;
        }

        if ((string)($localSplit ?? '') !== (string)($incoming ?? '')) {
            return false;
        }

        $localOrder    = $localRow['split_order'] !== null ? (int)$localRow['split_order'] : null;
        $incomingOrder = ($shiftInfo['splitOrder'] ?? null) !== null ? (int)$shiftInfo['splitOrder'] : null;
        if ($localOrder !== $incomingOrder) {
            return false;
        }

        if (LogaProcessor::normalizeTime($localRow['time_from'] ?? null) !== ($shiftInfo['timeFrom'] ?? null)) {
            return false;
        }
        if (LogaProcessor::normalizeTime($localRow['time_to'] ?? null) !== ($shiftInfo['timeTo'] ?? null)) {
            return false;
        }

        $localEnds    = !empty($localRow['ends_next_day']) ? 1 : 0;
        $incomingEnds = !empty($shiftInfo['endsNextDay']) ? 1 : 0;
        return $localEnds === $incomingEnds;
    }

    /**
     * Resolve a conflict based on strategy.
     * Returns: 'keep-local', 'keep-remote', or 'skip'
     */
    private function resolveConflict(string $strategy, string $key, array $resolutions): string {
        return match ($strategy) {
            'keep-local-all'  => 'keep-local',
            'keep-remote-all' => 'keep-remote',
            'per-conflict'    => $resolutions[$key] ?? 'skip',
            default           => 'skip', // sync-clean-only
        };
    }

    /**
     * Detect local shifts that are no longer in LOGA (for deletion).
     */
    private function detectDeletions(
        array $personsShifts,
        array $pnrToUid,
        array $uidToName,
        array $cellValueAbbrs,
        array $shiftToCellvalue,
        string $dateFrom,
        string $dateTo
    ): array {
        $deletions = [];

        if ($dateFrom === '' || $dateTo === '') return $deletions;

        // Collect LOGA-managed cellvalue IDs
        $logaManagedCellvalues = array_unique(array_map(fn($s) => $s['cellvalueid'], $shiftToCellvalue));
        if (empty($logaManagedCellvalues)) return $deletions;

        // Build set of LOGA dates per uid
        $logaDatesPerUid = [];
        foreach ($personsShifts as $person) {
            $uid = $pnrToUid[$person['pnr']] ?? null;
            if (!$uid) continue;
            if (!isset($logaDatesPerUid[$uid])) $logaDatesPerUid[$uid] = [];
            foreach ($person['dates'] as $entry) {
                $shiftId = $entry['id'];
                if (isset($shiftToCellvalue[$shiftId])) {
                    $logaDatesPerUid[$uid][$entry['date']] = true;
                }
            }
        }

        // Query local plan entries with LOGA-managed cell values
        $allLogaUids = array_values($pnrToUid);
        if (empty($allLogaUids)) return $deletions;

        $uidPlaceholders = implode(',', array_fill(0, count($allLogaUids), '?'));
        $cvPlaceholders = implode(',', array_fill(0, count($logaManagedCellvalues), '?'));
        $query = "SELECT uid, datum, valid FROM plan WHERE uid IN ({$uidPlaceholders}) AND datum BETWEEN ? AND ? AND valid IN ({$cvPlaceholders})";

        $stmt = $this->conn->prepare($query);
        $types = str_repeat('i', count($allLogaUids)) . 'ss' . str_repeat('i', count($logaManagedCellvalues));
        $params = array_merge($allLogaUids, [$dateFrom, $dateTo], $logaManagedCellvalues);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $uid = (int)$row['uid'];
            $localDate = $row['datum'];
            $localCellId = (int)$row['valid'];
            $userName = $uidToName[$uid] ?? "UID {$uid}";

            // If LOGA has no shift for this user on this date → mark for deletion
            if (!isset($logaDatesPerUid[$uid][$localDate])) {
                $localAbbr = $cellValueAbbrs[$localCellId] ?? '-';
                $deletions[] = [
                    'uid'         => $uid,
                    'date'        => $localDate,
                    'userName'    => $userName,
                    'cloudShift'  => '-',
                    'cloudCellId' => 0,
                    'localShift'  => $localAbbr,
                    'localCellId' => $localCellId,
                    'key'         => $uid . '|' . $localDate,
                    'action'      => 'delete',
                ];
            }
        }

        $stmt->close();
        return $deletions;
    }

    /**
     * Apply deletions based on strategy.
     */
    private function applyDeletions(
        array $personsShifts,
        array $pnrToUid,
        array $uidToName,
        array $cellValueAbbrs,
        array $shiftToCellvalue,
        string $dateFrom,
        string $dateTo,
        string $strategy,
        array $resolutions,
        bool $isApiCall
    ): array {
        $counts = ['deleted' => 0, 'keptLocal' => 0, 'errors' => 0];

        $deletions = $this->detectDeletions($personsShifts, $pnrToUid, $uidToName, $cellValueAbbrs, $shiftToCellvalue, $dateFrom, $dateTo);

        foreach ($deletions as $diff) {
            $key = $diff['key'];
            $action = $this->resolveConflict($strategy, $key, $resolutions);

            if ($action === 'keep-local') {
                $counts['keptLocal']++;
                $this->logger->info("Keeping local shift '{$diff['localShift']}' for {$diff['userName']} on {$diff['date']}", 'ShiftPuller');
                continue;
            }
            if ($action === 'skip') {
                // Deletions are always conflicts (local exists, remote doesn't).
                // 'skip' means: no resolution was provided (per-conflict with no UI input)
                // or strategy is sync-clean-only — in both cases, leave local untouched.
                $counts['keptLocal']++;
                continue;
            }

            // Delete (set optionData=0)
            $result = $this->applyPlanEntry($diff['uid'], $diff['date'], 0, $isApiCall);
            if ($result) {
                $counts['deleted']++;
                $this->logger->info("Deleted shift: {$diff['userName']} on {$diff['date']} - '{$diff['localShift']}' → '-' (not in LOGA)", 'ShiftPuller');
            } else {
                $counts['errors']++;
            }
        }

        return $counts;
    }

    /**
     * Pre-load current exclusive-cellvalue ownership for a date range.
     * Returns [ "cellvalueid|date" => ['uid' => int, 'splitId' => ?string] ]
     */
    private function loadExclusiveOwnership(array $exclusiveIds, string $dateFrom, string $dateTo): array {
        if (empty($exclusiveIds)) return [];

        $placeholders = implode(',', array_fill(0, count($exclusiveIds), '?'));
        $stmt = $this->conn->prepare(
            "SELECT uid, datum, valid, split_id FROM plan "
            . "WHERE datum BETWEEN ? AND ? AND valid IN ({$placeholders})"
        );
        $types = 'ss' . str_repeat('i', count($exclusiveIds));
        $params = array_merge([$dateFrom, $dateTo], $exclusiveIds);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $key = (int)$row['valid'] . '|' . $row['datum'];
            $map[$key] = [
                'uid'     => (int)$row['uid'],
                'splitId' => $row['split_id'] ?? null,
            ];
        }
        $stmt->close();
        return $map;
    }

    /**
     * Apply a single plan entry via internal API.
     */
    private function applyPlanEntry(int $uid, string $date, int $cellValueId, bool $isApiCall, array $shiftInfo = []): ?array {
        try {
            return $this->processor->callInternalApi('update_calendar', [
                'optionData'   => $cellValueId,
                'targetDatum'  => $date,
                'targetPerson' => $uid,
                'splitId'      => $shiftInfo['splitId'] ?? null,
                'splitOrder'   => $shiftInfo['splitOrder'] ?? null,
                'timeFrom'     => $shiftInfo['timeFrom'] ?? null,
                'timeTo'       => $shiftInfo['timeTo'] ?? null,
                'endsNextDay'  => !empty($shiftInfo['endsNextDay']) ? 1 : 0,
            ], $isApiCall);
        } catch (\Exception $e) {
            $this->logger->error("Failed to apply plan entry for UID {$uid} on {$date}: " . $e->getMessage(), 'ShiftPuller');
            return null;
        }
    }
}
