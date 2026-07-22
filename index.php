<?php
/**
 * LOGA Portal - Main Entry Point & Dashboard
 * 
 * Auto-detects context (browser / CLI / API) and routes accordingly.
 * Browser: standalone dashboard with controls, live SSE execution, conflict resolution.
 * CLI: command-line mode with text output.
 * API: JSON responses for integration.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

// ─── Bootstrap ──────────────────────────────────────────────────────────────

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/ProcessLock.php';
require_once __DIR__ . '/LogaClient.php';
require_once __DIR__ . '/LogaRuntimeConfig.php';
require_once __DIR__ . '/LogaAuth.php';
require_once __DIR__ . '/LogaCache.php';
require_once __DIR__ . '/LogaFetcher.php';
require_once __DIR__ . '/LogaProcessor.php';
require_once __DIR__ . '/LogaShiftPuller.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/LogaShiftPusher.php', true);
    opcache_invalidate(__DIR__ . '/config.php', true);
}
require_once __DIR__ . '/LogaShiftPusher.php';
require_once __DIR__ . '/LogaAbsencePuller.php';
require_once __DIR__ . '/LogaStats.php';
require_once __DIR__ . '/LogaPersons.php';
require_once __DIR__ . '/LogaExplorer.php';

// DB
require_once dirname(__DIR__) . '/db_config.php';
require_once dirname(__DIR__) . '/mysql_config.php';

// ─── Context Detection ─────────────────────────────────────────────────────

function detectContext(): string {
    if (php_sapi_name() === 'cli') return 'cli';
    if (
        filter_var($_GET['api_call'] ?? $_POST['api_call'] ?? false, FILTER_VALIDATE_BOOLEAN)
        || filter_var($_GET['compact'] ?? $_POST['compact'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ||
        isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
        || isset($_GET['format']) && $_GET['format'] === 'json'
        || isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
    ) {
        return 'api';
    }
    return 'browser';
}

$context = detectContext();

// ─── Authentication (browser/API) ──────────────────────────────────────────

if ($context !== 'cli') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    require_once dirname(__DIR__) . '/authCookieSessionValidate.php';

    if (!($isLoggedIn && (int)($_SESSION['role'] ?? 0) === 2)) {
        http_response_code(403);
        if ($context === 'api') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Admin role required']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'LOGA sync requires an authenticated admin session.';
        }
        exit;
    }
}

// ─── Parameter Extraction ───────────────────────────────────────────────────

if ($context === 'cli') {
    $opts = getopt('', ['mode:', 'from:', 'to:', 'force', 'strategy:', 'resolutions:', 'preview']);
    $mode = $opts['mode'] ?? null;
    $dateFrom = $opts['from'] ?? date('Y-m-01');
    $dateTo = $opts['to'] ?? date('Y-m-t');
    $forceRefresh = isset($opts['force']);
    $strategy = $opts['strategy'] ?? 'sync-clean-only';
    $resolutions = isset($opts['resolutions']) ? json_decode($opts['resolutions'], true) : [];
    $previewOnly = isset($opts['preview']);
} else {
    $mode = $_GET['mode'] ?? $_POST['mode'] ?? null;
    $dateFrom = $_GET['dateFrom'] ?? $_POST['dateFrom'] ?? date('Y-m-01');
    $dateTo = $_GET['dateTo'] ?? $_POST['dateTo'] ?? date('Y-m-t');
    $forceRefresh = filter_var($_GET['force'] ?? $_POST['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $strategy = $_GET['strategy'] ?? $_POST['strategy'] ?? 'sync-clean-only';
    $resolutions = [];
    if (!empty($_GET['resolutions'] ?? $_POST['resolutions'] ?? '')) {
        $resolutions = json_decode($_GET['resolutions'] ?? $_POST['resolutions'] ?? '[]', true) ?: [];
    }
    $previewOnly = filter_var($_GET['preview'] ?? $_POST['preview'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

// ─── Dashboard (no mode) ───────────────────────────────────────────────────

if ($mode === null && $context === 'browser') {
    renderDashboard();
    exit;
}

// ─── SSE Stream ─────────────────────────────────────────────────────────────

if ($mode === 'stream') {
    handleSSEStream();
    exit;
}

// ─── Status API ─────────────────────────────────────────────────────────────

if ($mode === 'status') {
    handleStatusApi();
    exit;
}

// ─── History API ─────────────────────────────────────────────────────────────

if ($mode === 'history') {
    handleHistoryApi();
    exit;
}

// ─── Cache Status API ───────────────────────────────────────────────────────

if ($mode === 'cache-status') {
    handleCacheStatusApi();
    exit;
}

// ─── Chart Data API ─────────────────────────────────────────────────────────

if ($mode === 'chart-data') {
    handleChartDataApi();
    exit;
}

// ─── Persons API ────────────────────────────────────────────────────────────

if ($mode === 'persons') {
    handlePersonsApi();
    exit;
}

if ($mode === 'person-detail') {
    handlePersonDetailApi();
    exit;
}

if ($mode === 'persons-sync') {
    handlePersonsSyncApi();
    exit;
}

// ─── Explorer API ───────────────────────────────────────────────────────────

if ($mode === 'explorer-endpoints') {
    handleExplorerEndpointsApi();
    exit;
}

if ($mode === 'explorer-fetch') {
    handleExplorerFetchApi();
    exit;
}

if ($mode === 'explorer-konten-overview') {
    handleExplorerKontenOverviewApi();
    exit;
}

if ($mode === 'explorer-history') {
    handleExplorerHistoryApi();
    exit;
}

if ($mode === 'explorer-view') {
    handleExplorerViewApi();
    exit;
}

// ─── Execute Mode ───────────────────────────────────────────────────────────

if ($mode !== null && in_array($mode, array_keys(LOGA_MODES))) {
    executeMode($mode, $dateFrom, $dateTo, $forceRefresh, $strategy, $resolutions, $previewOnly, $context);
    exit;
}

// Unknown mode
if ($context === 'api' || $context === 'cli') {
    $msg = "Unknown mode: {$mode}. Valid modes: " . implode(', ', array_keys(LOGA_MODES));
    if ($context === 'api') {
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg]);
    } else {
        echo "ERROR: {$msg}\n";
    }
    exit(1);
}


// ═══════════════════════════════════════════════════════════════════════════
// Mode Execution
// ═══════════════════════════════════════════════════════════════════════════

function executeMode(string $mode, string $dateFrom, string $dateTo, bool $forceRefresh, string $strategy, array $resolutions, bool $previewOnly, string $context): void {
    global $conn;

    // Allow long-running LOGA operations to complete even if nginx drops the
    // upstream connection (fastcgi_read_timeout). Without this the process lock
    // is never released, causing subsequent requests to queue/timeout as well.
    ignore_user_abort(true);
    set_time_limit(300); // 5-minute hard ceiling per operation

    // Release the PHP session file lock before the sync starts.
    // The sync calls api.php via loopback HTTP; api.php calls session_start()
    // which would block indefinitely waiting for this lock → 60s timeout per call.
    // session_write_close() flushes the session and releases the lock.
    // $_SESSION data remains readable for the rest of this request.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $outputContext = match ($context) {
        'cli'     => 'cli',
        'api'     => 'api',
        default   => (isset($_GET['stream']) ? 'sse' : 'browser'),
    };

    $logger = LogaLogger::getInstance();
    $logger->setContext($outputContext);
    $logger->info("Starting mode: {$mode} ({$dateFrom} to {$dateTo})", 'Main');

    // Process lock
    $lock = new ProcessLock($logger);
    if (!$lock->acquire($mode, $dateFrom . ' - ' . $dateTo)) {
        $lockInfo = $lock->getLockInfo();
        $msg = 'Another LOGA sync is running';
        if ($lockInfo) {
            $msg .= " (Mode: {$lockInfo['mode']}, Started: " . date('H:i:s', $lockInfo['startTime']). ")";
        }
        outputResult($context, ['error' => $msg], 423);
        return;
    }

    $stats = new LogaStats($conn, $logger);
    $stats->startRun($mode);
    $cache = new LogaCache($logger);

    try {
        $result = [];

        switch ($mode) {
            case 'fetch':
                $result = executeFetch($conn, $logger, $cache, $dateFrom, $dateTo, $forceRefresh);
                break;

            case 'process':
                $result = executeProcess($conn, $logger, $cache, $dateFrom, $dateTo);
                break;

            case 'pull-shifts':
                $result = executePullShifts($conn, $logger, $cache, $dateFrom, $dateTo, $forceRefresh, $strategy, $resolutions, $previewOnly);
                break;

            case 'push-shifts':
                $result = executePushShifts($conn, $logger, $cache, $dateFrom, $dateTo, $forceRefresh, $previewOnly);
                break;

            case 'pull-absences':
                $result = executePullAbsences($conn, $logger, $cache, $dateFrom, $dateTo, $forceRefresh, $strategy, $resolutions, $previewOnly);
                break;

            case 'pull-all':
                $result = executePullAll($conn, $logger, $cache, $dateFrom, $dateTo, $forceRefresh, $strategy, $resolutions, $previewOnly);
                break;
        }

        $hasErrors = $logger->hasErrors();
        $stats->recordRun($mode, $dateFrom, $dateTo, $result, $hasErrors);

        $result['logs'] = $logger->getEntries();
        $result['counts'] = $logger->getCounts();
        outputResult($context, $result);

    } catch (\Exception $e) {
        $logger->error($e->getMessage(), 'Main');
        $stats->recordRun($mode, $dateFrom, $dateTo, ['error' => $e->getMessage()], true);
        outputResult($context, [
            'error' => $e->getMessage(),
            'logs'  => $logger->getEntries(),
        ], 500);
    } finally {
        $lock->release();
    }
}

// ─── Mode Executors ─────────────────────────────────────────────────────────

function executeFetch(mysqli $conn, LogaLogger $logger, LogaCache $cache, string $dateFrom, string $dateTo, bool $forceRefresh): array {
    $client = new LogaClient($logger);

    $auth = new LogaAuth($client, $logger);
    if (!$auth->authenticate()) {
        throw new \RuntimeException('LOGA authentication failed');
    }

    $monthKeys = getMonthKeys($dateFrom, $dateTo);

    // Skip fetch entirely if all months are still fresh in cache
    if (!$forceRefresh) {
        $allCached = true;
        foreach ($monthKeys as $mk) {
            if ($cache->isStale($mk)) {
                $allCached = false;
                break;
            }
        }
        if ($allCached) {
            $logger->info("All months already cached – skipping LOGA fetch", 'Main');
            $totalPersons = 0;
            foreach ($monthKeys as $mk) {
                $cached = $cache->read($mk);
                $totalPersons += count($cached['personsData'] ?? []);
            }
            return [
                'success'      => true,
                'mode'         => 'fetch',
                'personsCount' => $totalPersons,
                'months'       => count($monthKeys),
                'fromCache'    => true,
            ];
        }
    }

    // Single LOGA request covering the full date range
    // Skip internal cache write — we write filtered per-month slices below
    $logger->info("Fetching full range {$dateFrom} → {$dateTo} in one LOGA request", 'Main');
    $fetcher = (new LogaFetcher($client, $dateFrom, $dateTo, $logger, $cache))->setSkipCache(true);
    
    try {
        $result = $fetcher->fetch(true); // always fresh – we already checked cache above
    } catch (\Exception $e) {
        // If fetch fails, it might be due to stale runtime config (version mismatch)
        // Force re-authentication with fresh config and retry
        if (strpos($e->getMessage(), 'circuit breaker') !== false 
            || strpos($e->getMessage(), 'HTTP 500') !== false
            || strpos($e->getMessage(), 'batches failed') !== false) {
            
            $logger->info("API failures detected, refreshing LOGA version...", 'Main');
            
            // Reset circuit breaker so retries can proceed
            $client->resetCircuitBreaker();
            
            // Re-authenticate with forced config refresh
            if (!$auth->authenticate(true)) {
                throw new \RuntimeException('LOGA re-authentication failed after version refresh');
            }
            
            // Retry fetch with refreshed credentials
            try {
                $result = $fetcher->fetch(true);
            } catch (\Exception $retryError) {
                throw new \RuntimeException("Fetch failed even after version refresh: " . $retryError->getMessage());
            }
        } else {
            throw $e;
        }
    }

    $persons     = $result['persons']     ?? [];
    $personsData = $result['personsData'] ?? [];

    // Write per-month slices into the cache so LogaProcessor (which reads by month) still works.
    // Each person's date-keyed maps are filtered to only that month's dates to avoid
    // storing the entire multi-month blob redundantly in every cache slot.
    foreach ($monthKeys as $mk) {
        $mFrom = max($dateFrom, $mk . '-01');
        $mTo   = min($dateTo, date('Y-m-t', strtotime($mk . '-01')));
        $monthPersonsData = filterPersonsDataToRange($personsData, $mFrom, $mTo);
        $cache->write($mk, $mFrom, $mTo, $persons, $monthPersonsData);
    }

    $totalPersons = count($personsData);
    $logger->info("Fetch complete: {$totalPersons} person records cached across " . count($monthKeys) . " months", 'Main');

    return [
        'success'      => true,
        'mode'         => 'fetch',
        'personsCount' => $totalPersons,
        'months'       => count($monthKeys),
        'fromCache'    => false,
    ];
}

function executeProcess(mysqli $conn, LogaLogger $logger, LogaCache $cache, string $dateFrom, string $dateTo): array {
    $processor = new LogaProcessor($conn, $logger, $cache);
    $monthKeys = getMonthKeys($dateFrom, $dateTo);

    $allShifts = [];
    $allAbsences = [];
    $personsCount = 0;

    foreach ($monthKeys as $mk) {
        $result = $processor->process($mk);
        $personsCount += count($result['personsTable']);
        $allShifts = array_merge($allShifts, $result['personsShifts']);
        $allAbsences = array_merge($allAbsences, $result['personsAbsences']);
    }

    return [
        'success'        => true,
        'mode'           => 'process',
        'personsCount'   => $personsCount,
        'shiftsCount'    => count($allShifts),
        'absencesCount'  => count($allAbsences),
    ];
}

function executePullShifts(mysqli $conn, LogaLogger $logger, LogaCache $cache, string $dateFrom, string $dateTo, bool $forceRefresh, string $strategy, array $resolutions, bool $previewOnly): array {
    // Ensure we have fresh data
    executeFetch($conn, $logger, $cache, $dateFrom, $dateTo, $forceRefresh);

    $processor = new LogaProcessor($conn, $logger, $cache);
    $puller = new LogaShiftPuller($conn, $logger, $processor);
    $monthKeys = getMonthKeys($dateFrom, $dateTo);

    $allDiffs = [];
    $allCounts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'keptLocal' => 0, 'errors' => 0];

    foreach ($monthKeys as $mk) {
        $processed = $processor->process($mk);
        $mFrom = max($dateFrom, $mk . '-01');
        $mTo = min($dateTo, date('Y-m-t', strtotime($mk . '-01')));

        if ($previewOnly) {
            $preview = $puller->preview($processed['personsShifts'], $mFrom, $mTo);
            $allDiffs = array_merge($allDiffs, $preview['differences']);
        } else {
            $counts = $puller->apply($processed['personsShifts'], $mFrom, $mTo, $strategy, $resolutions);
            foreach ($allCounts as $k => &$v) $v += ($counts[$k] ?? 0);
        }
    }

    if ($previewOnly) {
        return [
            'success'     => true,
            'mode'        => 'pull-shifts',
            'preview'     => true,
            'differences' => $allDiffs,
            'totalDiffs'  => count($allDiffs),
        ];
    }

    return array_merge(['success' => true, 'mode' => 'pull-shifts', 'preview' => false, 'shiftsCount' => $allCounts['inserted'] + $allCounts['updated']], $allCounts);
}

function executePushShifts(mysqli $conn, LogaLogger $logger, LogaCache $cache, string $dateFrom, string $dateTo, bool $forceRefresh, bool $previewOnly): array {
    // Need fresh LOGA data for comparison
    $client = new LogaClient($logger);

    $auth = new LogaAuth($client, $logger);
    if (!$auth->authenticate()) {
        throw new \RuntimeException('LOGA authentication failed');
    }

    // Single LOGA request covering the full date range
    // Skip internal cache write — push doesn't need to persist the fetched data
    $fetcher = (new LogaFetcher($client, $dateFrom, $dateTo, $logger, $cache))->setSkipCache(true);
    
    try {
        $result = $fetcher->fetch(true); // Always fresh for push
    } catch (\Exception $e) {
        // If fetch fails, it might be due to stale runtime config (version mismatch)
        // Force re-authentication with fresh config and retry
        if (strpos($e->getMessage(), 'circuit breaker') !== false 
            || strpos($e->getMessage(), 'HTTP 500') !== false
            || strpos($e->getMessage(), 'batches failed') !== false) {
            
            $logger->info("API failures detected, refreshing LOGA version...", 'Main');
            
            // Reset circuit breaker so retries can proceed
            $client->resetCircuitBreaker();
            
            // Re-authenticate with forced config refresh
            if (!$auth->authenticate(true)) {
                throw new \RuntimeException('LOGA re-authentication failed after version refresh');
            }
            
            // Retry fetch with refreshed credentials
            try {
                $result = $fetcher->fetch(true);
            } catch (\Exception $retryError) {
                throw new \RuntimeException("Fetch failed even after version refresh: " . $retryError->getMessage());
            }
        } else {
            throw $e;
        }
    }
    
    $allLogaData = $result['personsData'] ?? [];

    $pusher = new LogaShiftPusher($conn, $client, $auth, $dateFrom, $dateTo, $logger);

    if ($previewOnly) {
        $preview = $pusher->preview($allLogaData);
        return [
            'success' => true,
            'mode'    => 'push-shifts',
            'preview' => true,
            'changes' => $preview['changes'],
            'summary' => $preview['summary'],
        ];
    }

    $result = $pusher->push($allLogaData);
    return array_merge(['success' => true, 'mode' => 'push-shifts', 'preview' => false], $result);
}

function executePullAbsences(mysqli $conn, LogaLogger $logger, LogaCache $cache, string $dateFrom, string $dateTo, bool $forceRefresh, string $strategy, array $resolutions, bool $previewOnly): array {
    // Ensure fresh data
    executeFetch($conn, $logger, $cache, $dateFrom, $dateTo, $forceRefresh);

    $processor = new LogaProcessor($conn, $logger, $cache);
    $puller = new LogaAbsencePuller($conn, $logger, $processor);
    $monthKeys = getMonthKeys($dateFrom, $dateTo);

    $allConflicts = [];
    $allCounts = ['inserted' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'keptLocal' => 0, 'unmapped' => 0, 'errors' => 0];

    foreach ($monthKeys as $mk) {
        $processed = $processor->process($mk);
        $mFrom = max($dateFrom, $mk . '-01');
        $mTo = min($dateTo, date('Y-m-t', strtotime($mk . '-01')));

        if ($previewOnly) {
            $preview = $puller->preview($processed['personsAbsences'], $mFrom, $mTo);
            $allConflicts = array_merge($allConflicts, $preview['conflicts']);
        } else {
            $counts = $puller->apply($processed['personsAbsences'], $mFrom, $mTo, $strategy, $resolutions);
            foreach ($allCounts as $k => &$v) $v += ($counts[$k] ?? 0);
        }
    }

    if ($previewOnly) {
        return [
            'success'        => true,
            'mode'           => 'pull-absences',
            'preview'        => true,
            'hasConflicts'   => !empty($allConflicts),
            'conflicts'      => $allConflicts,
            'totalConflicts' => count($allConflicts),
        ];
    }

    return array_merge(['success' => true, 'mode' => 'pull-absences', 'preview' => false, 'absencesCount' => $allCounts['inserted'] + $allCounts['updated']], $allCounts);
}

function executePullAll(mysqli $conn, LogaLogger $logger, LogaCache $cache, string $dateFrom, string $dateTo, bool $forceRefresh, string $strategy, array $resolutions, bool $previewOnly): array {
    $logger->info("Full pull: shifts + absences", 'Main');

    $shiftResult = executePullShifts($conn, $logger, $cache, $dateFrom, $dateTo, $forceRefresh, $strategy, $resolutions, $previewOnly);
    $absenceResult = executePullAbsences($conn, $logger, $cache, $dateFrom, $dateTo, false, $strategy, $resolutions, $previewOnly);

    return [
        'success'   => true,
        'mode'      => 'pull-all',
        'preview'   => $previewOnly,
        'shifts'    => $shiftResult,
        'absences'  => $absenceResult,
    ];
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function getMonthKeys(string $dateFrom, string $dateTo): array {
    $keys = [];
    $start = new \DateTime(substr($dateFrom, 0, 7) . '-01');
    $end = new \DateTime(substr($dateTo, 0, 7) . '-01');
    while ($start <= $end) {
        $keys[] = $start->format('Y-m');
        $start->modify('+1 month');
    }
    return $keys;
}

/**
 * Filter each person's date-keyed data maps to only the given date range.
 * This keeps individual month cache slots small even when data was fetched
 * in one big multi-month request.
 */
function filterPersonsDataToRange(array $personsData, string $from, string $to): array {
    $result = [];
    foreach ($personsData as $person) {
        $filtered = $person;

        // Shifts are day-keyed; key filtering is enough.
        if (isset($person['shiftData']) && is_array($person['shiftData'])) {
            $filtered['shiftData'] = array_filter(
                $person['shiftData'],
                fn($date) => $date >= $from && $date <= $to,
                ARRAY_FILTER_USE_KEY
            );
        }

        // Absence maps can be keyed by interval start (possibly previous month).
        // Keep entries when any contained interval overlaps the requested range.
        if (isset($person['personAbsenceDataMap']) && is_array($person['personAbsenceDataMap'])) {
            $filtered['personAbsenceDataMap'] = array_filter(
                $person['personAbsenceDataMap'],
                fn($day, $dateKey) => dateMapEntryOverlapsRange($day, $dateKey, $from, $to, 'absenceData'),
                ARRAY_FILTER_USE_BOTH
            );
        }

        if (isset($person['absenceRequestDataMap']) && is_array($person['absenceRequestDataMap'])) {
            $filtered['absenceRequestDataMap'] = array_filter(
                $person['absenceRequestDataMap'],
                fn($day, $dateKey) => dateMapEntryOverlapsRange($day, $dateKey, $from, $to, 'data'),
                ARRAY_FILTER_USE_BOTH
            );
        }

        $result[] = $filtered;
    }
    return $result;
}

/**
 * Check whether a date-keyed map entry overlaps the given range.
 */
function dateMapEntryOverlapsRange($day, string $dateKey, string $from, string $to, string $entriesKey): bool {
    if ($dateKey >= $from && $dateKey <= $to) {
        return true;
    }

    if (!is_array($day) || !isset($day[$entriesKey]) || !is_array($day[$entriesKey])) {
        return false;
    }

    foreach ($day[$entriesKey] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $interval = $entry['interval'] ?? null;
        $entryFrom = $interval['dateFrom'] ?? $dateKey;
        $entryTo = $interval['dateTo'] ?? $dateKey;

        if (!is_string($entryFrom) || !is_string($entryTo)) {
            continue;
        }

        if ($entryFrom <= $to && $entryTo >= $from) {
            return true;
        }
    }

    return false;
}


function outputResult(string $context, array $data, int $httpCode = 200): void {
    if ($context === 'api' || $context === 'browser') {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        // CLI
        if (isset($data['error'])) {
            echo "ERROR: {$data['error']}\n";
        }
        if (isset($data['logs'])) {
            foreach ($data['logs'] as $log) {
                echo "[{$log['level']}] [{$log['source']}] {$log['message']}\n";
            }
        }
        if (!isset($data['error'])) {
            echo "OK\n";
        }
    }
}

// ─── API Handlers ───────────────────────────────────────────────────────────

function handleStatusApi(): void {
    global $conn;
    $logger = LogaLogger::getInstance();
    $cache = new LogaCache($logger);
    $stats = new LogaStats($conn, $logger, $cache);
    $lock = new ProcessLock($logger);

    header('Content-Type: application/json');
    echo json_encode([
        'locked'     => $lock->isLocked(),
        'lockInfo'   => $lock->getLockInfo(),
        'cooldown'   => 0,
        'lastRun'    => $stats->getLastRun(),
        'cacheStatus' => $stats->getCacheStatus(),
    ], JSON_UNESCAPED_UNICODE);
}

function handleHistoryApi(): void {
    global $conn;
    $limit = (int)($_GET['limit'] ?? 50);
    $stats = new LogaStats($conn);
    header('Content-Type: application/json');
    echo json_encode($stats->getHistory($limit), JSON_UNESCAPED_UNICODE);
}

function handleCacheStatusApi(): void {
    $logger = LogaLogger::getInstance();
    $cache = new LogaCache($logger);
    $status = $cache->getStatus();
    header('Content-Type: application/json');
    echo json_encode([
        'status'     => $status,
        'statistics' => $status['statistics'] ?? [],
    ], JSON_UNESCAPED_UNICODE);
}

function handleChartDataApi(): void {
    global $conn;
    $days = (int)($_GET['days'] ?? 30);
    $stats = new LogaStats($conn);
    header('Content-Type: application/json');
    echo json_encode($stats->getChartData($days), JSON_UNESCAPED_UNICODE);
}

// ─── Persons API Handlers ───────────────────────────────────────────────────

function handlePersonsApi(): void {
    global $conn;
    $persons = new LogaPersons($conn);

    if (!$persons->tableExists()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'loga_persons table not found. Run migration.sql first.']);
        return;
    }

    $search = $_GET['search'] ?? '';
    $filter = $_GET['filter'] ?? 'all';
    $orderBy = $_GET['orderBy'] ?? 'full_name';

    $data = $persons->getAll($search, $filter, $orderBy);
    $stats = $persons->getStats();

    header('Content-Type: application/json');
    echo json_encode([
        'persons' => $data,
        'stats'   => $stats,
        'count'   => count($data),
    ], JSON_UNESCAPED_UNICODE);
}

function handlePersonDetailApi(): void {
    global $conn;
    $pnr = $_GET['pnr'] ?? '';
    if (!$pnr) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Missing pnr parameter']);
        return;
    }

    $persons = new LogaPersons($conn);
    $person = $persons->getByPnr($pnr);

    header('Content-Type: application/json');
    if ($person) {
        echo json_encode(['success' => true, 'person' => $person], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'error' => "Person with PNR {$pnr} not found"]);
    }
}

function handlePersonsSyncApi(): void {
    global $conn;
    $logger = LogaLogger::getInstance();
    $cache = new LogaCache($logger);
    $persons = new LogaPersons($conn, $logger);

    if (!$persons->tableExists()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'loga_persons table not found. Run migration.sql first.']);
        return;
    }

    // Get all person data from cache
    $monthKeys = [];
    $status = $cache->getStatus();
    foreach (($status['months'] ?? []) as $mk => $info) {
        $monthKeys[] = $mk;
    }

    if (empty($monthKeys)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No cached data available. Run a fetch first.']);
        return;
    }

    $totalResult = ['total' => 0, 'inserted' => 0, 'updated' => 0];

    foreach ($monthKeys as $mk) {
        $cached = $cache->read($mk);
        if ($cached && !empty($cached['personsData'])) {
            $result = $persons->syncFromFetchData($cached['personsData']);
            $totalResult['total'] += $result['total'];
            $totalResult['inserted'] += $result['inserted'];
            $totalResult['updated'] += $result['updated'];
        }
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'result'  => $totalResult,
        'message' => "Synced from " . count($monthKeys) . " cached month(s)",
    ], JSON_UNESCAPED_UNICODE);
}

// ─── Explorer API Handlers ──────────────────────────────────────────────────

function handleExplorerEndpointsApi(): void {
    header('Content-Type: application/json');
    echo json_encode([
        'endpoints' => LogaExplorer::getEndpointList(),
        'categories' => LogaExplorer::getEndpointsByCategory(),
    ], JSON_UNESCAPED_UNICODE);
}

function handleExplorerFetchApi(): void {
    global $conn;
    header('Content-Type: application/json');

    $endpointKey = $_GET['endpoint'] ?? $_POST['endpoint'] ?? '';

    if (!$endpointKey) {
        echo json_encode(['error' => 'Missing endpoint parameter']);
        return;
    }

    try {
        $logger = LogaLogger::getInstance();
        $client = new LogaClient($logger);
        $auth = new LogaAuth($client, $logger);

        if (!$auth->authenticate()) {
            echo json_encode(['error' => 'LOGA authentication failed']);
            return;
        }

        // Save to DB only if connection and table exist
        $saveToDb = false;
        if ($conn && $conn->ping()) {
            $tableCheck = $conn->query("SHOW TABLES LIKE 'loga_explorer_data'");
            $saveToDb = ($tableCheck && $tableCheck->num_rows > 0);
        }

        $explorer = new LogaExplorer($client, $auth, $logger, $conn);
        $result = $explorer->fetch($endpointKey, $saveToDb);

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        http_response_code(200); // Return JSON error, not HTTP 500
        echo json_encode([
            'success' => false,
            'error'   => $e->getMessage(),
            'endpoint' => $endpointKey,
        ], JSON_UNESCAPED_UNICODE);
    }
}

function handleExplorerKontenOverviewApi(): void {
    global $conn;
    header('Content-Type: application/json');

    $month = normalizeExplorerKontenMonth($_GET['month'] ?? $_POST['month'] ?? null);
    $forceRefresh = filter_var($_GET['refresh'] ?? $_POST['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $interval = getExplorerKontenMonthInterval($month);

    if (!$forceRefresh && $conn && explorerKontenOverviewTableExists($conn)) {
        $stored = loadExplorerKontenOverviewFromDb($conn, $month);
        if ($stored !== null) {
            echo json_encode($stored, JSON_UNESCAPED_UNICODE);
            return;
        }
    }

    try {
        $logger = LogaLogger::getInstance();
        $client = new LogaClient($logger);
        $auth = new LogaAuth($client, $logger);

        if (!$auth->authenticate()) {
            echo json_encode(['success' => false, 'error' => 'LOGA authentication failed']);
            return;
        }

        $explorer = new LogaExplorer($client, $auth, $logger, $conn);
        $explorer->setRequestInterval($interval['dateFrom'], $interval['dateTo']);
        $objectResult = $explorer->fetch('konten-objekt-sum', true);
        $personResult = $explorer->fetch('konten-person-data', true);
        $explorer->clearRequestInterval();

        if (!$objectResult['success'] || !$personResult['success']) {
            $error = $objectResult['error'] ?? $personResult['error'] ?? 'Failed to load konten data';
            echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (empty($objectResult['isJson']) || empty($personResult['isJson'])) {
            echo json_encode(['success' => false, 'error' => 'LOGA konten response is not valid JSON'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $overview = buildExplorerKontenOverview(
            is_array($personResult['data'] ?? null) ? $personResult['data'] : [],
            is_array($objectResult['data'] ?? null) ? $objectResult['data'] : [],
            $conn
        );

        if ($conn && explorerKontenOverviewTableExists($conn)) {
            saveExplorerKontenOverviewToDb(
                $conn,
                $month,
                $interval,
                $overview,
                is_array($objectResult['data'] ?? null) ? $objectResult['data'] : [],
                is_array($personResult['data'] ?? null) ? $personResult['data'] : []
            );
        }

        echo json_encode([
            'success' => true,
            'month' => $month,
            'dateFrom' => $interval['dateFrom'],
            'dateTo' => $interval['dateTo'],
            'source' => 'loga',
            'meta' => [
                'objectHttpStatus' => $objectResult['httpStatus'] ?? null,
                'personHttpStatus' => $personResult['httpStatus'] ?? null,
                'objectDurationMs' => $objectResult['durationMs'] ?? null,
                'personDurationMs' => $personResult['durationMs'] ?? null,
                'objectSize' => $objectResult['size'] ?? null,
                'personSize' => $personResult['size'] ?? null,
            ],
        ] + $overview, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
}

function handleExplorerHistoryApi(): void {
    global $conn;
    header('Content-Type: application/json');
    try {
        $logger = LogaLogger::getInstance();
        $explorer = new LogaExplorer(new LogaClient($logger), new LogaAuth(new LogaClient($logger), $logger), $logger, $conn);

        $limit = (int)($_GET['limit'] ?? 50);
        $rows = $explorer->getHistory($limit);

        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function handleExplorerViewApi(): void {
    global $conn;
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);

    if (!$id) {
        echo json_encode(['error' => 'Missing id parameter']);
        return;
    }

    try {
    $logger = LogaLogger::getInstance();
    $explorer = new LogaExplorer(new LogaClient($logger), new LogaAuth(new LogaClient($logger), $logger), $logger, $conn);
    $row = $explorer->getSavedResponse($id);

    header('Content-Type: application/json');
    if ($row) {
        // Try to decode response_data as JSON for pretty display
        $decoded = json_decode($row['response_data'], true);
        $row['response_decoded'] = $decoded;
        echo json_encode(['success' => true, 'record' => $row], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } else {
        echo json_encode(['success' => false, 'error' => 'Record not found']);
    }
    } catch (\Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function buildExplorerKontenOverview(array $personPayload, array $objectPayload, mysqli $conn): array {
    $titles = is_array($objectPayload['titles'] ?? null) ? $objectPayload['titles'] : [];
    $objectData = is_array($objectPayload['data'] ?? null) ? $objectPayload['data'] : [];
    $personRows = is_array($personPayload['personsKontenData'] ?? null) ? $personPayload['personsKontenData'] : [];

    $personMetricKeys = [];
    foreach ($personRows as $row) {
        foreach (array_keys(is_array($row['data'] ?? null) ? $row['data'] : []) as $metricKey) {
            $personMetricKeys[$metricKey] = true;
        }
    }

    $metricKeys = [];
    foreach ($titles as $metricKey => $_title) {
        if (array_key_exists($metricKey, $objectData) || isset($personMetricKeys[$metricKey])) {
            $metricKeys[] = $metricKey;
        }
    }
    foreach (array_keys($personMetricKeys) as $metricKey) {
        if (!in_array($metricKey, $metricKeys, true)) {
            $metricKeys[] = $metricKey;
        }
    }

    $snapshotDate = detectExplorerKontenDate($metricKeys, $objectData, $personRows);
    $columns = [];
    $totals = [];

    foreach ($metricKeys as $metricKey) {
        $columns[] = [
            'key' => $metricKey,
            'title' => (string)($titles[$metricKey] ?? $metricKey),
        ];
        $totals[$metricKey] = pickExplorerKontenValue(
            is_array($objectData[$metricKey] ?? null) ? $objectData[$metricKey] : [],
            $snapshotDate
        );
    }

    $persons = new LogaPersons($conn);
    $nameMap = $persons->getDisplayDataByPnr();
    $rows = [];

    foreach ($personRows as $row) {
        $pnr = (string)($row['manAkPnrVertnr']['pnr'] ?? '');
        if ($pnr === '') {
            continue;
        }

        $nameInfo = $nameMap[$pnr] ?? [
            'loga_person_id' => null,
            'local_user_id' => null,
            'local_name' => null,
            'loga_name' => null,
            'display_name' => "PNR {$pnr}",
        ];

        $values = [];
        foreach ($metricKeys as $metricKey) {
            $values[$metricKey] = pickExplorerKontenValue(
                is_array($row['data'][$metricKey] ?? null) ? $row['data'][$metricKey] : [],
                $snapshotDate
            );
        }

        $rows[] = [
            'pnr' => $pnr,
            'logaPersonId' => $nameInfo['loga_person_id'] ?? null,
            'displayName' => $nameInfo['display_name'] ?? "PNR {$pnr}",
            'localName' => $nameInfo['local_name'] ?? null,
            'logaName' => $nameInfo['loga_name'] ?? null,
            'localUserId' => $nameInfo['local_user_id'] ?? null,
            'vertnr' => $row['manAkPnrVertnr']['vertnr'] ?? null,
            'values' => $values,
        ];
    }

    usort($rows, static function (array $left, array $right): int {
        return strcasecmp((string)$left['displayName'], (string)$right['displayName']);
    });

    return [
        'objektId' => $objectPayload['objektId'] ?? null,
        'objsId' => $personPayload['objsId'] ?? null,
        'snapshotDate' => $snapshotDate,
        'columns' => $columns,
        'totals' => $totals,
        'rows' => $rows,
        'count' => count($rows),
    ];
}

function detectExplorerKontenDate(array $metricKeys, array $objectData, array $personRows): ?string {
    foreach ($metricKeys as $metricKey) {
        $date = pickExplorerKontenDate(is_array($objectData[$metricKey] ?? null) ? $objectData[$metricKey] : []);
        if ($date !== null) {
            return $date;
        }
    }

    foreach ($personRows as $row) {
        foreach (array_keys(is_array($row['data'] ?? null) ? $row['data'] : []) as $metricKey) {
            $date = pickExplorerKontenDate(is_array($row['data'][$metricKey] ?? null) ? $row['data'][$metricKey] : []);
            if ($date !== null) {
                return $date;
            }
        }
    }

    return null;
}

function pickExplorerKontenDate(array $bucket): ?string {
    foreach ($bucket as $date => $_value) {
        return (string)$date;
    }
    return null;
}

function pickExplorerKontenValue(array $bucket, ?string $preferredDate): ?string {
    if ($preferredDate !== null && array_key_exists($preferredDate, $bucket)) {
        return (string)$bucket[$preferredDate];
    }

    foreach ($bucket as $value) {
        return (string)$value;
    }

    return null;
}

function normalizeExplorerKontenMonth(?string $month): string {
    if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month)) {
        return $month;
    }

    return date('Y-m');
}

function getExplorerKontenMonthInterval(string $month): array {
    $date = \DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01');
    if (!$date) {
        $date = new \DateTimeImmutable(date('Y-m-01'));
    }

    return [
        'dateFrom' => $date->format('Y-m-01'),
        'dateTo' => $date->format('Y-m-t'),
    ];
}

function explorerKontenOverviewTableExists(mysqli $conn): bool {
    $result = $conn->query("SHOW TABLES LIKE 'loga_konten_overview_monthly'");
    return $result && $result->num_rows > 0;
}

function explorerKontenPersonMonthlyTableExists(mysqli $conn): bool {
    $result = $conn->query("SHOW TABLES LIKE 'loga_konten_person_monthly'");
    return $result && $result->num_rows > 0;
}

function loadExplorerKontenOverviewFromDb(mysqli $conn, string $month): ?array {
    $stmt = $conn->prepare(
        "SELECT month_key, date_from, date_to, snapshot_date, objekt_id, objs_id, overview_data, updated_at
         FROM loga_konten_overview_monthly
         WHERE month_key = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $month);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return null;
    }

    $overview = json_decode((string)($row['overview_data'] ?? ''), true);
    if (!is_array($overview)) {
        return null;
    }

    return [
        'success' => true,
        'source' => 'database',
        'month' => $row['month_key'],
        'dateFrom' => $row['date_from'],
        'dateTo' => $row['date_to'],
        'storedAt' => $row['updated_at'],
    ] + $overview;
}

function saveExplorerKontenOverviewToDb(
    mysqli $conn,
    string $month,
    array $interval,
    array $overview,
    array $objectPayload,
    array $personPayload
): void {
    $user = $_SESSION['username'] ?? $_SESSION['name'] ?? 'system';

    if (!empty($overview['rows']) && explorerKontenPersonMonthlyTableExists($conn)) {
        $persons = new LogaPersons($conn);
        $personSeeds = [];
        foreach ($overview['rows'] as $row) {
            $pnr = trim((string)($row['pnr'] ?? ''));
            if ($pnr === '') {
                continue;
            }

            $personSeeds[$pnr] = [
                'display_name' => $row['displayName'] ?? null,
                'local_name' => $row['localName'] ?? null,
                'loga_name' => $row['logaName'] ?? null,
            ];
        }

        $personIdMap = $persons->ensurePersonIdsByPnr($personSeeds);
        foreach ($overview['rows'] as &$row) {
            $pnr = trim((string)($row['pnr'] ?? ''));
            if ($pnr !== '' && isset($personIdMap[$pnr])) {
                $row['logaPersonId'] = $personIdMap[$pnr];
            }
        }
        unset($row);
    }

    $overviewJson = json_encode($overview, JSON_UNESCAPED_UNICODE);
    $objectJson = json_encode($objectPayload, JSON_UNESCAPED_UNICODE);
    $personJson = json_encode($personPayload, JSON_UNESCAPED_UNICODE);

    if ($overviewJson === false || $objectJson === false || $personJson === false) {
        return;
    }

    $snapshotDate = $overview['snapshotDate'] ?? null;
    $objektId = $overview['objektId'] ?? null;
    $objsId = $overview['objsId'] ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO loga_konten_overview_monthly (
            month_key, date_from, date_to, snapshot_date, objekt_id, objs_id,
            overview_data, raw_object_payload, raw_person_payload, fetched_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            date_from = VALUES(date_from),
            date_to = VALUES(date_to),
            snapshot_date = VALUES(snapshot_date),
            objekt_id = VALUES(objekt_id),
            objs_id = VALUES(objs_id),
            overview_data = VALUES(overview_data),
            raw_object_payload = VALUES(raw_object_payload),
            raw_person_payload = VALUES(raw_person_payload),
            fetched_by = VALUES(fetched_by),
            updated_at = CURRENT_TIMESTAMP"
    );

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'ssssssssss',
        $month,
        $interval['dateFrom'],
        $interval['dateTo'],
        $snapshotDate,
        $objektId,
        $objsId,
        $overviewJson,
        $objectJson,
        $personJson,
        $user
    );
    $stmt->execute();
    $stmt->close();

    if (explorerKontenPersonMonthlyTableExists($conn)) {
        saveExplorerKontenPersonRowsToDb($conn, $month, $overview);
    }
}

function saveExplorerKontenPersonRowsToDb(mysqli $conn, string $month, array $overview): void {
    $deleteStmt = $conn->prepare("DELETE FROM loga_konten_person_monthly WHERE month_key = ?");
    if (!$deleteStmt) {
        return;
    }
    $deleteStmt->bind_param('s', $month);
    $deleteStmt->execute();
    $deleteStmt->close();

    if (empty($overview['rows']) || !is_array($overview['rows'])) {
        return;
    }

    $snapshotDate = $overview['snapshotDate'] ?? null;
    $objektId = $overview['objektId'] ?? null;
    $objsId = $overview['objsId'] ?? null;

    $insertStmt = $conn->prepare(
        "INSERT INTO loga_konten_person_monthly (
            month_key, snapshot_date, objekt_id, objs_id, loga_person_id,
            pnr, display_name, local_name, loga_name, local_user_id, vertnr, values_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            snapshot_date = VALUES(snapshot_date),
            objekt_id = VALUES(objekt_id),
            objs_id = VALUES(objs_id),
            pnr = VALUES(pnr),
            display_name = VALUES(display_name),
            local_name = VALUES(local_name),
            loga_name = VALUES(loga_name),
            local_user_id = VALUES(local_user_id),
            vertnr = VALUES(vertnr),
            values_json = VALUES(values_json),
            updated_at = CURRENT_TIMESTAMP"
    );

    if (!$insertStmt) {
        return;
    }

    foreach ($overview['rows'] as $row) {
        $logaPersonId = isset($row['logaPersonId']) ? (int)$row['logaPersonId'] : 0;
        if ($logaPersonId <= 0) {
            continue;
        }

        $pnr = (string)($row['pnr'] ?? '');
        $displayName = $row['displayName'] ?? null;
        $localName = $row['localName'] ?? null;
        $logaName = $row['logaName'] ?? null;
        $localUserId = isset($row['localUserId']) && $row['localUserId'] !== '' ? (int)$row['localUserId'] : null;
        $vertnr = isset($row['vertnr']) && $row['vertnr'] !== '' ? (int)$row['vertnr'] : null;
        $valuesJson = json_encode($row['values'] ?? [], JSON_UNESCAPED_UNICODE);

        if ($valuesJson === false) {
            continue;
        }

        $insertStmt->bind_param(
            'ssssissssiis',
            $month,
            $snapshotDate,
            $objektId,
            $objsId,
            $logaPersonId,
            $pnr,
            $displayName,
            $localName,
            $logaName,
            $localUserId,
            $vertnr,
            $valuesJson
        );
        $insertStmt->execute();
    }

    $insertStmt->close();
}

function handleSSEStream(): void {
    // SSE endpoint for live execution output — delegates to actual mode execution
    // with logger set to SSE context
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    // Disable output buffering
    while (ob_get_level()) ob_end_flush();

    $realMode = $_GET['realMode'] ?? 'fetch';
    $dateFrom = $_GET['dateFrom'] ?? date('Y-m-01');
    $dateTo = $_GET['dateTo'] ?? date('Y-m-t');
    $forceRefresh = filter_var($_GET['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $strategy = $_GET['strategy'] ?? 'sync-clean-only';
    $resolutions = !empty($_GET['resolutions']) ? json_decode($_GET['resolutions'], true) : [];
    $previewOnly = filter_var($_GET['preview'] ?? false, FILTER_VALIDATE_BOOLEAN);

    echo "event: start\ndata: " . json_encode(['mode' => $realMode, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo]) . "\n\n";
    flush();

    // Set logger to SSE mode
    $logger = LogaLogger::getInstance();
    $logger->setContext('sse');

    try {
        executeMode($realMode, $dateFrom, $dateTo, $forceRefresh, $strategy, $resolutions, $previewOnly, 'api');
    } catch (\Exception $e) {
        echo "event: error\ndata: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
        flush();
    }

    echo "event: done\ndata: {}\n\n";
    flush();
}


// ═══════════════════════════════════════════════════════════════════════════
// Dashboard Rendering
// ═══════════════════════════════════════════════════════════════════════════

function renderDashboard(): void {
    global $isLoggedIn;
    $userName = $_SESSION['username'] ?? $_SESSION['name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LOGA Portal - DienstPlan</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --loga-bg-start: #f5f7fb;
            --loga-bg-end: #eef2f8;
            --loga-surface: #ffffff;
            --loga-sidebar-start: #133c55;
            --loga-sidebar-end: #0f2f43;
            --loga-accent: #ff7a59;
            --loga-accent-strong: #ff5a2a;
            --loga-shadow: 0 10px 30px rgba(12, 33, 53, 0.08);
            --sidebar-width: 240px;
            --dp-primary: #4e73df;
            --dp-success: #1cc88a;
            --dp-warning: #f6c23e;
            --dp-danger: #e74a3b;
            --dp-info: #36b9cc;
            --dp-dark: #5a5c69;
        }
        body {
            background:
                radial-gradient(circle at top right, rgba(255, 122, 89, 0.15), transparent 38%),
                linear-gradient(180deg, var(--loga-bg-start), var(--loga-bg-end));
            font-family: 'Plus Jakarta Sans', 'Trebuchet MS', sans-serif;
        }
        .app-shell {
            min-height: 100vh;
            position: relative;
            width: 100%;
        }
        .sidebar {
            background: linear-gradient(180deg, var(--loga-sidebar-start), var(--loga-sidebar-end));
            min-height: 100vh;
            width: var(--sidebar-width);
            flex: 0 0 var(--sidebar-width);
            box-shadow: var(--loga-shadow);
            z-index: 1025;
        }
        .sidebar .nav-link { color: rgba(255,255,255,.8); padding: .75rem 1rem; font-size: .85rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            color: #fff;
            background: rgba(255,255,255,.14);
            border-radius: .6rem;
        }
        .sidebar .nav-link i { width: 1.5rem; }
        .mobile-topbar {
            position: sticky;
            top: 0;
            z-index: 1015;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(6px);
            border-radius: .85rem;
            box-shadow: 0 6px 16px rgba(15, 35, 58, 0.08);
        }
        .mobile-topbar .btn {
            border-color: #d8dee7;
            color: #0f2f43;
        }
        .mobile-user-pill {
            background: #f0f4f9;
            border-radius: 999px;
            font-size: .75rem;
            color: #2f4f65;
            padding: .25rem .75rem;
        }
        .main-content {
            flex: 1 1 auto;
            min-width: 0;
            max-width: none;
            width: auto;
        }
        .card {
            border: 0;
            border-radius: .9rem;
            box-shadow: var(--loga-shadow);
            background: var(--loga-surface);
        }
        .card-stat { border-left: .25rem solid; }
        .card-stat.primary { border-left-color: var(--dp-primary); }
        .card-stat.success { border-left-color: var(--dp-success); }
        .card-stat.warning { border-left-color: var(--dp-warning); }
        .card-stat.danger { border-left-color: var(--dp-danger); }
        .card-stat .stat-value { font-size: 1.5rem; font-weight: 700; }
        .console-output {
            background: #1e1e1e; color: #d4d4d4; font-family: 'Cascadia Code', 'Fira Code', monospace;
            font-size: .8rem; height: 400px; overflow-y: auto; padding: 1rem; border-radius: .5rem;
        }
        .console-output .log-error { color: #f48771; }
        .console-output .log-info { color: #6a9955; }
        .console-output .log-debug { color: #569cd6; }
        .console-output .log-time { color: #858585; }
        .console-output .log-source { color: #dcdcaa; }
        .conflict-table th { font-size: .8rem; }
        .conflict-table td { font-size: .8rem; vertical-align: middle; }
        .conflict-table .badge { font-size: .7rem; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .status-dot.green { background: var(--dp-success); }
        .status-dot.yellow { background: var(--dp-warning); }
        .status-dot.red { background: var(--dp-danger); }
        .status-dot.gray { background: #ccc; }
        .btn-mode { min-width: 140px; }
        .mode-actions {
            display: grid;
            gap: .5rem;
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
        #lockOverlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,.3); z-index: 9999; align-items: center; justify-content: center;
        }
        #lockOverlay.show { display: flex; }
        #lockOverlay .spinner { width: 3rem; height: 3rem; }
        .history-table { font-size: .8rem; }
        .history-table .badge { font-size: .7rem; }
        .section-title { font-size: 1.1rem; font-weight: 600; color: var(--dp-dark); }
        .subnav-tabs .nav-link { color: var(--dp-dark); }
        .subnav-tabs .nav-link.active { font-weight: 600; }
        .konten-name { min-width: 240px; }
        .konten-secondary { font-size: .72rem; color: #858796; }
        .explorer-output {
            height: 500px;
            white-space: pre-wrap;
            font-size: .75rem;
        }
        .raw-data-output {
            height: 350px;
            white-space: pre-wrap;
            font-size: .7rem;
        }
        .mobile-bottom-nav {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(255, 255, 255, .95);
            backdrop-filter: blur(7px);
            border-top: 1px solid #dce4ef;
            padding: .45rem .4rem calc(.45rem + env(safe-area-inset-bottom));
            z-index: 1040;
            display: none;
        }
        .mobile-bottom-nav .nav-link {
            color: #6d7f92;
            border-radius: .6rem;
            padding: .35rem .15rem;
            font-size: .72rem;
            text-align: center;
            line-height: 1.2;
            min-height: 44px;
        }
        .mobile-bottom-nav .nav-link i {
            display: block;
            font-size: 1rem;
            margin-bottom: .2rem;
        }
        .mobile-bottom-nav .nav-link.active {
            background: #eef5ff;
            color: var(--dp-primary);
            font-weight: 700;
        }
        .mobile-sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(5, 16, 28, .45);
            z-index: 1020;
        }
        @media (max-width: 1200px) {
            .mode-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (max-width: 900px) {
            .sidebar {
                position: fixed;
                top: 0;
                bottom: 0;
                left: 0;
                transform: translateX(-100%);
                transition: transform .28s ease;
                min-height: 100dvh;
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .mobile-sidebar-backdrop.show {
                display: block;
            }
            .main-content {
                max-width: 100%;
                padding: 1rem 1rem 5.4rem !important;
            }
            .mobile-bottom-nav {
                display: block;
            }
            .btn-mode {
                min-width: 0;
                width: 100%;
                min-height: 44px;
                font-size: .82rem;
            }
            .console-output {
                height: 290px;
            }
            .explorer-output {
                height: 320px;
                font-size: .7rem;
            }
            .raw-data-output {
                height: 260px;
                font-size: .68rem;
            }
            .konten-name {
                min-width: 160px;
                max-width: 190px;
            }
            .history-table {
                font-size: .76rem;
            }
        }
        @media (max-width: 576px) {
            .mode-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .main-content {
                padding: .85rem .75rem 5.2rem !important;
            }
            .history-table {
                font-size: .72rem;
            }
            .conflict-table th,
            .conflict-table td {
                font-size: .74rem;
            }
            .konten-name {
                min-width: 0;
                max-width: 145px;
            }
            .console-output {
                height: 240px;
                font-size: .75rem;
            }
            .mobile-topbar {
                margin-bottom: .85rem;
            }
            #panel-history .history-table th:nth-child(5),
            #panel-history .history-table td:nth-child(5),
            #panel-history .history-table th:nth-child(6),
            #panel-history .history-table td:nth-child(6),
            #panel-history .history-table th:nth-child(7),
            #panel-history .history-table td:nth-child(7),
            #panel-history .history-table th:nth-child(8),
            #panel-history .history-table td:nth-child(8),
            #panel-history .history-table th:nth-child(9),
            #panel-history .history-table td:nth-child(9),
            #panel-history .history-table th:nth-child(10),
            #panel-history .history-table td:nth-child(10) {
                display: none;
            }
            #panel-persons .history-table th:nth-child(3),
            #panel-persons .history-table td:nth-child(3),
            #panel-persons .history-table th:nth-child(4),
            #panel-persons .history-table td:nth-child(4),
            #panel-persons .history-table th:nth-child(5),
            #panel-persons .history-table td:nth-child(5),
            #panel-persons .history-table th:nth-child(7),
            #panel-persons .history-table td:nth-child(7),
            #panel-persons .history-table th:nth-child(8),
            #panel-persons .history-table td:nth-child(8),
            #panel-persons .history-table th:nth-child(9),
            #panel-persons .history-table td:nth-child(9) {
                display: none;
            }
        }
    </style>
</head>
<body>
<?php
$navbarActivePage = 'loga';
$navbarBasePath = '..';
$navbarBrand = 'LOGA Portal';
require dirname(__DIR__) . '/navbar.php';
?>
<div class="d-flex app-shell">
    <div id="mobileSidebarBackdrop" class="mobile-sidebar-backdrop"></div>
    <!-- Sidebar -->
    <div class="sidebar d-flex flex-column p-3" id="logaSidebar">
        <div class="d-flex justify-content-between align-items-center d-lg-none mb-2">
            <div class="text-white fw-semibold">Navigation</div>
            <button type="button" class="btn btn-sm btn-outline-light" id="mobileCloseSidebar"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="text-white text-center mb-4 mt-2">
            <i class="bi bi-cloud-arrow-down-fill fs-2"></i>
            <div class="fw-bold mt-1">LOGA Portal</div>
            <small class="opacity-75"><?= htmlspecialchars($userName) ?></small>
        </div>
        <ul class="nav flex-column">
            <li class="nav-item"><a class="nav-link panel-nav-link active" href="#" data-panel="execute"><i class="bi bi-play-circle"></i> Execute</a></li>
            <li class="nav-item"><a class="nav-link panel-nav-link" href="#" data-panel="conflicts"><i class="bi bi-exclamation-triangle"></i> Conflicts</a></li>
            <li class="nav-item"><a class="nav-link panel-nav-link" href="#" data-panel="history"><i class="bi bi-clock-history"></i> History</a></li>
            <li class="nav-item"><a class="nav-link panel-nav-link" href="#" data-panel="cache"><i class="bi bi-database"></i> Cache</a></li>
            <li class="nav-item"><a class="nav-link panel-nav-link" href="#" data-panel="stats"><i class="bi bi-graph-up"></i> Statistics</a></li>
            <li class="nav-item mt-2"><hr class="border-light opacity-25 my-1"></li>
            <li class="nav-item"><a class="nav-link panel-nav-link" href="#" data-panel="persons"><i class="bi bi-people"></i> Persons</a></li>
            <li class="nav-item"><a class="nav-link panel-nav-link" href="#" data-panel="explorer"><i class="bi bi-binoculars"></i> Explorer</a></li>
        </ul>
        <div class="mt-auto">
            <a class="nav-link text-white-50" href="../"><i class="bi bi-arrow-left"></i> DienstPlan</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-grow-1 p-4 main-content">

        <div class="mobile-topbar d-lg-none p-2 mb-3">
            <div class="d-flex align-items-center justify-content-between">
                <button type="button" class="btn btn-sm" id="mobileMenuToggle"><i class="bi bi-list"></i> Menue</button>
                <div class="text-center flex-grow-1">
                    <div class="fw-bold small">LOGA Mobile</div>
                    <span class="mobile-user-pill"><?= htmlspecialchars($userName) ?></span>
                </div>
                <button type="button" class="btn btn-sm panel-nav-link" data-panel="execute"><i class="bi bi-lightning-charge"></i></button>
            </div>
        </div>

        <!-- Status Bar -->
        <div class="row g-3 mb-4" id="statusCards">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card card-stat primary shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-xs text-uppercase text-muted fw-bold mb-1">Status</div>
                        <div id="statusIndicator"><span class="status-dot gray"></span> <span class="text-muted">Idle</span></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card card-stat success shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-xs text-uppercase text-muted fw-bold mb-1">Last Run</div>
                        <div id="lastRunInfo" class="text-muted small">-</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card card-stat warning shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-xs text-uppercase text-muted fw-bold mb-1">Cooldown</div>
                        <div id="cooldownInfo" class="text-muted small">Ready</div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card card-stat danger shadow-sm">
                    <div class="card-body py-2">
                        <div class="text-xs text-uppercase text-muted fw-bold mb-1">Cache</div>
                        <div id="cacheInfoBar" class="text-muted small">-</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Panel: Execute ══════════════════════════════════════════════ -->
        <div class="panel" id="panel-execute">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white"><span class="section-title"><i class="bi bi-gear"></i> Configuration</span></div>
                <div class="card-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-6 col-md-2">
                            <label class="form-label small fw-bold">Date From</label>
                            <input type="date" class="form-control form-control-sm" id="dateFrom" value="<?= date('Y-m-01') ?>">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small fw-bold">Date To</label>
                            <input type="date" class="form-control form-control-sm" id="dateTo" value="<?= date('Y-m-t') ?>">
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-bold">Strategy</label>
                            <select class="form-select form-select-sm" id="strategy">
                                <option value="sync-clean-only">Sync Clean Only</option>
                                <option value="keep-local-all">Keep Local All</option>
                                <option value="keep-remote-all">Keep Remote All</option>
                                <option value="per-conflict">Per Conflict</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" id="forceRefresh">
                                <label class="form-check-label small" for="forceRefresh">Force Refresh</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="previewOnly">
                                <label class="form-check-label small" for="previewOnly">Preview Only</label>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="mode-actions">
                        <button class="btn btn-info btn-sm btn-mode text-white" onclick="runMode('pull-shifts')"><i class="bi bi-arrow-down-circle"></i> Pull Shifts</button>
                        <button class="btn btn-warning btn-sm btn-mode" onclick="runMode('push-shifts')"><i class="bi bi-arrow-up-circle"></i> Push Shifts</button>
                        <button class="btn btn-danger btn-sm btn-mode" onclick="runMode('pull-absences')"><i class="bi bi-calendar-x"></i> Pull Absences</button>
                        <button class="btn btn-primary btn-sm btn-mode" onclick="runMode('fetch')"><i class="bi bi-cloud-download"></i> Fetch</button>
                        <button class="btn btn-success btn-sm btn-mode" onclick="runMode('process')"><i class="bi bi-cpu"></i> Process</button>
                        <button class="btn btn-secondary btn-sm btn-mode" onclick="runMode('pull-all')"><i class="bi bi-arrow-repeat"></i> Pull All</button>
                    </div>
                    <div class="small text-muted mt-2">
                        Standard bei Pull/Push Workflows: erst Preview, danach automatische Sync wenn konfliktfrei.
                    </div>
                </div>
            </div>

            <!-- Console Output -->
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="section-title"><i class="bi bi-terminal"></i> Execution Console</span>
                    <button class="btn btn-outline-secondary btn-sm" onclick="clearConsole()"><i class="bi bi-trash"></i> Clear</button>
                </div>
                <div class="card-body p-0">
                    <div class="console-output" id="consoleOutput">
                        <div class="text-muted">Ready. Select a mode and click to execute.</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Panel: Conflicts ════════════════════════════════════════════ -->
        <div class="panel d-none" id="panel-conflicts">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="section-title"><i class="bi bi-exclamation-triangle"></i> Conflict Resolution</span>
                    <div>
                        <button class="btn btn-outline-primary btn-sm" onclick="bulkResolve('keep-local')">All Keep Local</button>
                        <button class="btn btn-outline-danger btn-sm" onclick="bulkResolve('keep-remote')">All Keep Remote</button>
                        <button class="btn btn-success btn-sm" onclick="applyResolutions()"><i class="bi bi-check-lg"></i> Apply</button>
                    </div>
                </div>
                <div class="card-body">
                    <div id="conflictsContainer">
                        <p class="text-muted">No conflicts detected. Run a preview first to check for conflicts.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Panel: History ══════════════════════════════════════════════ -->
        <div class="panel d-none" id="panel-history">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="section-title"><i class="bi bi-clock-history"></i> Sync History</span>
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadHistory()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover history-table mb-0">
                            <thead class="table-light">
                                <tr><th>Time</th><th>Mode</th><th>Range</th><th>Status</th><th>Duration</th><th>Persons</th><th>Shifts</th><th>Absences</th><th>Errors</th><th>By</th></tr>
                            </thead>
                            <tbody id="historyBody"><tr><td colspan="10" class="text-center text-muted py-3">Loading...</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Panel: Cache ══════════════════════════════════════════════ -->
        <div class="panel d-none" id="panel-cache">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="section-title"><i class="bi bi-database"></i> Cache Status</span>
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadCacheStatus()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                </div>
                <div class="card-body">
                    <div id="cacheContainer">
                        <p class="text-muted">Loading...</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Panel: Stats ════════════════════════════════════════════════ -->
        <div class="panel d-none" id="panel-stats">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><span class="section-title"><i class="bi bi-graph-up"></i> Performance Statistics</span></div>
                <div class="card-body">
                    <canvas id="statsChart" style="max-height:350px"></canvas>
                </div>
            </div>
        </div>

        <!-- ═══ Panel: Persons ══════════════════════════════════════════════ -->
        <div class="panel d-none" id="panel-persons">
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="section-title"><i class="bi bi-people"></i> Persons</span>
                    <div>
                        <button class="btn btn-outline-secondary btn-sm" onclick="loadPersons()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                        <button class="btn btn-outline-primary btn-sm" onclick="syncPersons()"><i class="bi bi-database-add"></i> Sync from Cache</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-sm-6 col-md-3">
                            <input type="text" class="form-control form-control-sm" id="personsSearch" placeholder="Search name or PNR...">
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <select class="form-select form-select-sm" id="personsFilter">
                                <option value="all">All Persons</option>
                                <option value="mapped">Mapped only</option>
                                <option value="unmapped">Unmapped only</option>
                                <option value="special">Special only</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6 col-md-3">
                            <select class="form-select form-select-sm" id="personsOrderBy" onchange="loadPersons()">
                                <option value="full_name">Sort by Name</option>
                                <option value="pnr">Sort by PNR</option>
                                <option value="last_seen_at">Sort by Last Seen</option>
                                <option value="shift_count">Sort by Shifts</option>
                                <option value="absence_count">Sort by Absences</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-auto"><span class="badge bg-secondary">Total: <span id="pStatTotal">-</span></span></div>
                        <div class="col-auto"><span class="badge bg-success">Mapped: <span id="pStatMapped">-</span></span></div>
                        <div class="col-auto"><span class="badge bg-warning text-dark">Unmapped: <span id="pStatUnmapped">-</span></span></div>
                        <div class="col-auto"><span class="badge bg-info">Special: <span id="pStatSpecial">-</span></span></div>
                        <div class="col-auto"><span class="badge bg-primary">Shifts: <span id="pStatShifts">-</span></span></div>
                        <div class="col-auto"><span class="badge bg-primary">Absences: <span id="pStatAbsences">-</span></span></div>
                    </div>

                    <!-- Persons Table -->
                    <div class="table-responsive">
                        <table class="table table-sm table-hover history-table mb-0">
                            <thead class="table-light">
                                <tr><th>PNR</th><th>Name</th><th>Valid From</th><th>Valid To</th><th>Local ID</th><th>Type</th><th>Shifts</th><th>Absences</th><th>Last Seen</th><th></th></tr>
                            </thead>
                            <tbody id="personsBody"><tr><td colspan="10" class="text-center text-muted py-3">Click "Sync from Cache" to populate, or "Refresh" to reload.</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Person Detail Modal -->
            <div class="modal fade" id="personDetailModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h6 class="modal-title"><i class="bi bi-person-badge"></i> Person Detail</h6>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="personDetailBody" style="max-height:70vh;overflow-y:auto">
                            <p class="text-muted">Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Panel: Explorer ═════════════════════════════════════════════ -->
        <div class="panel d-none" id="panel-explorer">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="section-title"><i class="bi bi-binoculars"></i> API Explorer</span>
                    <button class="btn btn-outline-secondary btn-sm" onclick="loadExplorerHistory()"><i class="bi bi-clock-history"></i> History</button>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs subnav-tabs mb-3">
                        <li class="nav-item">
                            <button class="nav-link active" type="button" data-explorer-section="api" onclick="switchExplorerSection('api')">API Endpoints</button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" type="button" data-explorer-section="konten" onclick="switchExplorerSection('konten')">Konten Übersicht</button>
                        </li>
                    </ul>

                    <div id="explorerSectionApi">
                    <p class="small text-muted mb-3">
                        Explore LOGA API endpoints to inspect raw responses. Data is saved to DB for later analysis. Select an endpoint and click Fetch.
                    </p>

                    <!-- Endpoint Selector -->
                    <div id="explorerEndpoints" class="mb-3">
                        <p class="text-muted small">Loading endpoints...</p>
                    </div>

                    <!-- Result Display -->
                    <div id="explorerResult" class="d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="small fw-bold" id="explorerResultLabel">-</span>
                            <div>
                                <span class="badge bg-secondary" id="explorerResultMeta">-</span>
                                <button class="btn btn-outline-secondary btn-sm ms-1" onclick="copyExplorerResult()"><i class="bi bi-clipboard"></i></button>
                                <button class="btn btn-outline-secondary btn-sm" onclick="toggleExplorerRaw()"><i class="bi bi-code"></i> Toggle Raw</button>
                            </div>
                        </div>
                        <div id="explorerResultFormatted" class="console-output explorer-output"></div>
                    </div>
                    </div>

                    <div id="explorerSectionKonten" class="d-none">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <p class="small text-muted mb-0">
                                Kombiniert Person-Kontodaten mit den Titeln aus Objekt Konten Summe und mappt PNR auf lokale Mitarbeiternamen.
                            </p>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <input type="month" class="form-control form-control-sm" id="kontenMonth" value="<?= date('Y-m') ?>" onchange="loadExplorerKontenOverview()" style="max-width: 180px;">
                                <button class="btn btn-outline-secondary btn-sm" onclick="loadExplorerKontenOverview()"><i class="bi bi-database"></i> Laden</button>
                                <button class="btn btn-outline-primary btn-sm" onclick="loadExplorerKontenOverview(true)"><i class="bi bi-arrow-repeat"></i> Neu von LOGA</button>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-12 col-md-4">
                                <input type="text" class="form-control form-control-sm" id="kontenSearch" placeholder="Suche Name oder PNR..." oninput="renderKontenOverview()">
                            </div>
                            <div class="col-12 col-md-8 d-flex align-items-center">
                                <div class="small text-muted" id="kontenMeta">Noch nicht geladen.</div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3" id="kontenTotals">
                            <div class="col-12"><p class="text-muted small mb-0">Noch keine Summen geladen.</p></div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover history-table mb-0 align-middle">
                                <thead class="table-light" id="kontenHead">
                                    <tr><th>Name</th><th>PNR</th></tr>
                                </thead>
                                <tbody id="kontenBody">
                                    <tr><td colspan="2" class="text-center text-muted py-3">Monat wählen und auf Laden klicken, um die Konten-Übersicht zu öffnen.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Explorer History -->
            <div class="card shadow-sm d-none" id="explorerHistoryCard">
                <div class="card-header bg-white"><span class="section-title"><i class="bi bi-clock-history"></i> Previous Fetches</span></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover history-table mb-0">
                            <thead class="table-light">
                                <tr><th>Time</th><th>Endpoint</th><th>Status</th><th>Size</th><th>Duration</th><th>By</th><th></th></tr>
                            </thead>
                            <tbody id="explorerHistoryBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<nav class="mobile-bottom-nav" aria-label="Mobile Primary Navigation">
    <div class="d-flex justify-content-between gap-1">
        <a href="#" class="nav-link panel-nav-link active" data-panel="execute"><i class="bi bi-play-circle"></i>Execute</a>
        <a href="#" class="nav-link panel-nav-link" data-panel="conflicts"><i class="bi bi-exclamation-triangle"></i>Conflicts</a>
        <a href="#" class="nav-link panel-nav-link" data-panel="persons"><i class="bi bi-people"></i>Persons</a>
        <a href="#" class="nav-link panel-nav-link" data-panel="explorer"><i class="bi bi-binoculars"></i>Explorer</a>
        <a href="#" class="nav-link panel-nav-link" data-panel="stats"><i class="bi bi-graph-up"></i>Stats</a>
    </div>
</nav>

<!-- Lock Overlay -->
<div id="lockOverlay">
    <div class="text-center text-white">
        <div class="spinner-border spinner text-light mb-3" role="status"></div>
        <div id="lockMessage">Processing...</div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../js/nav-cache-refresh.js"></script>
<script>
// ─── State ──────────────────────────────────────────────────────────────────
let currentConflicts = [];
let conflictResolutions = {};
let statsChart = null;
let statusTimer = null;
const mobileBreakpoint = window.matchMedia('(max-width: 900px)');
const panelLinks = Array.from(document.querySelectorAll('.panel-nav-link[data-panel]'));

// ─── Navigation ─────────────────────────────────────────────────────────────
function closeMobileSidebar() {
    document.getElementById('logaSidebar')?.classList.remove('show');
    document.getElementById('mobileSidebarBackdrop')?.classList.remove('show');
}

function openMobileSidebar() {
    document.getElementById('logaSidebar')?.classList.add('show');
    document.getElementById('mobileSidebarBackdrop')?.classList.add('show');
}

function syncPanelNavState(panel) {
    panelLinks.forEach(link => {
        link.classList.toggle('active', link.dataset.panel === panel);
    });
}

function triggerPanelLoad(panel) {
    if (panel === 'history') loadHistory();
    if (panel === 'cache') loadCacheStatus();
    if (panel === 'stats') loadChart();
    if (panel === 'persons') loadPersons();
    if (panel === 'explorer') initExplorerPanel();
}

function setActivePanel(panel) {
    document.querySelectorAll('.panel').forEach(p => p.classList.add('d-none'));
    const target = document.getElementById('panel-' + panel);
    if (!target) return;

    target.classList.remove('d-none');
    syncPanelNavState(panel);
    triggerPanelLoad(panel);

    if (mobileBreakpoint.matches) {
        closeMobileSidebar();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
}

panelLinks.forEach(link => {
    link.addEventListener('click', e => {
        e.preventDefault();
        setActivePanel(link.dataset.panel);
    });
});

document.getElementById('mobileMenuToggle')?.addEventListener('click', () => {
    if (document.getElementById('logaSidebar')?.classList.contains('show')) {
        closeMobileSidebar();
        return;
    }
    openMobileSidebar();
});
document.getElementById('mobileCloseSidebar')?.addEventListener('click', closeMobileSidebar);
document.getElementById('mobileSidebarBackdrop')?.addEventListener('click', closeMobileSidebar);
mobileBreakpoint.addEventListener('change', e => {
    if (!e.matches) {
        closeMobileSidebar();
    }
});

// ─── Mode Execution ─────────────────────────────────────────────────────────
function runMode(mode) {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const force = document.getElementById('forceRefresh').checked;
    const preview = document.getElementById('previewOnly').checked;
    const strategy = document.getElementById('strategy').value;

    clearConsole();
    appendConsole('info', `Starting ${mode} (${dateFrom} → ${dateTo})...`, 'UI');
    showLock(`Running: ${mode}`);

    const params = new URLSearchParams({
        mode: mode,
        dateFrom: dateFrom,
        dateTo: dateTo,
        force: force ? '1' : '0',
        preview: preview ? '1' : '0',
        strategy: strategy,
        format: 'json'
    });

    // If per-conflict, include resolutions
    if (strategy === 'per-conflict' && Object.keys(conflictResolutions).length > 0) {
        params.set('resolutions', JSON.stringify(conflictResolutions));
    }

    fetch('?' + params.toString())
        .then(r => r.json())
        .then(data => {
            hideLock();
            if (data.error) {
                appendConsole('error', data.error, 'System');
            } else {
                appendConsole('info', `${mode} completed successfully`, 'System');
            }

            // Show logs
            if (data.logs) {
                data.logs.forEach(log => {
                    appendConsole(log.level.toLowerCase(), log.message, log.source);
                });
            }

            // Handle preview results
            if (data.preview && data.differences) {
                currentConflicts = data.differences;
                renderConflicts(data.differences, 'shift');
                appendConsole('info', `Found ${data.differences.length} shift differences`, 'UI');
            }
            if (data.preview && data.conflicts) {
                currentConflicts = data.conflicts;
                renderConflicts(data.conflicts, 'absence');
                appendConsole('info', `Found ${data.conflicts.length} absence conflicts`, 'UI');
            }

            // Refresh status
            loadStatus();
        })
        .catch(err => {
            hideLock();
            appendConsole('error', 'Request failed: ' + err.message, 'UI');
        });
}

// ─── Console ────────────────────────────────────────────────────────────────
function clearConsole() {
    document.getElementById('consoleOutput').innerHTML = '';
}

function appendConsole(level, message, source) {
    const el = document.getElementById('consoleOutput');
    const time = new Date().toLocaleTimeString('de-DE');
    const cls = level === 'error' ? 'log-error' : (level === 'debug' ? 'log-debug' : 'log-info');
    el.innerHTML += `<div><span class="log-time">[${time}]</span> <span class="log-source">[${source || '-'}]</span> <span class="${cls}">${escapeHtml(message)}</span></div>`;
    el.scrollTop = el.scrollHeight;
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ─── Conflict Resolution ────────────────────────────────────────────────────
function renderConflicts(items, type) {
    if (!items || items.length === 0) {
        document.getElementById('conflictsContainer').innerHTML = '<p class="text-success"><i class="bi bi-check-circle"></i> No conflicts detected.</p>';
        return;
    }

    conflictResolutions = {};
    let html = `<div class="alert alert-warning small"><i class="bi bi-exclamation-triangle"></i> ${items.length} conflict(s) require resolution.</div>`;
    html += '<div class="table-responsive"><table class="table table-sm conflict-table"><thead class="table-light"><tr>';
    html += '<th>User</th><th>Date</th>';

    if (type === 'shift') {
        html += '<th>Local</th><th></th><th>LOGA</th><th>Action</th>';
    } else {
        html += '<th>Current Shift</th><th></th><th>Absence</th><th>Action</th>';
    }
    html += '<th>Resolution</th></tr></thead><tbody>';

    items.forEach((item, idx) => {
        const key = item.key;
        conflictResolutions[key] = 'skip';
        const actionBadge = (item.action === 'delete') ? '<span class="badge bg-danger">DELETE</span>' :
                           (item.action === 'insert') ? '<span class="badge bg-success">INSERT</span>' :
                           '<span class="badge bg-warning text-dark">UPDATE</span>';

        if (type === 'shift') {
            html += `<tr>
                <td>${escapeHtml(item.userName)}</td>
                <td>${item.date}</td>
                <td><span class="badge bg-secondary">${item.localShift || '-'}</span></td>
                <td>→</td>
                <td><span class="badge bg-primary">${item.cloudShift || '-'}</span></td>
                <td>${actionBadge}</td>
                <td><select class="form-select form-select-sm" data-key="${key}" onchange="setResolution('${key}', this.value)" style="width:140px">
                    <option value="skip">Skip</option>
                    <option value="keep-local">Keep Local</option>
                    <option value="keep-remote">Keep Remote</option>
                </select></td>
            </tr>`;
        } else {
            html += `<tr>
                <td>${escapeHtml(item.userName)}</td>
                <td>${item.date}</td>
                <td><span class="badge bg-info text-dark">${item.currentShift || '-'}</span></td>
                <td>→</td>
                <td><span class="badge bg-warning text-dark">${item.absenceSymbol || '-'}</span></td>
                <td>${actionBadge}</td>
                <td><select class="form-select form-select-sm" data-key="${key}" onchange="setResolution('${key}', this.value)" style="width:140px">
                    <option value="skip">Skip</option>
                    <option value="keep-local">Keep Local</option>
                    <option value="keep-remote">Keep Remote</option>
                </select></td>
            </tr>`;
        }
    });

    html += '</tbody></table></div>';
    document.getElementById('conflictsContainer').innerHTML = html;

    // Switch to conflicts panel
    setActivePanel('conflicts');
}

function setResolution(key, value) {
    conflictResolutions[key] = value;
}

function bulkResolve(action) {
    Object.keys(conflictResolutions).forEach(key => {
        conflictResolutions[key] = action;
    });
    document.querySelectorAll('.conflict-table select').forEach(sel => {
        sel.value = action;
    });
}

function applyResolutions() {
    // Re-run the last mode with per-conflict strategy and resolutions
    document.getElementById('strategy').value = 'per-conflict';
    document.getElementById('previewOnly').checked = false;

    const lastMode = currentConflicts.length > 0 && currentConflicts[0].absenceSymbol ? 'pull-absences' : 'pull-shifts';
    appendConsole('info', `Applying ${Object.keys(conflictResolutions).length} resolutions with per-conflict strategy`, 'UI');
    runMode(lastMode);
}

// ─── Status Polling ─────────────────────────────────────────────────────────
function loadStatus() {
    fetch('?mode=status&format=json')
        .then(r => r.json())
        .then(data => {
            // Status indicator
            const si = document.getElementById('statusIndicator');
            if (data.locked) {
                const info = data.lockInfo || {};
                si.innerHTML = `<span class="status-dot yellow"></span> <span class="text-warning">Running: ${info.mode || '?'}</span>`;
            } else {
                si.innerHTML = `<span class="status-dot green"></span> <span class="text-success">Idle</span>`;
            }

            // Last run
            const lr = document.getElementById('lastRunInfo');
            if (data.lastRun) {
                lr.innerHTML = `${data.lastRun.mode} <span class="badge ${data.lastRun.status === 'success' ? 'bg-success' : 'bg-danger'}">${data.lastRun.status}</span> ${data.lastRun.created_at}`;
            }

            // Cooldown
            const cd = document.getElementById('cooldownInfo');
            if (data.cooldown > 0) {
                cd.innerHTML = `<span class="text-warning">${data.cooldown}s remaining</span>`;
            } else {
                cd.innerHTML = `<span class="text-success">Ready</span>`;
            }

            // Cache
            const ci = document.getElementById('cacheInfoBar');
            if (data.cacheStatus && data.cacheStatus.months) {
                const months = Object.keys(data.cacheStatus.months);
                const stale = months.filter(m => data.cacheStatus.months[m].stale).length;
                ci.innerHTML = `${months.length} months cached, ${stale} stale`;
            }
        })
        .catch(() => {});
}

// ─── History ────────────────────────────────────────────────────────────────
function loadHistory() {
    fetch('?mode=history&format=json&limit=50')
        .then(r => r.json())
        .then(rows => {
            const tbody = document.getElementById('historyBody');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">No sync history yet.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => `<tr>
                <td>${r.created_at}</td>
                <td><span class="badge bg-primary">${r.mode}</span></td>
                <td>${r.date_from} – ${r.date_to}</td>
                <td><span class="badge ${r.status === 'success' ? 'bg-success' : 'bg-danger'}">${r.status}</span></td>
                <td>${parseFloat(r.duration_sec).toFixed(1)}s</td>
                <td>${r.persons_count || 0}</td>
                <td>${r.shifts_count || 0}</td>
                <td>${r.absences_count || 0}</td>
                <td>${r.errors_count > 0 ? '<span class="text-danger">' + r.errors_count + '</span>' : '0'}</td>
                <td>${r.triggered_by || '-'}</td>
            </tr>`).join('');
        })
        .catch(() => {});
}

// ─── Cache ──────────────────────────────────────────────────────────────────
function formatAge(seconds) {
    if (!seconds && seconds !== 0) return '-';
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
    return `${Math.floor(seconds / 86400)}d ${Math.floor((seconds % 86400) / 3600)}h`;
}

function loadCacheStatus() {
    fetch('?mode=cache-status&format=json')
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('cacheContainer');
            const months = (data.status && data.status.months) ? data.status.months : {};
            if (!data.status || !data.status.exists || Object.keys(months).length === 0) {
                container.innerHTML = '<p class="text-muted">No cached data.</p>';
                return;
            }

            let html = '<div class="table-responsive"><table class="table table-sm"><thead class="table-light"><tr><th>Month</th><th>Status</th><th>Age</th><th>Persons</th><th>Fetched</th></tr></thead><tbody>';
            Object.entries(months).forEach(([month, info]) => {
                const statusClass = info.stale ? 'bg-warning text-dark' : 'bg-success';
                const statusText = info.stale ? 'Stale' : 'Fresh';
                html += `<tr>
                    <td>${month}</td>
                    <td><span class="badge ${statusClass}">${statusText}</span></td>
                    <td>${formatAge(info.age)}</td>
                    <td>${info.persons || 0}</td>
                    <td>${info.fetchedAt || '-'}</td>
                </tr>`;
            });
            html += '</tbody></table></div>';

            // Size info
            if (data.status.totalSize) {
                const sizeKB = (data.status.totalSize / 1024).toFixed(1);
                html += `<div class="text-muted small mt-2">Total cache size: ${sizeKB} KB &middot; Last updated: ${data.status.lastUpdated || '-'}</div>`;
            }

            // Statistics
            const s = data.statistics;
            if (s && Object.keys(s).length > 0) {
                html += `<div class="row g-3 mt-2">
                    <div class="col-md-4"><div class="card bg-light"><div class="card-body py-2 text-center"><small class="text-muted">Shift Types</small><div class="fw-bold">${s.totalShiftTypes || 0}</div></div></div></div>
                    <div class="col-md-4"><div class="card bg-light"><div class="card-body py-2 text-center"><small class="text-muted">Absence Types</small><div class="fw-bold">${s.totalAbsenceTypes || 0}</div></div></div></div>
                    <div class="col-md-4"><div class="card bg-light"><div class="card-body py-2 text-center"><small class="text-muted">Months Cached</small><div class="fw-bold">${Object.keys(months).length}</div></div></div></div>
                </div>`;
            }

            container.innerHTML = html;
        })
        .catch(() => {});
}

// ─── Chart ──────────────────────────────────────────────────────────────────
function loadChart() {
    fetch('?mode=chart-data&format=json&days=30')
        .then(r => r.json())
        .then(data => {
            if (statsChart) statsChart.destroy();
            const ctx = document.getElementById('statsChart').getContext('2d');
            statsChart = new Chart(ctx, {
                type: 'line',
                data: data,
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'bottom' } },
                    scales: {
                        y: { beginAtZero: true, title: { display: true, text: 'Avg Duration (s)' } }
                    }
                }
            });
        })
        .catch(() => {});
}

// ─── Lock Overlay ───────────────────────────────────────────────────────────
function showLock(msg) {
    document.getElementById('lockMessage').textContent = msg;
    document.getElementById('lockOverlay').classList.add('show');
}
function hideLock() {
    document.getElementById('lockOverlay').classList.remove('show');
}

// ═══════════════════════════════════════════════════════════════════════════
// Persons Panel
// ═══════════════════════════════════════════════════════════════════════════

let personsSearchTimeout = null;

function loadPersons() {
    clearTimeout(personsSearchTimeout);
    personsSearchTimeout = setTimeout(() => {
        const search = document.getElementById('personsSearch').value;
        const filter = document.getElementById('personsFilter').value;
        const orderBy = document.getElementById('personsOrderBy').value;

        const params = new URLSearchParams({ mode: 'persons', format: 'json', search, filter, orderBy });
        fetch('?' + params.toString())
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    document.getElementById('personsBody').innerHTML = `<tr><td colspan="10" class="text-center text-danger py-3">${escapeHtml(data.error)}</td></tr>`;
                    return;
                }

                // Update stats
                if (data.stats) {
                    const s = data.stats;
                    document.getElementById('pStatTotal').textContent = s.total || 0;
                    document.getElementById('pStatMapped').textContent = s.mapped || 0;
                    document.getElementById('pStatUnmapped').textContent = s.unmapped || 0;
                    document.getElementById('pStatSpecial').textContent = s.special || 0;
                    document.getElementById('pStatShifts').textContent = s.total_shifts || 0;
                    document.getElementById('pStatAbsences').textContent = s.total_absences || 0;
                }

                // Render table
                const tbody = document.getElementById('personsBody');
                if (!data.persons || data.persons.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="10" class="text-center text-muted py-3">No persons found.</td></tr>';
                    return;
                }

                tbody.innerHTML = data.persons.map(p => {
                    const typeBadge = p.is_special_user == 1
                        ? '<span class="badge bg-info">Special</span>'
                        : (p.local_user_id ? '<span class="badge bg-success">Mapped</span>' : '<span class="badge bg-warning text-dark">Unmapped</span>');
                    return `<tr>
                        <td><code>${escapeHtml(p.pnr)}</code></td>
                        <td>${escapeHtml(p.full_name || '-')}</td>
                        <td>${p.valid_from || '-'}</td>
                        <td>${p.valid_to || '-'}</td>
                        <td>${p.local_user_id || '-'}</td>
                        <td>${typeBadge}</td>
                        <td>${p.shift_count}</td>
                        <td>${p.absence_count}</td>
                        <td class="small">${p.last_seen_at || '-'}</td>
                        <td><button class="btn btn-outline-primary btn-sm py-0 px-1" onclick="showPersonDetail('${p.pnr}')" title="View raw data"><i class="bi bi-eye"></i></button></td>
                    </tr>`;
                }).join('');
            })
            .catch(err => {
                document.getElementById('personsBody').innerHTML = `<tr><td colspan="10" class="text-center text-danger py-3">Error: ${err.message}</td></tr>`;
            });
    }, 300);
}

function syncPersons() {
    showLock('Syncing persons from cache...');
    fetch('?mode=persons-sync&format=json')
        .then(r => r.json())
        .then(data => {
            hideLock();
            if (data.error) {
                alert('Error: ' + data.error);
            } else {
                const r = data.result;
                alert(`Synced ${r.total} persons: ${r.inserted} inserted, ${r.updated} updated`);
                loadPersons();
            }
        })
        .catch(err => { hideLock(); alert('Error: ' + err.message); });
}

function showPersonDetail(pnr) {
    const modal = new bootstrap.Modal(document.getElementById('personDetailModal'));
    const body = document.getElementById('personDetailBody');
    body.innerHTML = '<p class="text-muted">Loading...</p>';
    modal.show();

    fetch('?mode=person-detail&format=json&pnr=' + encodeURIComponent(pnr))
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                body.innerHTML = `<p class="text-danger">${escapeHtml(data.error)}</p>`;
                return;
            }
            const p = data.person;
            let html = '<div class="row g-3">';
            html += '<div class="col-md-6">';
            html += '<table class="table table-sm"><tbody>';
            html += `<tr><th class="text-muted" style="width:120px">PNR</th><td><code>${escapeHtml(p.pnr)}</code></td></tr>`;
            html += `<tr><th class="text-muted">Name</th><td>${escapeHtml(p.full_name || '-')}</td></tr>`;
            html += `<tr><th class="text-muted">Valid From</th><td>${p.valid_from || '-'}</td></tr>`;
            html += `<tr><th class="text-muted">Valid To</th><td>${p.valid_to || '-'}</td></tr>`;
            html += `<tr><th class="text-muted">Local User ID</th><td>${p.local_user_id || '<span class="text-warning">Not mapped</span>'}</td></tr>`;
            html += `<tr><th class="text-muted">Special User</th><td>${p.is_special_user == 1 ? 'Yes' : 'No'}</td></tr>`;
            html += `<tr><th class="text-muted">Shifts</th><td>${p.shift_count}</td></tr>`;
            html += `<tr><th class="text-muted">Absences</th><td>${p.absence_count}</td></tr>`;
            html += `<tr><th class="text-muted">Last Seen</th><td>${p.last_seen_at || '-'}</td></tr>`;
            html += `<tr><th class="text-muted">Created</th><td>${p.created_at}</td></tr>`;
            html += `<tr><th class="text-muted">Updated</th><td>${p.updated_at}</td></tr>`;
            html += '</tbody></table></div>';
            html += '<div class="col-md-6">';
            html += '<h6 class="small fw-bold text-muted">Raw Data (stored summary)</h6>';
            html += '<div class="console-output raw-data-output">';
            if (p.raw_data_decoded) {
                html += escapeHtml(JSON.stringify(p.raw_data_decoded, null, 2));
            } else if (p.raw_data) {
                html += escapeHtml(p.raw_data);
            } else {
                html += '<span class="text-muted">No raw data stored</span>';
            }
            html += '</div></div></div>';
            body.innerHTML = html;
        })
        .catch(err => { body.innerHTML = `<p class="text-danger">${err.message}</p>`; });
}

// ═══════════════════════════════════════════════════════════════════════════
// Explorer Panel
// ═══════════════════════════════════════════════════════════════════════════

let explorerRawData = '';
let explorerShowRaw = false;
let explorerSection = 'api';
let kontenOverviewData = null;

function initExplorerPanel() {
    if (explorerSection === 'konten') {
        if (kontenOverviewData) {
            renderKontenOverview();
        } else {
            loadExplorerKontenOverview();
        }
        return;
    }

    loadExplorerEndpoints();
}

function switchExplorerSection(section) {
    explorerSection = section;
    document.querySelectorAll('[data-explorer-section]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.explorerSection === section);
    });
    document.getElementById('explorerSectionApi').classList.toggle('d-none', section !== 'api');
    document.getElementById('explorerSectionKonten').classList.toggle('d-none', section !== 'konten');

    if (section === 'konten') {
        if (kontenOverviewData) {
            renderKontenOverview();
        } else {
            loadExplorerKontenOverview();
        }
        return;
    }

    loadExplorerEndpoints();
}

function loadExplorerEndpoints() {
    fetch('?mode=explorer-endpoints&format=json')
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('explorerEndpoints');
            if (!data.categories) {
                container.innerHTML = '<p class="text-danger small">Failed to load endpoints</p>';
                return;
            }

            let html = '';
            Object.entries(data.categories).forEach(([category, endpoints]) => {
                html += `<div class="mb-3"><h6 class="small fw-bold text-uppercase text-muted">${escapeHtml(category)}</h6>`;
                html += '<div class="d-flex flex-wrap gap-1">';
                endpoints.forEach(ep => {
                    html += `<button class="btn btn-outline-secondary btn-sm" onclick="explorerFetch('${ep.key}')" title="${escapeHtml(ep.path)}">`;
                    html += `<i class="bi bi-download"></i> ${escapeHtml(ep.label)}</button>`;
                });
                html += '</div></div>';
            });

            container.innerHTML = html;
        })
        .catch(err => {
            document.getElementById('explorerEndpoints').innerHTML = `<p class="text-danger small">${err.message}</p>`;
        });
}

function explorerFetch(endpointKey) {
    const resultDiv = document.getElementById('explorerResult');
    const formatted = document.getElementById('explorerResultFormatted');
    resultDiv.classList.remove('d-none');
    formatted.textContent = 'Fetching from LOGA...';
    document.getElementById('explorerResultLabel').textContent = endpointKey;
    document.getElementById('explorerResultMeta').textContent = '...';
    explorerShowRaw = false;

    showLock('Fetching: ' + endpointKey);

    fetch('?mode=explorer-fetch&format=json&endpoint=' + encodeURIComponent(endpointKey))
        .then(r => r.json())
        .then(data => {
            hideLock();

            if (data.error) {
                formatted.innerHTML = `<span class="log-error">${escapeHtml(data.error)}</span>`;
                return;
            }

            document.getElementById('explorerResultLabel').textContent = data.label || endpointKey;
            document.getElementById('explorerResultMeta').textContent =
                `HTTP ${data.httpStatus} | ${formatBytes(data.size)} | ${data.durationMs}ms`;

            explorerRawData = data.raw || JSON.stringify(data.data, null, 2);

            // Show formatted JSON
            if (data.isJson && data.data) {
                formatted.textContent = JSON.stringify(data.data, null, 2);
                syntaxHighlight(formatted);
            } else {
                formatted.textContent = data.raw || '(empty response)';
            }
        })
        .catch(err => {
            hideLock();
            formatted.innerHTML = `<span class="log-error">Error: ${escapeHtml(err.message)}</span>`;
        });
}

function loadExplorerKontenOverview(forceRefresh = false) {
    const meta = document.getElementById('kontenMeta');
    const month = document.getElementById('kontenMonth')?.value || new Date().toISOString().slice(0, 7);
    meta.textContent = forceRefresh
        ? 'Lade Konten-Übersicht neu aus LOGA...'
        : 'Lade Konten-Übersicht aus Datenbank oder LOGA...';
    document.getElementById('kontenBody').innerHTML = '<tr><td colspan="2" class="text-center text-muted py-3">Lade Daten...</td></tr>';
    showLock('Lade Konten-Übersicht...');

    const params = new URLSearchParams({ mode: 'explorer-konten-overview', format: 'json', month });
    if (forceRefresh) {
        params.set('refresh', '1');
    }

    fetch('?' + params.toString())
        .then(r => r.json())
        .then(data => {
            hideLock();
            if (!data.success) {
                kontenOverviewData = null;
                meta.textContent = data.error || 'Konten-Übersicht konnte nicht geladen werden.';
                document.getElementById('kontenTotals').innerHTML = '<div class="col-12"><p class="text-danger small mb-0">Fehler beim Laden der Summen.</p></div>';
                document.getElementById('kontenBody').innerHTML = `<tr><td colspan="2" class="text-center text-danger py-3">${escapeHtml(data.error || 'Unbekannter Fehler')}</td></tr>`;
                return;
            }

            kontenOverviewData = data;
            renderKontenOverview();
        })
        .catch(err => {
            hideLock();
            kontenOverviewData = null;
            meta.textContent = 'Konten-Übersicht konnte nicht geladen werden.';
            document.getElementById('kontenTotals').innerHTML = '<div class="col-12"><p class="text-danger small mb-0">Fehler beim Laden der Summen.</p></div>';
            document.getElementById('kontenBody').innerHTML = `<tr><td colspan="2" class="text-center text-danger py-3">${escapeHtml(err.message)}</td></tr>`;
        });
}

function renderKontenOverview() {
    const meta = document.getElementById('kontenMeta');
    const totals = document.getElementById('kontenTotals');
    const head = document.getElementById('kontenHead');
    const body = document.getElementById('kontenBody');

    if (!kontenOverviewData) {
        meta.textContent = 'Noch nicht geladen.';
        totals.innerHTML = '<div class="col-12"><p class="text-muted small mb-0">Noch keine Summen geladen.</p></div>';
        head.innerHTML = '<tr><th>Name</th><th>PNR</th></tr>';
        body.innerHTML = '<tr><td colspan="2" class="text-center text-muted py-3">Keine Daten vorhanden.</td></tr>';
        return;
    }

    const query = (document.getElementById('kontenSearch').value || '').trim().toLowerCase();
    const rows = (kontenOverviewData.rows || []).filter(row => {
        const haystack = [row.displayName, row.localName, row.logaName, row.pnr]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        return haystack.includes(query);
    });

    const sourceText = kontenOverviewData.source === 'database'
        ? `DB ${kontenOverviewData.storedAt || ''}`.trim()
        : 'LOGA live';
    meta.textContent = `${rows.length} / ${kontenOverviewData.count || 0} Personen | Monat ${kontenOverviewData.month || '-'} | Stand ${kontenOverviewData.snapshotDate || '-'} | Objekt ${kontenOverviewData.objsId || kontenOverviewData.objektId || '-'} | ${sourceText}`;

    const columns = kontenOverviewData.columns || [];
    totals.innerHTML = columns.map(col => {
        const value = kontenOverviewData.totals ? kontenOverviewData.totals[col.key] : null;
        return `<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
            <div class="card bg-light h-100">
                <div class="card-body py-2 text-center">
                    <small class="text-muted d-block">${escapeHtml(col.title)}</small>
                    <div class="fw-bold">${escapeHtml(value || '-')}</div>
                </div>
            </div>
        </div>`;
    }).join('');

    head.innerHTML = '<tr><th class="konten-name">Name</th><th>PNR</th>' + columns.map(col => {
        return `<th title="${escapeHtml(col.key)}">${escapeHtml(col.title)}</th>`;
    }).join('') + '</tr>';

    if (rows.length === 0) {
        body.innerHTML = `<tr><td colspan="${columns.length + 2}" class="text-center text-muted py-3">Keine Personen für diese Suche gefunden.</td></tr>`;
        return;
    }

    body.innerHTML = rows.map(row => {
        const mappedBadge = row.localUserId ? '<span class="badge bg-success ms-2">Lokal</span>' : '<span class="badge bg-warning text-dark ms-2">Nur LOGA</span>';
        const secondary = [];
        if (row.localName && row.logaName && row.localName !== row.logaName) {
            secondary.push(`LOGA: ${escapeHtml(row.logaName)}`);
        } else if (!row.localName && row.logaName) {
            secondary.push(`LOGA: ${escapeHtml(row.logaName)}`);
        }
        if (row.vertnr) {
            secondary.push(`VertNr ${escapeHtml(String(row.vertnr))}`);
        }

        return `<tr>
            <td class="konten-name">
                <div class="fw-semibold">${escapeHtml(row.displayName || '-')} ${mappedBadge}</div>
                ${secondary.length ? `<div class="konten-secondary">${secondary.join(' | ')}</div>` : ''}
            </td>
            <td><code>${escapeHtml(row.pnr || '-')}</code></td>
            ${columns.map(col => renderKontenValueCell(row.values ? row.values[col.key] : null)).join('')}
        </tr>`;
    }).join('');
}

function renderKontenValueCell(value) {
    if (value === null || value === undefined || value === '') {
        return '<td class="text-muted">-</td>';
    }

    const text = String(value);
    const cls = text.startsWith('-') ? 'text-danger fw-semibold' : '';
    return `<td class="${cls}">${escapeHtml(text)}</td>`;
}

function toggleExplorerRaw() {
    const formatted = document.getElementById('explorerResultFormatted');
    explorerShowRaw = !explorerShowRaw;
    if (explorerShowRaw) {
        formatted.textContent = explorerRawData;
    } else {
        try {
            const parsed = JSON.parse(explorerRawData);
            formatted.textContent = JSON.stringify(parsed, null, 2);
            syntaxHighlight(formatted);
        } catch(e) {
            formatted.textContent = explorerRawData;
        }
    }
}

function copyExplorerResult() {
    navigator.clipboard.writeText(explorerRawData).then(() => {
        const btn = event.currentTarget;
        btn.innerHTML = '<i class="bi bi-check"></i>';
        setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
    });
}

function loadExplorerHistory() {
    const card = document.getElementById('explorerHistoryCard');
    card.classList.toggle('d-none');
    if (card.classList.contains('d-none')) return;

    fetch('?mode=explorer-history&format=json&limit=30')
        .then(r => r.json())
        .then(rows => {
            const tbody = document.getElementById('explorerHistoryBody');
            if (!rows || rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No history yet.</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(r => `<tr>
                <td>${r.created_at}</td>
                <td>${escapeHtml(r.endpoint_label || r.endpoint)}</td>
                <td><span class="badge ${r.http_status >= 200 && r.http_status < 400 ? 'bg-success' : 'bg-danger'}">${r.http_status}</span></td>
                <td>${formatBytes(r.response_size)}</td>
                <td>${r.duration_ms}ms</td>
                <td>${r.fetched_by || '-'}</td>
                <td><button class="btn btn-outline-primary btn-sm py-0 px-1" onclick="viewExplorerRecord(${r.id})" title="View response"><i class="bi bi-eye"></i></button></td>
            </tr>`).join('');
        })
        .catch(() => {});
}

function viewExplorerRecord(id) {
    const formatted = document.getElementById('explorerResultFormatted');
    const resultDiv = document.getElementById('explorerResult');
    resultDiv.classList.remove('d-none');
    formatted.textContent = 'Loading saved response...';

    fetch('?mode=explorer-view&format=json&id=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                formatted.innerHTML = `<span class="log-error">${escapeHtml(data.error)}</span>`;
                return;
            }
            const rec = data.record;
            document.getElementById('explorerResultLabel').textContent = rec.endpoint_label || rec.endpoint;
            document.getElementById('explorerResultMeta').textContent =
                `HTTP ${rec.http_status} | ${formatBytes(rec.response_size)} | ${rec.duration_ms}ms | ${rec.created_at}`;

            explorerRawData = rec.response_data || '';

            if (rec.response_decoded) {
                formatted.textContent = JSON.stringify(rec.response_decoded, null, 2);
                syntaxHighlight(formatted);
            } else {
                formatted.textContent = rec.response_data || '(empty)';
            }
        })
        .catch(err => { formatted.innerHTML = `<span class="log-error">${err.message}</span>`; });
}

// Minimal JSON syntax highlighting for the console
function syntaxHighlight(el) {
    let text = el.textContent;
    if (text.length > 200000) return;
    text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    text = text.replace(/"([^"]+)"(\s*:)/g, '<span style="color:#dcdcaa">"$1"</span>$2');
    text = text.replace(/:\s*"([^"]*)"/g, ': <span style="color:#ce9178">"$1"</span>');
    text = text.replace(/:\s*(\d+\.?\d*)/g, ': <span style="color:#b5cea8">$1</span>');
    text = text.replace(/:\s*(true|false|null)/g, ': <span style="color:#569cd6">$1</span>');
    el.innerHTML = text;
}

function formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

// ─── Init ───────────────────────────────────────────────────────────────────
loadStatus();
statusTimer = setInterval(loadStatus, 15000);

// Attach persons search/filter event listeners
document.getElementById('personsSearch')?.addEventListener('input', loadPersons);
document.getElementById('personsFilter')?.addEventListener('change', loadPersons);
document.getElementById('personsOrderBy')?.addEventListener('change', loadPersons);
</script>
</body>
</html>
<?php
}
?>
