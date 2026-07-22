<?php
/**
 * LOGA Portal - Runtime Config Resolver
 * 
 * Auto-detects the current LOGA build version and GWT permutation tokens
 * by scraping the public logout page and parsing GWT bootstrap JS files.
 * Results are cached with a 6-hour TTL.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaClient.php';

class LogaRuntimeConfig {
    private LogaClient $client;
    private LogaLogger $logger;
    private string $cacheFile;
    private int $cacheTtl;

    /** @var array Default fallback config */
    private const DEFAULTS = [
        'logaVersion'      => LOGA_DEFAULT_VERSION,
        'loginPermutation' => LOGA_DEFAULT_LOGIN_PERMUTATION,
        'loginStrongName'  => LOGA_DEFAULT_LOGIN_STRONG_NAME,
        'xsrfPermutation'  => LOGA_DEFAULT_XSRF_PERMUTATION,
        'xsrfStrongName'   => LOGA_DEFAULT_XSRF_STRONG_NAME,
    ];

    public function __construct(LogaClient $client, ?LogaLogger $logger = null) {
        $this->client = $client;
        $this->logger = $logger ?? LogaLogger::getInstance();
        $this->cacheFile = LOGA_RUNTIME_CACHE_FILE;
        $this->cacheTtl = LOGA_RUNTIME_CONFIG_TTL;

        // Ensure cache directory exists
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Resolve runtime config: check cache first, then auto-detect from LOGA server.
     * 
     * @param bool        $force             Bypass cache and re-detect
     * @param string|null $preferredVersion  Use this version instead of auto-detecting
     * @return array Config array with keys: logaVersion, loginPermutation, loginStrongName, xsrfPermutation, xsrfStrongName
     */
    public function resolve(bool $force = false, ?string $preferredVersion = null): array {
        // Try cache first (unless forced)
        if (!$force) {
            $cached = $this->loadCache($preferredVersion);
            if ($cached !== null) {
                $this->logger->debug("Using cached runtime config (version: {$cached['logaVersion']})", 'RuntimeConfig');
                return $cached;
            }
        }

        $this->logger->info("Resolving LOGA runtime configuration...", 'RuntimeConfig');

        $config = self::DEFAULTS;
        if ($preferredVersion !== null && $preferredVersion !== '') {
            $config['logaVersion'] = $preferredVersion;
        }

        try {
            // Step 1: Detect LOGA version from public page
            $resolvedVersion = $preferredVersion ?: $this->detectLogaVersion();
            if ($resolvedVersion) {
                $config['logaVersion'] = $resolvedVersion;
                $this->logger->info("Detected LOGA version: {$resolvedVersion}", 'RuntimeConfig');
            }

            // Step 2: Resolve Login module permutation + strong name
            $loginBootstrap = $this->client->get(
                $this->buildModuleUrl($config['logaVersion'], 'Login', 'Login.nocache.js')
            );
            $loginPerm = $this->extractPermutation($loginBootstrap);
            if ($loginPerm) {
                $config['loginPermutation'] = $loginPerm;
                $this->logger->debug("Login permutation: {$loginPerm}", 'RuntimeConfig');
            }

            $loginCache = $this->client->get(
                $this->buildModuleUrl($config['logaVersion'], 'Login', $config['loginPermutation'] . '.cache.js')
            );
            $loginStrong = $this->extractLoginStrongName($loginCache);
            if ($loginStrong) {
                $config['loginStrongName'] = $loginStrong;
                $this->logger->debug("Login strong name: {$loginStrong}", 'RuntimeConfig');
            }

            // Step 3: Resolve L2Main module permutation + strong name (for XSRF)
            $l2MainBootstrap = $this->client->get(
                $this->buildModuleUrl($config['logaVersion'], 'L2Main', 'L2Main.nocache.js')
            );
            $xsrfPerm = $this->extractPermutation($l2MainBootstrap);
            if ($xsrfPerm) {
                $config['xsrfPermutation'] = $xsrfPerm;
                $this->logger->debug("XSRF permutation: {$xsrfPerm}", 'RuntimeConfig');
            }

            $l2MainCache = $this->client->get(
                $this->buildModuleUrl($config['logaVersion'], 'L2Main', $config['xsrfPermutation'] . '.cache.js')
            );
            $xsrfStrong = $this->extractXsrfStrongName($l2MainCache);
            if ($xsrfStrong) {
                $config['xsrfStrongName'] = $xsrfStrong;
                $this->logger->debug("XSRF strong name: {$xsrfStrong}", 'RuntimeConfig');
            }

            $config['source'] = 'remote';
            $this->logger->info("Runtime config resolved from LOGA server", 'RuntimeConfig');

        } catch (\Throwable $e) {
            $config['source'] = 'fallback';
            $config['detectionError'] = $e->getMessage();
            $this->logger->error("Runtime config detection failed, using defaults: " . $e->getMessage(), 'RuntimeConfig');
        }

        $config['resolvedAt'] = time();
        $this->storeCache($config);

        return $config;
    }

    /**
     * Get the cached config without attempting resolution.
     */
    public function getCached(): ?array {
        return $this->loadCache();
    }

    // ─── Detection Methods ───────────────────────────────────────────────────

    /**
     * Detect LOGA version from the public logout page.
     */
    private function detectLogaVersion(): ?string {
        $page = $this->client->get(LOGA_BASE_URL . 'public/logout');

        // Primary: look for Login.nocache.js path
        if (preg_match('~/loga3/bts/(\d{17})/Login/Login\.nocache\.js~', $page, $m)) {
            return $m[1];
        }

        // Fallback: any 17-digit version in bts path
        if (preg_match('~/bts/(\d{17})/~', $page, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract GWT permutation hash from bootstrap JS.
     */
    private function extractPermutation(string $bootstrap): ?string {
        // Pattern: k([Mb,jc],XX) where XX is the alias
        if (!preg_match('~k\(\[Mb,jc\],([A-Za-z]{2})\)~', $bootstrap, $m)) {
            return null;
        }

        $alias = preg_quote($m[1], '~');
        if (preg_match("~\\b{$alias}=['\"]([A-F0-9]{32})['\"]~", $bootstrap, $pm)) {
            return $pm[1];
        }

        return null;
    }

    /**
     * Extract Login service strong name from cache JS.
     */
    private function extractLoginStrongName(string $cacheContent): ?string {
        if (preg_match("~this\\.b=a\\+\\'LoginSrv\\';this\\.e=b;this\\.d=\\'([A-F0-9]{32})\\'~", $cacheContent, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Extract XSRF service strong name from L2Main cache JS.
     */
    private function extractXsrfStrongName(string $cacheContent): ?string {
        $pos = strpos($cacheContent, 'getNewXsrfToken');
        if ($pos === false) {
            return null;
        }

        $windowStart = max(0, $pos - 1200);
        $window = substr($cacheContent, $windowStart, 1200);
        if (!$window) {
            return null;
        }

        if (!preg_match_all("~'([A-F0-9]{32})'~", $window, $m) || empty($m[1])) {
            return null;
        }

        return end($m[1]) ?: null;
    }

    // ─── Cache Management ────────────────────────────────────────────────────

    private function loadCache(?string $preferredVersion = null): ?array {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        $raw = @file_get_contents($this->cacheFile);
        if (!$raw) {
            return null;
        }

        $cached = json_decode($raw, true);
        if (!is_array($cached) || !isset($cached['resolvedAt'])) {
            return null;
        }

        // Check TTL
        if ((time() - (int)$cached['resolvedAt']) > $this->cacheTtl) {
            return null;
        }

        // Check version match if preferred version specified
        if ($preferredVersion && ($cached['logaVersion'] ?? null) !== $preferredVersion) {
            return null;
        }

        // Validate required keys
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (!isset($cached[$key]) || !is_string($cached[$key]) || $cached[$key] === '') {
                return null;
            }
        }

        return $cached;
    }

    private function storeCache(array $config): void {
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents($this->cacheFile, $json, LOCK_EX);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function buildModuleUrl(string $version, string $module, string $file): string {
        return LOGA_BASE_URL . "bts/{$version}/{$module}/{$file}";
    }
}
