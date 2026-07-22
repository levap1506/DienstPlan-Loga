<?php
/**
 * LOGA Portal - Logger
 * 
 * Structured logging with ERROR/INFO/DEBUG levels.
 * Outputs to file (always), and conditionally to browser/CLI/API.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';

class LogaLogger {
    /** Log level constants */
    const LEVEL_ERROR = 0;
    const LEVEL_INFO  = 1;
    const LEVEL_DEBUG = 2;

    /** @var int Current log level threshold */
    private int $level;

    /** @var string Path to log file */
    private string $logFile;

    /** @var int Max log file size before rotation */
    private int $maxSize;

    /** @var string Output context: 'browser', 'cli', 'api', 'sse' */
    private string $context;

    /** @var array Collected log entries (for API JSON response) */
    private array $entries = [];

    /** @var float Start time for duration tracking */
    private float $startTime;

    /** @var string|null Source class for log entries */
    private ?string $source = null;

    /** @var self|null Singleton instance */
    private static ?self $instance = null;

    /**
     * @param string $level   Log level: 'ERROR', 'INFO', 'DEBUG'
     * @param string $context Output context: 'browser', 'cli', 'api', 'sse'
     */
    public function __construct(string $level = 'DEBUG', string $context = 'api') {
        $this->level = self::parseLevel($level);
        $this->logFile = LOGA_LOG_FILE;
        $this->maxSize = LOGA_LOG_MAX_SIZE;
        $this->context = $context;
        $this->startTime = microtime(true);

        // Ensure log directory exists
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Rotate if needed
        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxSize) {
            $oldFile = $this->logFile . '.old';
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
            rename($this->logFile, $oldFile);
        }
    }

    /**
     * Get or create singleton instance.
     */
    public static function getInstance(string $level = 'DEBUG', string $context = 'api'): self {
        if (self::$instance === null) {
            self::$instance = new self($level, $context);
        }
        return self::$instance;
    }

    /**
     * Reset singleton (for testing).
     */
    public static function resetInstance(): void {
        self::$instance = null;
    }

    /**
     * Set source class name for subsequent log entries.
     */
    public function setSource(?string $source): self {
        $this->source = $source;
        return $this;
    }

    /**
     * Set output context after construction.
     */
    public function setContext(string $context): self {
        $this->context = $context;
        return $this;
    }

    /**
     * Log an error message.
     */
    public function error(string $message, ?string $source = null): void {
        $this->log(self::LEVEL_ERROR, $message, $source);
    }

    /**
     * Log an info message.
     */
    public function info(string $message, ?string $source = null): void {
        $this->log(self::LEVEL_INFO, $message, $source);
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, ?string $source = null): void {
        $this->log(self::LEVEL_DEBUG, $message, $source);
    }

    /**
     * Get elapsed time since logger creation.
     */
    public function getElapsed(): float {
        return round(microtime(true) - $this->startTime, 2);
    }

    /**
     * Get all collected log entries (for API JSON response).
     */
    public function getEntries(): array {
        return $this->entries;
    }

    /**
     * Get entries filtered by minimum level.
     */
    public function getEntriesByLevel(int $minLevel = self::LEVEL_ERROR): array {
        return array_filter($this->entries, fn($e) => $e['levelNum'] <= $minLevel);
    }

    /**
     * Check if any errors were logged.
     */
    public function hasErrors(): bool {
        foreach ($this->entries as $entry) {
            if ($entry['levelNum'] === self::LEVEL_ERROR) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get count of entries by level.
     */
    public function getCounts(): array {
        $counts = ['error' => 0, 'info' => 0, 'debug' => 0];
        foreach ($this->entries as $entry) {
            match ($entry['levelNum']) {
                self::LEVEL_ERROR => $counts['error']++,
                self::LEVEL_INFO  => $counts['info']++,
                self::LEVEL_DEBUG => $counts['debug']++,
            };
        }
        return $counts;
    }

    // ─── Internal ───────────────────────────────────────────────────────────

    private function log(int $level, string $message, ?string $source = null): void {
        if ($level > $this->level) {
            return; // Below threshold
        }

        $levelName = self::levelName($level);
        $src = $source ?? $this->source ?? '';
        $timestamp = date('Y-m-d H:i:s');
        $elapsed = $this->getElapsed();

        // Build entry
        $entry = [
            'time'     => $timestamp,
            'elapsed'  => $elapsed,
            'level'    => $levelName,
            'levelNum' => $level,
            'source'   => $src,
            'message'  => $message,
        ];
        $this->entries[] = $entry;

        // Always write to file
        $srcTag = $src ? "[{$src}] " : '';
        $fileLine = "[{$timestamp}] [{$elapsed}s] {$levelName}: {$srcTag}{$message}" . PHP_EOL;
        @file_put_contents($this->logFile, $fileLine, FILE_APPEND | LOCK_EX);

        // Output based on context
        match ($this->context) {
            'browser' => $this->outputBrowser($level, $levelName, $message, $src, $elapsed),
            'cli'     => $this->outputCli($level, $levelName, $message, $src, $elapsed),
            'sse'     => $this->outputSse($level, $levelName, $message, $src, $elapsed),
            default   => null, // 'api' — collected in $this->entries, returned at end
        };
    }

    private function outputBrowser(int $level, string $levelName, string $message, string $source, float $elapsed): void {
        $colors = [
            self::LEVEL_ERROR => '#dc3545',
            self::LEVEL_INFO  => '#17a2b8',
            self::LEVEL_DEBUG => '#6c757d',
        ];
        $icons = [
            self::LEVEL_ERROR => '&#x274C;',  // ❌
            self::LEVEL_INFO  => '&#x2139;',   // ℹ️
            self::LEVEL_DEBUG => '&#x1F41B;',  // 🐛
        ];

        $color = $colors[$level] ?? '#6c757d';
        $icon = $icons[$level] ?? '';
        $srcHtml = $source ? "<span style='color:#999;font-size:0.85em'>[{$source}]</span> " : '';
        $timeHtml = "<span style='color:#999;font-size:0.8em'>{$elapsed}s</span>";

        echo "<div class='log-line log-{$levelName}' style='margin:2px 0;padding:4px 8px;border-left:3px solid {$color};font-family:monospace;font-size:13px;'>"
           . "{$icon} {$srcHtml}<span style='color:{$color}'>{$message}</span> {$timeHtml}"
           . "</div>\n";

        $this->flushOutput();
    }

    private function outputCli(int $level, string $levelName, string $message, string $source, float $elapsed): void {
        $prefix = match ($level) {
            self::LEVEL_ERROR => '[ERROR]',
            self::LEVEL_INFO  => '[INFO] ',
            self::LEVEL_DEBUG => '[DEBUG]',
        };
        $srcTag = $source ? "[{$source}] " : '';
        echo "{$prefix} {$srcTag}{$message} ({$elapsed}s)" . PHP_EOL;
    }

    private function outputSse(int $level, string $levelName, string $message, string $source, float $elapsed): void {
        $data = json_encode([
            'type'    => $levelName,
            'source'  => $source,
            'message' => $message,
            'elapsed' => $elapsed,
        ]);
        echo "data: {$data}\n\n";
        $this->flushOutput();
    }

    private function flushOutput(): void {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private static function parseLevel(string $level): int {
        return match (strtoupper($level)) {
            'ERROR' => self::LEVEL_ERROR,
            'INFO'  => self::LEVEL_INFO,
            'DEBUG' => self::LEVEL_DEBUG,
            default => self::LEVEL_DEBUG,
        };
    }

    private static function levelName(int $level): string {
        return match ($level) {
            self::LEVEL_ERROR => 'ERROR',
            self::LEVEL_INFO  => 'INFO',
            self::LEVEL_DEBUG => 'DEBUG',
            default           => 'DEBUG',
        };
    }
}
