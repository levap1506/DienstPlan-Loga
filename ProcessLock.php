<?php
/**
 * LOGA Portal - Process Lock
 * 
 * File-based mutual exclusion to prevent concurrent LOGA sync operations.
 * Uses flock(LOCK_EX|LOCK_NB) for non-blocking exclusive lock.
 * Supports stale lock detection and automatic recovery.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';

class ProcessLock {
    private string $lockFile;
    private $lockHandle = null;
    private LogaLogger $logger;

    public function __construct(?LogaLogger $logger = null) {
        $this->lockFile = LOGA_LOCK_FILE;
        $this->logger = $logger ?? LogaLogger::getInstance();

        // Ensure cache directory exists
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Try to acquire the lock. Returns true on success, false if already locked.
     * 
     * @param string $mode    Current operation mode
     * @param string $user    Current user name
     * @param bool   $force   If true, force-release any existing lock before acquiring
     * @return bool
     */
    public function acquire(string $mode = 'unknown', string $user = 'unknown', bool $force = false): bool {
        // Force-release stale or stuck locks when explicitly requested
        if ($force) {
            $this->forceRelease();
        }

        // Check for stale lock before attempting
        $this->recoverStaleLock();

        $this->lockHandle = @fopen($this->lockFile, 'c+');
        if (!$this->lockHandle) {
            $this->logger->error("Failed to open lock file: {$this->lockFile}", 'ProcessLock');
            return false;
        }

        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            $this->logger->debug("Lock already held by another process", 'ProcessLock');
            return false;
        }

        // Write lock metadata
        ftruncate($this->lockHandle, 0);
        rewind($this->lockHandle);
        fwrite($this->lockHandle, json_encode([
            'pid'        => getmypid(),
            'startTime'  => time(),
            'mode'       => $mode,
            'user'       => $user,
            'dateRange'  => ($_GET['dateFrom'] ?? '') . ' - ' . ($_GET['dateTo'] ?? ''),
        ], JSON_PRETTY_PRINT));
        fflush($this->lockHandle);

        $this->logger->debug("Lock acquired (PID: " . getmypid() . ", mode: {$mode})", 'ProcessLock');
        return true;
    }

    /**
     * Release the lock.
     */
    public function release(): void {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;

            // Remove lock file
            if (file_exists($this->lockFile)) {
                @unlink($this->lockFile);
            }

            $this->logger->debug("Lock released", 'ProcessLock');
        }
    }

    /**
     * Force-release any existing lock, even if held by another process.
     * Useful for recovering from hung syncs. Removes the lock file so
     * a fresh acquire() can succeed.
     */
    public function forceRelease(): void {
        if ($this->lockHandle) {
            $this->release();
        }
        if (file_exists($this->lockFile)) {
            @unlink($this->lockFile);
            $this->logger->info("Lock force-released (file removed)", 'ProcessLock');
        }
    }

    /**
     * Get information about the current lock holder (if any).
     * 
     * @return array|null Lock metadata or null if no lock
     */
    public function getLockInfo(): ?array {
        if (!file_exists($this->lockFile)) {
            return null;
        }

        $content = @file_get_contents($this->lockFile);
        if (!$content) {
            return null;
        }

        $info = json_decode($content, true);
        if (!is_array($info)) {
            return null;
        }

        // Add computed fields
        if (isset($info['startTime'])) {
            $info['age'] = time() - $info['startTime'];
            $info['startTimeFormatted'] = date('Y-m-d H:i:s', $info['startTime']);
        }

        return $info;
    }

    /**
     * Check if lock is currently active.
     */
    public function isLocked(): bool {
        if (!file_exists($this->lockFile)) {
            return false;
        }

        // Try non-blocking lock to check
        $handle = @fopen($this->lockFile, 'r');
        if (!$handle) {
            return false;
        }

        $locked = !flock($handle, LOCK_EX | LOCK_NB);
        if (!$locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return $locked;
    }

    /**
     * Detect and recover stale locks.
     *
     * A lock is stale if the owning PID is no longer running (crashed process).
     * If the PID is still alive but the lock age exceeds the timeout, the process
     * is considered hung and the lock is force-released with a warning.
     */
    private function recoverStaleLock(): void {
        $info = $this->getLockInfo();
        if (!$info || !isset($info['pid'], $info['startTime'])) {
            return;
        }

        $age = time() - $info['startTime'];
        $pid = $info['pid'];
        $pidRunning = $this->isPidRunning($pid);

        if (!$pidRunning) {
            $this->logger->info(
                "Recovering stale lock: PID {$pid} no longer running, lock age {$age}s",
                'ProcessLock'
            );
            @unlink($this->lockFile);
        } elseif ($age >= LOGA_STALE_LOCK_TIMEOUT) {
            $this->logger->info(
                "Forcing release of hung lock: PID {$pid} still running but lock held for {$age}s (threshold: " . LOGA_STALE_LOCK_TIMEOUT . "s)",
                'ProcessLock'
            );
            @unlink($this->lockFile);
        }
    }

    private function isPidRunning(int $pid): bool {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output, $returnCode);
            return count($output) > 1;
        }
        return file_exists("/proc/{$pid}");
    }
}
