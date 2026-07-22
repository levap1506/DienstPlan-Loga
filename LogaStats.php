<?php
/**
 * LOGA Portal - Statistics Module
 * 
 * Tracks per-sync metrics and writes to loga_sync_stats DB table.
 * Provides historical stats for the dashboard and cache statistics from JSON.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaCache.php';

class LogaStats {
    private mysqli $conn;
    private LogaLogger $logger;
    private LogaCache $cache;

    // Timing
    private float $startTime;
    private ?string $currentMode = null;

    public function __construct(mysqli $conn, ?LogaLogger $logger = null, ?LogaCache $cache = null) {
        $this->conn = $conn;
        $this->logger = $logger ?? LogaLogger::getInstance();
        $this->cache = $cache ?? new LogaCache($this->logger);
        $this->startTime = microtime(true);
    }

    /**
     * Start tracking a sync run.
     */
    public function startRun(string $mode): void {
        $this->startTime = microtime(true);
        $this->currentMode = $mode;
    }

    /**
     * Record a completed sync run to the database.
     * 
     * @param string $mode         Mode that was executed
     * @param string $dateFrom     Start date
     * @param string $dateTo       End date
     * @param array  $result       Result data (counts, etc.)
     * @param bool   $hasErrors    Whether errors occurred
     */
    public function recordRun(string $mode, string $dateFrom, string $dateTo, array $result, bool $hasErrors = false): void {
        $duration = round(microtime(true) - $this->startTime, 2);
        $status = $hasErrors ? 'error' : 'success';

        // Extract summary metrics
        $personsCount = $result['personsCount'] ?? 0;
        $shiftsCount = $result['shiftsCount'] ?? 0;
        $absencesCount = $result['absencesCount'] ?? 0;
        $errorsCount = $result['errors'] ?? ($result['errorsCount'] ?? 0);

        $resultJson = json_encode($result, JSON_UNESCAPED_UNICODE);
        $userName = $_SESSION['username'] ?? ($_SESSION['name'] ?? 'system');

        $stmt = $this->conn->prepare(
            "INSERT INTO loga_sync_stats (mode, date_from, date_to, status, duration_sec, persons_count, shifts_count, absences_count, errors_count, result_data, triggered_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->bind_param(
            "ssssdiiisss",
            $mode, $dateFrom, $dateTo, $status, $duration,
            $personsCount, $shiftsCount, $absencesCount, $errorsCount,
            $resultJson, $userName
        );
        $stmt->execute();
        $stmt->close();

        $this->logger->info("Stats recorded: {$mode} ({$status}) in {$duration}s", 'Stats');
    }

    /**
     * Get recent sync history.
     * 
     * @param int $limit Maximum number of records
     * @return array
     */
    public function getHistory(int $limit = 50): array {
        $stmt = $this->conn->prepare(
            "SELECT id, mode, date_from, date_to, status, duration_sec, persons_count, shifts_count, absences_count, errors_count, triggered_by, created_at FROM loga_sync_stats ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->bind_param("i", $limit);
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
     * Get aggregated stats for a given period.
     * 
     * @param string $period  'day', 'week', or 'month'
     * @return array
     */
    public function getAggregatedStats(string $period = 'week'): array {
        $dateExpr = match ($period) {
            'day'   => "DATE(created_at)",
            'week'  => "YEARWEEK(created_at, 1)",
            'month' => "DATE_FORMAT(created_at, '%Y-%m')",
            default => "DATE(created_at)",
        };

        $query = "SELECT {$dateExpr} as period, mode, COUNT(*) as run_count, SUM(duration_sec) as total_duration, AVG(duration_sec) as avg_duration, SUM(persons_count) as total_persons, SUM(shifts_count) as total_shifts, SUM(absences_count) as total_absences, SUM(errors_count) as total_errors, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count, SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count FROM loga_sync_stats GROUP BY {$dateExpr}, mode ORDER BY period DESC, mode LIMIT 100";

        $result = $this->conn->query($query);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Get cache statistics (from JSON files).
     */
    public function getCacheStats(): array {
        return $this->cache->buildStatistics();
    }

    /**
     * Get cache status (per-month info).
     */
    public function getCacheStatus(): array {
        return $this->cache->getStatus();
    }

    /**
     * Get dashboard overview data.
     */
    public function getDashboardData(): array {
        return [
            'history'     => $this->getHistory(20),
            'cacheStatus' => $this->getCacheStatus(),
            'cacheStats'  => $this->getCacheStats(),
            'lastRun'     => $this->getLastRun(),
            'cooldown'    => 0,
        ];
    }

    /**
     * Get the most recent sync run.
     */
    public function getLastRun(): ?array {
        $result = $this->conn->query(
            "SELECT * FROM loga_sync_stats ORDER BY created_at DESC LIMIT 1"
        );
        return $result->fetch_assoc();
    }

    /**
     * Get stats summary for a specific date range.
     */
    public function getRangeStats(string $dateFrom, string $dateTo): array {
        $stmt = $this->conn->prepare(
            "SELECT mode, COUNT(*) as runs, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as successes, SUM(CASE WHEN status='error' THEN 1 ELSE 0 END) as errors, AVG(duration_sec) as avg_duration FROM loga_sync_stats WHERE date_from >= ? AND date_to <= ? GROUP BY mode"
        );
        $stmt->bind_param("ss", $dateFrom, $dateTo);
        $stmt->execute();
        $result = $stmt->get_result();

        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $stats[$row['mode']] = $row;
        }
        $stmt->close();

        return $stats;
    }

    /**
     * Purge old stats entries.
     * 
     * @param int $daysToKeep Number of days to keep
     * @return int Number of deleted rows
     */
    public function purgeOldStats(int $daysToKeep = 90): int {
        $stmt = $this->conn->prepare(
            "DELETE FROM loga_sync_stats WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->bind_param("i", $daysToKeep);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected > 0) {
            $this->logger->info("Purged {$affected} stats records older than {$daysToKeep} days", 'Stats');
        }
        return $affected;
    }

    /**
     * Build chart data for the dashboard (Chart.js compatible).
     * 
     * @param int $days Number of days to include
     * @return array
     */
    public function getChartData(int $days = 30): array {
        $stmt = $this->conn->prepare(
            "SELECT DATE(created_at) as day, mode, COUNT(*) as runs, AVG(duration_sec) as avg_duration, SUM(errors_count) as total_errors FROM loga_sync_stats WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY DATE(created_at), mode ORDER BY day"
        );
        $stmt->bind_param("i", $days);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();

        // Pivot for Chart.js
        $labels = [];
        $datasets = [];
        $modes = [];

        foreach ($data as $row) {
            $labels[$row['day']] = true;
            $modes[$row['mode']] = true;
        }

        $labels = array_keys($labels);
        $modeColors = [
            'fetch'          => '#4e73df',
            'process'        => '#1cc88a',
            'pull-shifts'    => '#36b9cc',
            'push-shifts'    => '#f6c23e',
            'pull-absences'  => '#e74a3b',
            'pull-all'       => '#858796',
        ];

        foreach (array_keys($modes) as $mode) {
            $values = array_fill(0, count($labels), 0);
            foreach ($data as $row) {
                if ($row['mode'] === $mode) {
                    $idx = array_search($row['day'], $labels);
                    if ($idx !== false) {
                        $values[$idx] = (float)$row['avg_duration'];
                    }
                }
            }

            $datasets[] = [
                'label'           => $mode,
                'data'            => $values,
                'backgroundColor' => $modeColors[$mode] ?? '#858796',
                'borderColor'     => $modeColors[$mode] ?? '#858796',
                'fill'            => false,
            ];
        }

        return [
            'labels'   => $labels,
            'datasets' => $datasets,
        ];
    }
}
