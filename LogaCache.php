<?php
/**
 * LOGA Portal - JSON Cache Manager
 * 
 * Manages per-month JSON cache files for LOGA data.
 * Each month is stored in its own file (loga_YYYY-MM.json) to keep memory
 * usage proportional to a single month – even when fetching a full year.
 * Also manages the cooldown tracker for rate protection.
 * 
 * @author  DienstPlan System
 * @date    2026-04-18
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';

class LogaCache {
    private LogaLogger $logger;
    private string $cacheDir;
    private string $cooldownFile;

    public function __construct(?LogaLogger $logger = null) {
        $this->logger = $logger ?? LogaLogger::getInstance();
        $this->cacheDir = LOGA_CACHE_DIR;
        $this->cooldownFile = LOGA_COOLDOWN_FILE;

        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    // ─── Per-Month File Helpers ─────────────────────────────────────────────

    private function monthFile(string $monthKey): string {
        return $this->cacheDir . '/loga_' . $monthKey . '.json';
    }

    private function loadMonthFile(string $monthKey): ?array {
        $file = $this->monthFile($monthKey);
        if (!file_exists($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!$raw) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function saveMonthFile(string $monthKey, array $data): void {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents($this->monthFile($monthKey), $json, LOCK_EX);
        }
    }

    /**
     * List all month keys that have a cache file on disk.
     * @return string[] Sorted array of YYYY-MM keys
     */
    private function listCachedMonths(): array {
        $keys = [];
        foreach (glob($this->cacheDir . '/loga_????-??.json') as $file) {
            if (preg_match('/loga_(\d{4}-\d{2})\.json$/', $file, $m)) {
                $keys[] = $m[1];
            }
        }
        sort($keys);
        return $keys;
    }

    // ─── Data Cache ─────────────────────────────────────────────────────────

    /**
     * Read cached data for a specific month.
     * 
     * @param string $monthKey Month key in YYYY-MM format
     * @return array|null Cached month data or null if not found/expired
     */
    public function read(string $monthKey): ?array {
        $month = $this->loadMonthFile($monthKey);
        if ($month === null) {
            return null;
        }

        // Check staleness
        if ($this->isEntryStale($month)) {
            $this->logger->debug("Cache for {$monthKey} is stale", 'LogaCache');
            return null;
        }

        return $month;
    }

    /**
     * Write data for a specific month to its own cache file.
     * 
     * @param string $monthKey   Month key in YYYY-MM format
     * @param string $dateFrom   Start date
     * @param string $dateTo     End date
     * @param array  $persons    Persons list from LOGA
     * @param array  $personsData Full persons data (shifts + absences)
     */
    public function write(string $monthKey, string $dateFrom, string $dateTo, array $persons, array $personsData): void {
        $data = [
            'fetchedAt'   => date('Y-m-d\TH:i:s'),
            'timestamp'   => time(),
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
            'persons'     => $persons,
            'personsData' => $personsData,
        ];

        $this->saveMonthFile($monthKey, $data);
        $this->logger->info("Cache updated for {$monthKey} ({$dateFrom} to {$dateTo})", 'LogaCache');
    }

    /**
     * Check if cached data for a month is stale (older than TTL).
     */
    public function isStale(string $monthKey): bool {
        $month = $this->loadMonthFile($monthKey);
        if ($month === null) {
            return true;
        }
        return $this->isEntryStale($month);
    }

    /**
     * Get all cached data (all months) — used by LogaProcessor fallback.
     * WARNING: loads every month file into memory.  Use sparingly.
     */
    public function getAll(): ?array {
        $months = [];
        foreach ($this->listCachedMonths() as $mk) {
            $data = $this->loadMonthFile($mk);
            if ($data) {
                $months[$mk] = $data;
            }
        }
        if (empty($months)) {
            return null;
        }
        return [
            'lastUpdated' => date('Y-m-d\TH:i:s'),
            'months'      => $months,
        ];
    }

    /**
     * Get cache status information for dashboard display.
     * Only reads metadata — does NOT load full personsData.
     */
    public function getStatus(): array {
        $cachedMonths = $this->listCachedMonths();
        if (empty($cachedMonths)) {
            return [
                'exists'      => false,
                'lastUpdated' => null,
                'months'      => [],
                'totalSize'   => 0,
            ];
        }

        $months = [];
        $totalSize = 0;
        $latestUpdate = null;

        foreach ($cachedMonths as $mk) {
            $file = $this->monthFile($mk);
            $size = file_exists($file) ? filesize($file) : 0;
            $totalSize += $size;

            // Read only the small metadata fields (quick json parse)
            $month = $this->loadMonthFile($mk);
            if (!$month) continue;

            $age = isset($month['timestamp']) ? time() - $month['timestamp'] : null;
            $months[$mk] = [
                'fetchedAt' => $month['fetchedAt'] ?? null,
                'dateFrom'  => $month['dateFrom'] ?? null,
                'dateTo'    => $month['dateTo'] ?? null,
                'age'       => $age,
                'stale'     => $this->isEntryStale($month),
                'persons'   => count($month['persons'] ?? []),
            ];

            if ($latestUpdate === null || ($month['fetchedAt'] ?? '') > $latestUpdate) {
                $latestUpdate = $month['fetchedAt'] ?? null;
            }
        }

        return [
            'exists'      => true,
            'lastUpdated' => $latestUpdate,
            'months'      => $months,
            'statistics'  => [], // statistics are computed on-the-fly if needed
            'totalSize'   => $totalSize,
        ];
    }

    /**
     * Clear all cached data.
     */
    public function clear(): void {
        foreach (glob($this->cacheDir . '/loga_????-??.json') as $file) {
            @unlink($file);
        }
        // Also remove legacy single-file cache if it exists
        $legacy = $this->cacheDir . '/loga_data.json';
        if (file_exists($legacy)) {
            @unlink($legacy);
        }
        $this->logger->info("Cache cleared", 'LogaCache');
    }

    /**
     * Clear a specific month from cache.
     */
    public function clearMonth(string $monthKey): void {
        $file = $this->monthFile($monthKey);
        if (file_exists($file)) {
            @unlink($file);
            $this->logger->info("Cache cleared for {$monthKey}", 'LogaCache');
        }
    }

    // ─── Cooldown Tracking ──────────────────────────────────────────────────

    /**
     * Check if a mode+dateRange is on cooldown.
     * 
     * When called without arguments, returns the maximum remaining cooldown
     * across all tracked entries (useful for status dashboard).
     * 
     * @param string|null $mode     Operation mode (null = check all)
     * @param string|null $monthKey Month key in YYYY-MM format
     * @return int Remaining cooldown seconds (0 if ready)
     */
    public function getCooldownRemaining(?string $mode = null, ?string $monthKey = null): int {
        $cooldowns = $this->loadCooldowns();

        // If both args provided, check specific key
        if ($mode !== null && $monthKey !== null) {
            $key = "{$mode}:{$monthKey}";
            if (!isset($cooldowns[$key])) {
                return 0;
            }
            $elapsed = time() - $cooldowns[$key];
            $remaining = LOGA_SYNC_COOLDOWN - $elapsed;
            return max(0, $remaining);
        }

        // No args: return max remaining across all entries
        $maxRemaining = 0;
        foreach ($cooldowns as $ts) {
            $elapsed = time() - $ts;
            $remaining = LOGA_SYNC_COOLDOWN - $elapsed;
            $maxRemaining = max($maxRemaining, $remaining);
        }
        return max(0, $maxRemaining);
    }

    /**
     * Record a cooldown timestamp for a mode+dateRange.
     */
    public function recordCooldown(string $mode, string $monthKey): void {
        $cooldowns = $this->loadCooldowns();
        $cooldowns["{$mode}:{$monthKey}"] = time();

        // Prune old entries (older than 1 hour)
        $cooldowns = array_filter($cooldowns, fn($ts) => (time() - $ts) < 3600);

        @file_put_contents($this->cooldownFile, json_encode($cooldowns, JSON_PRETTY_PRINT), LOCK_EX);
    }

    // ─── Internal Helpers ───────────────────────────────────────────────────

    private function isEntryStale(array $entry): bool {
        if (!isset($entry['timestamp'])) {
            return true;
        }
        return (time() - $entry['timestamp']) > LOGA_DATA_CACHE_TTL;
    }

    private function loadCooldowns(): array {
        if (!file_exists($this->cooldownFile)) {
            return [];
        }

        $data = json_decode(@file_get_contents($this->cooldownFile), true);
        return is_array($data) ? $data : [];
    }
}
