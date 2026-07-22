<?php
/**
 * LOGA Portal - Shift Pusher
 * 
 * Pushes local DienstPlan shifts to LOGA.
 * Calculates expected shifts per user/date, diffs against current LOGA state,
 * and batches changes via the LOGA planshifts API.
 * 
 * Key logic:
 *   - O-suffix: workday→holiday permutation (1-4)
 *   - R-shift casing: r1/r2 lowercase, R3/R4 uppercase
 *   - Special users: hardcoded shifts (80/O/CA)
 *   - Base shift: from user_dienstart table (dienstart=2→O, else→A)
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaClient.php';
require_once __DIR__ . '/LogaAuth.php';

class LogaShiftPusher {
    private mysqli $conn;
    private LogaLogger $logger;
    private LogaClient $client;
    private LogaAuth $auth;

    private string $dateFrom;
    private string $dateTo;
    private array $holidays = [];
    private array $shiftIdMap = [];

    public function __construct(
        mysqli $conn,
        LogaClient $client,
        LogaAuth $auth,
        string $dateFrom,
        string $dateTo,
        ?LogaLogger $logger = null
    ) {
        $this->conn = $conn;
        $this->client = $client;
        $this->auth = $auth;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->logger = $logger ?? LogaLogger::getInstance();

        $this->loadHolidays();
        $this->loadShiftIdMap();
    }

    // ─── Public Interface ───────────────────────────────────────────────────

    /**
     * Preview mode: calculate all changes without sending to LOGA.
     * 
     * @param array $currentLogaData Current LOGA persons data (from fetcher)
     * @return array { changes: [...], summary: {...} }
     */
    public function preview(array $currentLogaData): array {
        $this->logger->info("Preview shift push for {$this->dateFrom} to {$this->dateTo}", 'ShiftPusher');

        $users = $this->getValidUsers();
        $currentShifts = $this->extractCurrentShifts($currentLogaData);

        $allChanges = [];
        $syncCount = 0;
        $skipCount = 0;

        foreach ($users as $user) {
            try {
                $changes = $this->calculateShiftChanges($user, $currentShifts[$user['pnr']] ?? []);
                if (empty($changes)) {
                    $skipCount++;
                    continue;
                }

                foreach ($changes as $change) {
                    $change['pnr'] = $user['pnr'];
                    $change['userName'] = $user['name'];
                    $allChanges[] = $change;
                }
                $syncCount++;
            } catch (\Exception $e) {
                $this->logger->error("Failed to calculate changes for user {$user['pnr']}: " . $e->getMessage(), 'ShiftPusher');
            }
        }

        $this->logger->info("Preview complete: {$syncCount} users with changes, {$skipCount} no changes", 'ShiftPusher');

        return [
            'changes' => $allChanges,
            'summary' => [
                'usersWithChanges' => $syncCount,
                'usersNoChanges'   => $skipCount,
                'totalChanges'     => count($allChanges),
                'totalAdds'        => array_sum(array_map(fn($c) => count($c['toAdd']), $allChanges)),
                'totalDeletes'     => array_sum(array_map(fn($c) => count($c['toDelete']), $allChanges)),
            ],
        ];
    }

    /**
     * Execute shift push to LOGA.
     * 
     * @param array $currentLogaData Current LOGA persons data (from fetcher)
     * @return array Result summary
     */
    public function push(array $currentLogaData): array {
        $this->logger->info("Starting shift push for {$this->dateFrom} to {$this->dateTo}", 'ShiftPusher');

        $users = $this->getValidUsers();
        $currentShifts = $this->extractCurrentShifts($currentLogaData);

        $this->logger->info("Found " . count($users) . " valid users to sync", 'ShiftPusher');

        $allChanges = [];
        $syncCount = 0;
        $skipCount = 0;

        foreach ($users as $user) {
            try {
                $changes = $this->calculateShiftChanges($user, $currentShifts[$user['pnr']] ?? []);
                if (empty($changes)) {
                    $skipCount++;
                    continue;
                }

                $this->logger->debug("User {$user['pnr']} has " . count($changes) . " changes", 'ShiftPusher');
                foreach ($changes as $change) {
                    $change['pnr'] = $user['pnr'];
                    $allChanges[] = $change;
                }
                $syncCount++;
            } catch (\Exception $e) {
                $this->logger->error("Failed to calculate changes for user {$user['pnr']}: " . $e->getMessage(), 'ShiftPusher');
            }
        }

        // Send all changes in batches
        $batchResult = ['success' => 0, 'failed' => 0];
        if (!empty($allChanges)) {
            $batchResult = $this->sendBatchedShiftChanges($allChanges);
        }

        $this->logger->info(
            "Shift push complete: {$syncCount} users with changes, {$skipCount} no changes, "
            . "{$batchResult['success']} batches OK, {$batchResult['failed']} batches failed",
            'ShiftPusher'
        );

        return [
            'usersWithChanges' => $syncCount,
            'usersNoChanges'   => $skipCount,
            'totalChanges'     => count($allChanges),
            'batchesSuccess'   => $batchResult['success'],
            'batchesFailed'    => $batchResult['failed'],
            'allSuccess'       => ($batchResult['failed'] === 0),
        ];
    }

    // ─── Holiday Management ─────────────────────────────────────────────────

    private function loadHolidays(): void {
        $startDate = new \DateTime($this->dateFrom);
        $endDate = new \DateTime($this->dateTo);
        $this->holidays = [];

        // Check if getFeastsMonth is available
        if (!function_exists('getFeastsMonth')) {
            // Include ft.php from the parent directory
            $ftPath = dirname(__DIR__) . '/ft.php';
            if (file_exists($ftPath)) {
                require_once $ftPath;
            } else {
                $this->logger->error("ft.php not found, holidays will not be loaded", 'ShiftPusher');
                return;
            }
        }

        while ($startDate <= $endDate) {
            $month = (int)$startDate->format('n');
            $year = (int)$startDate->format('Y');
            $monthTimestamp = mktime(0, 0, 0, $month, 1, $year);

            $monthHolidays = getFeastsMonth($monthTimestamp, "BW");
            foreach ($monthHolidays as $day) {
                $this->holidays[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            $startDate->modify('first day of next month');
        }

        $this->logger->info("Loaded " . count($this->holidays) . " holidays", 'ShiftPusher');
    }

    private function loadShiftIdMap(): void {
        $result = $this->conn->query("SELECT shortcut, originalId FROM loga_shiftlist");
        while ($row = $result->fetch_assoc()) {
            $this->shiftIdMap[$row['shortcut']] = $row['originalId'];
        }
        $this->logger->info("Loaded " . count($this->shiftIdMap) . " shift mappings", 'ShiftPusher');
    }

    // ─── Workday / O-suffix Logic ───────────────────────────────────────────

    private function isWorkDay(string $date): bool {
        $dayOfWeek = (int)date('N', strtotime($date)); // 1=Monday, 7=Sunday
        return $dayOfWeek <= 5 && !in_array($date, $this->holidays);
    }

    /**
     * Get the O/R suffix based on current/next day workday status.
     *   Workday→Workday   = 1
     *   Workday→Holiday   = 2
     *   Holiday→Holiday   = 3
     *   Holiday→Workday   = 4
     */
    private function getOSuffix(string $date): string {
        $nextDate = date('Y-m-d', strtotime($date . ' +1 day'));
        $isCurrentWork = $this->isWorkDay($date);
        $isNextWork = $this->isWorkDay($nextDate);

        if ($isCurrentWork && $isNextWork) return '1';
        if ($isCurrentWork && !$isNextWork) return '2';
        if (!$isCurrentWork && !$isNextWork) return '3';
        if (!$isCurrentWork && $isNextWork) return '4';

        return '1'; // fallback
    }

    /**
     * Get R-shift shortcut with correct case.
     * r1/r2 = lowercase, R3/R4 = uppercase.
     */
    private function getRShiftWithSuffix(string $suffix): string {
        return match ($suffix) {
            '1' => 'r1',
            '2' => 'r2',
            '3' => 'R3',
            '4' => 'R4',
            default => 'R1',
        };
    }

    // ─── User Resolution ────────────────────────────────────────────────────

    /**
     * Get base shift type for a user on a given date.
     * dienstart=2 → 'O', else → 'A'
     */
    private function getBaseShift(int $uid, string $date): ?string {
        $stmt = $this->conn->prepare(
            "SELECT dienstart FROM user_dienstart WHERE uid = ? AND gueltigab <= ? AND gueltigbis >= ? ORDER BY gueltigab DESC LIMIT 1"
        );
        $stmt->bind_param("iss", $uid, $date, $date);
        $stmt->execute();
        $result = $stmt->get_result();

        $baseShift = null;
        if ($row = $result->fetch_assoc()) {
            $baseShift = ($row['dienstart'] == 2) ? 'O' : 'A';
        }
        $stmt->close();

        return $baseShift;
    }

    /**
     * Get valid users for the sync period.
     * Includes regular DB users + special LOGA-only users.
     */
    private function getValidUsers(): array {
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT u.id, u.pnr, u.name FROM user u WHERE u.pnr IS NOT NULL AND u.gueltigab <= ? AND u.gueltigbis >= ?"
        );
        $stmt->bind_param("ss", $this->dateTo, $this->dateFrom);
        $stmt->execute();
        $result = $stmt->get_result();

        $specialPnrs = array_keys(LOGA_SPECIAL_USERS);
        $users = [];
        $skipped = [];
        while ($row = $result->fetch_assoc()) {
            // Skip DB entries for special users — they are handled via LOGA_SPECIAL_USERS
            if (in_array($row['pnr'], $specialPnrs)) {
                $skipped[] = $row['pnr'];
                continue;
            }
            $users[] = $row;
        }
        $stmt->close();

        $this->logger->debug("DB users after filter: " . count($users) . ", skipped special PNRs: " . implode(',', $skipped), 'ShiftPusher');

        // Add special LOGA-only users
        foreach (LOGA_SPECIAL_USERS as $pnr => $info) {
            $users[] = [
                'id'   => null,
                'pnr'  => (string)$pnr,  // cast: PHP coerces numeric string keys to int
                'name' => $info['name'] . ' (Special - ' . $info['shift'] . ')',
            ];
        }

        $this->logger->info("Found " . count($users) . " valid users (incl. " . count(LOGA_SPECIAL_USERS) . " special)", 'ShiftPusher');
        return $users;
    }

    // ─── Shift Calculation ──────────────────────────────────────────────────

    /**
     * Extract current shift state from LOGA data.
     * Returns: [pnr => [date => [shortcut1, shortcut2, ...]]]
     */
    private function extractCurrentShifts(array $logaData): array {
        $currentShifts = [];
        foreach ($logaData as $person) {
            $pnr = $person['manAkPnrVertnr']['pnr'] ?? null;
            if (!$pnr || !isset($person['shiftData'])) continue;

            $currentShifts[$pnr] = [];
            foreach ($person['shiftData'] as $date => $shiftDay) {
                if (!isset($shiftDay['shifts'])) continue;
                $shifts = [];
                foreach ($shiftDay['shifts'] as $shift) {
                    $sc = $shift['shortcut'] ?? null;
                    if ($sc) $shifts[] = $sc;
                }
                if (!empty($shifts)) {
                    $currentShifts[$pnr][$date] = $shifts;
                }
            }
        }
        return $currentShifts;
    }

    /**
     * Calculate expected shifts for a single user and build diff against LOGA.
     */
    private function calculateShiftChanges(array $user, array $currentLogaShifts): array {
        $changes = [];
        $startDate = new \DateTime($this->dateFrom);
        $endDate = new \DateTime($this->dateTo);

        // Get local plan entries (only for DB users)
        $planEntries = [];
        if ($user['id'] !== null) {
            $planEntries = $this->getPlanEntries($user['id']);
        }

        $userIdentifier = $user['id'] ?? $user['pnr'];

        // DEBUG: log special user state
        if ($user['id'] === null) {
            $testDate = $this->dateFrom;
            $testExpected = $this->calculateExpectedShifts($userIdentifier, $testDate, []);
            $this->logger->debug("Special user {$user['pnr']}: id=null, identifier=" . var_export($userIdentifier, true) . ", inMap=" . var_export(isset(LOGA_SPECIAL_USERS[$userIdentifier]), true) . ", currentShiftDates=" . count($currentLogaShifts) . ", isWorkDay({$testDate})=" . var_export($this->isWorkDay($testDate), true) . ", expected(" . $testDate . ")=" . json_encode($testExpected) . ", holidays=" . json_encode($this->holidays), 'ShiftPusher');
        }

        while ($startDate <= $endDate) {
            $dateStr = $startDate->format('Y-m-d');
            $currentLogaShiftsForDate = $currentLogaShifts[$dateStr] ?? [];

            $expectedShifts = $this->calculateExpectedShifts($userIdentifier, $dateStr, $planEntries);

            if (!$this->shiftsMatch($expectedShifts, $currentLogaShiftsForDate)) {
                $changes[] = [
                    'date'           => $dateStr,
                    'currentShifts'  => $currentLogaShiftsForDate,
                    'expectedShifts' => $expectedShifts,
                    'toDelete'       => $currentLogaShiftsForDate,
                    'toAdd'          => $expectedShifts,
                ];
            }

            $startDate->modify('+1 day');
        }

        return $changes;
    }

    /**
     * Get plan entries for a user in the sync range.
     */
    private function getPlanEntries(int $uid): array {
        $stmt = $this->conn->prepare("SELECT datum, valid FROM plan WHERE uid = ? AND datum BETWEEN ? AND ? AND valid IN (1, 2)");
        $stmt->bind_param("iss", $uid, $this->dateFrom, $this->dateTo);
        $stmt->execute();
        $result = $stmt->get_result();

        $entries = [];
        while ($row = $result->fetch_assoc()) {
            $entries[$row['datum']] = $row['valid'];
        }
        $stmt->close();
        return $entries;
    }

    /**
     * Calculate expected shifts for a user/date.
     * Handles special users and regular users differently.
     */
    private function calculateExpectedShifts($userIdOrPnr, string $date, array $planEntries): array {
        // Special users (identified by PNR string; cast because PHP coerces numeric string keys to int)
        $pnrKey = (string)$userIdOrPnr;
        if (isset(LOGA_SPECIAL_USERS[$pnrKey])) {
            $shift = LOGA_SPECIAL_USERS[$pnrKey]['shift'];
            $workdaysOnly = LOGA_SPECIAL_USERS[$pnrKey]['workdaysOnly'] ?? true;

            return ($workdaysOnly && !$this->isWorkDay($date)) ? [] : [$shift];
        }

        // Regular user (integer UID)
        $uid = (int)$userIdOrPnr;
        $baseShift = $this->getBaseShift($uid, $date);
        if (!$baseShift) return [];

        $isWorkDay = $this->isWorkDay($date);
        $planValue = $planEntries[$date] ?? null;

        // Plan value 1 = O shifts
        if ($planValue == 1) {
            $suffix = $this->getOSuffix($date);
            return $isWorkDay
                ? ['O', "O{$suffix}"]
                : ["O{$suffix}"];
        }

        // Plan value 2 = R shifts
        if ($planValue == 2) {
            $suffix = $this->getOSuffix($date);
            $rShift = $this->getRShiftWithSuffix($suffix);
            return $isWorkDay
                ? ['R', $rShift]
                : [$rShift];
        }

        // No plan entry: base shift on workdays, nothing on weekends
        return $isWorkDay ? [$baseShift] : [];
    }

    /**
     * Compare two shift arrays (order-insensitive).
     */
    private function shiftsMatch(array $expected, array $current): bool {
        $e = $expected;
        $c = $current;
        sort($e);
        sort($c);
        return $e === $c;
    }

    // ─── LOGA API Communication ─────────────────────────────────────────────

    /**
     * Send shift changes in batches to LOGA.
     */
    private function sendBatchedShiftChanges(array $allChanges): array {
        $batchSize = LOGA_DEFAULT_BATCH_SIZE;
        $batches = array_chunk($allChanges, $batchSize);
        $result = ['success' => 0, 'failed' => 0];

        $this->logger->info("Sending " . count($allChanges) . " changes in " . count($batches) . " batches", 'ShiftPusher');

        foreach ($batches as $batchIndex => $batch) {
            $batchNum = $batchIndex + 1;
            $maxRetries = 3;
            $batchSuccess = false;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    $this->logger->info("Sending batch {$batchNum}/" . count($batches) . " (attempt {$attempt})", 'ShiftPusher');
                    $this->sendSingleBatch($batch, $batchNum, count($batches));
                    $batchSuccess = true;
                    break;
                } catch (\Exception $e) {
                    $this->logger->error("Batch {$batchNum} attempt {$attempt} failed: " . $e->getMessage(), 'ShiftPusher');
                    if ($attempt < $maxRetries) {
                        sleep(5);
                    }
                }
            }

            if ($batchSuccess) {
                $result['success']++;
            } else {
                $result['failed']++;
                $this->logger->error("Batch {$batchNum} failed permanently after {$maxRetries} attempts", 'ShiftPusher');
            }

            // Rate limiting between batches
            $this->client->batchDelay();
        }

        return $result;
    }

    /**
     * Send a single batch of shift changes to LOGA.
     */
    private function sendSingleBatch(array $changes, int $batchNum, int $totalBatches): void {
        $shiftToPlanParams = [];

        foreach ($changes as $change) {
            $this->logger->info(
                "Batch {$batchNum}/{$totalBatches}: {$change['pnr']} on {$change['date']}: "
                . implode(',', $change['currentShifts']) . " → " . implode(',', $change['expectedShifts']),
                'ShiftPusher'
            );

            // Prepare shifts for deletion
            $shiftsForDelete = [];
            foreach ($change['toDelete'] as $shortcut) {
                if (isset($this->shiftIdMap[$shortcut])) {
                    $shiftsForDelete[] = $this->buildShiftParam($this->shiftIdMap[$shortcut]);
                } else {
                    $this->logger->error("Shift mapping not found for deletion: {$shortcut}", 'ShiftPusher');
                }
            }

            // Prepare shifts for addition
            $shiftsForAdd = [];
            foreach ($change['toAdd'] as $shortcut) {
                if (isset($this->shiftIdMap[$shortcut])) {
                    $shiftsForAdd[] = $this->buildShiftParam($this->shiftIdMap[$shortcut]);
                } else {
                    $this->logger->error("Shift mapping not found for addition: {$shortcut}", 'ShiftPusher');
                }
            }

            $shiftToPlanParams[] = [
                'objektId'         => LOGA_OBJEKT_ID,
                'objektPath'       => LOGA_OBJEKT_PATH,
                'manAkPnrVertnr'   => [
                    'man'    => LOGA_MANDANT,
                    'ak'     => LOGA_MANDANT,
                    'pnr'    => $change['pnr'],
                    'vertnr' => 1,
                ],
                'date'             => $change['date'],
                'shiftsForAdd'     => $shiftsForAdd,
                'shiftsForUpdate'  => [],
                'shiftsForDelete'  => $shiftsForDelete,
            ];
        }

        if (empty($shiftToPlanParams)) return;

        $payload = [
            'clientContext'   => $this->buildClientContext(),
            'shiftToPlanParam' => $shiftToPlanParams,
        ];

        $this->sendPlanShiftsRequest($payload);
        $this->logger->info("Batch {$batchNum}/{$totalBatches} sent: " . count($shiftToPlanParams) . " operations", 'ShiftPusher');
    }

    /**
     * Build a shift parameter object for the planshifts API.
     */
    private function buildShiftParam(string $originalId): array {
        return [
            'id'               => $originalId,
            'timeFrom'         => null,
            'timeTo'           => null,
            'splitShift_id'    => null,
            'order'            => null,
            'dienstgruppeId'   => null,
        ];
    }

    /**
     * Build the client context for the LOGA API.
     */
    private function buildClientContext(): array {
        return [
            'interval'      => ['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo],
            'countryNls'    => '000',
            'man'           => LOGA_MANDANT,
            'contextRoleId' => LOGA_CONTEXT_ROLE_ID,
        ];
    }

    /**
     * Send planshifts request to LOGA.
     */
    private function sendPlanShiftsRequest(array $payload): void {
        $decoded = $this->client->apiRequest('private/api/spepdatachangeservice/planshifts', $payload);

        // Check for API-level error
        if (isset($decoded['error']) && $decoded['error'] === true) {
            $errorMsg = "LOGA planshifts error";
            if (!empty($decoded['errorMessages'])) {
                $errorMsg .= ": " . implode(', ', $decoded['errorMessages']);
            }
            throw new \RuntimeException($errorMsg);
        }

        // Log messages
        if (!empty($decoded['saveMessages'])) {
            $this->logger->info("LOGA: " . implode(', ', $decoded['saveMessages']), 'ShiftPusher');
        }
        if (!empty($decoded['warningMessages'])) {
            $this->logger->info("LOGA warnings: " . implode(', ', $decoded['warningMessages']), 'ShiftPusher');
        }
    }
}
