<?php
/**
 * LOGA Portal - Configuration
 *
 * Centralized configuration for the LOGA sync system.
 * Values come from .env (secrets) and config/settings.json (tuning),
 * with hardcoded defaults as fallback.
 *
 * @author  DienstPlan System
 */

// ── LOGA Server ────────────────────────────────────────────────────────────
if (!defined('LOGA_BASE_URL')) {
    define('LOGA_BASE_URL', $_ENV['LOGA_BASE_URL'] ?? 'https://schwarzw.pi-asp.de/loga3/');
}
if (!defined('LOGA_MANDANT')) {
    define('LOGA_MANDANT', $_ENV['LOGA_MANDANT'] ?? 'SBK');
}
if (!defined('LOGA_CONTEXT_ROLE_ID')) {
    define('LOGA_CONTEXT_ROLE_ID', $_ENV['LOGA_CONTEXT_ROLE_ID'] ?? 'a673d0ad-03ae-4e11-8ff3-92fbece4ba1e');
}
if (!defined('LOGA_OBJEKT_ID')) {
    define('LOGA_OBJEKT_ID', $_ENV['LOGA_OBJEKT_ID'] ?? 'c3ad345f-4112-4414-9ea0-91567242aa59');
}
if (!defined('LOGA_OBJEKT_PATH')) {
    define('LOGA_OBJEKT_PATH', ['.', 'd02cef3d-1733-489f-9c5c-d9dea6f1608b', LOGA_OBJEKT_ID]);
}
if (!defined('LOGA_COUNTRY_NLS')) {
    define('LOGA_COUNTRY_NLS', '000');
}

// ── GWT-RPC Credentials (from .env) ────────────────────────────────────────
if (!defined('LOGA_USERNAME')) {
    define('LOGA_USERNAME', $_ENV['LOGA_USERNAME'] ?? 'PGUTU');
}
if (!defined('LOGA_PASSWORD')) {
    define('LOGA_PASSWORD', $_ENV['LOGA_PASSWORD'] ?? 'NGpwM2NjaTEzNE1FTyNm');
}

// ── Runtime Config Defaults (overridden by auto-detection) ─────────────────
if (!defined('LOGA_DEFAULT_VERSION')) {
    define('LOGA_DEFAULT_VERSION', $_ENV['LOGA_DEFAULT_VERSION'] ?? '20260213183704171');
}
if (!defined('LOGA_DEFAULT_LOGIN_PERMUTATION')) {
    define('LOGA_DEFAULT_LOGIN_PERMUTATION', $_ENV['LOGA_DEFAULT_LOGIN_PERMUTATION'] ?? '909B89694ABD854BA0C21E2CD2033CDA');
}
if (!defined('LOGA_DEFAULT_LOGIN_STRONG_NAME')) {
    define('LOGA_DEFAULT_LOGIN_STRONG_NAME', $_ENV['LOGA_DEFAULT_LOGIN_STRONG_NAME'] ?? 'E1DE55D613C4F27162BFDC93ACEC979B');
}
if (!defined('LOGA_DEFAULT_XSRF_PERMUTATION')) {
    define('LOGA_DEFAULT_XSRF_PERMUTATION', $_ENV['LOGA_DEFAULT_XSRF_PERMUTATION'] ?? '881A738FF6CE4399AB1573CAA05B2DAF');
}
if (!defined('LOGA_DEFAULT_XSRF_STRONG_NAME')) {
    define('LOGA_DEFAULT_XSRF_STRONG_NAME', $_ENV['LOGA_DEFAULT_XSRF_STRONG_NAME'] ?? '210577F3DEBA0E5BB6F2FD176A940671');
}

// ── Special Users (exist only in LOGA, no local DB record) ────────────────
if (!defined('LOGA_SPECIAL_USERS')) {
    define('LOGA_SPECIAL_USERS', [
        '3808197' => ['name' => 'Laemmler, Birgit', 'shift' => '80', 'workdaysOnly' => true],
        '3805098' => ['name' => 'Philipp, Andreas', 'shift' => 'O', 'workdaysOnly' => true],
        '3011872' => ['name' => 'Ritz, Rainer', 'shift' => 'CA', 'workdaysOnly' => true],
    ]);
}

// ── Per-user base-shift overrides ──────────────────────────────────────────
// Replace a user's base shift code within a date range.
// Gutu (PNR 3017484): base shift is AT instead of O from 2026-10-01 to 2027-09-30.
// Format: pnr => [ ['from' => 'Y-m-d', 'to' => 'Y-m-d', 'fromShift' => 'O', 'toShift' => 'AT'], ... ]
if (!defined('LOGA_BASE_SHIFT_OVERRIDES')) {
    define('LOGA_BASE_SHIFT_OVERRIDES', [
        '3017484' => [
            ['from' => '2026-10-01', 'to' => '2027-09-30', 'fromShift' => 'O', 'toShift' => 'AT'],
        ],
    ]);
}

// Shift shortcuts that must never be deleted by the pusher (e.g. managed in LOGA).
if (!defined('LOGA_PROTECTED_SHIFTS')) {
    define('LOGA_PROTECTED_SHIFTS', ['AT']);
}

// ── Split creation via privateRPC ──────────────────────────────────────────
// Captured MaskActionSrv.callMaskAction envelope that creates a Dienstsplit
// (primary + partner). Placeholders are filled by LogaRpc::fill().
if (!defined('LOGA_SPLIT_ACTION_TEMPLATE')) {
    define('LOGA_SPLIT_ACTION_TEMPLATE',
        '7|3|37|{MODULE_BASE}|009B0BB5230AFAE10C0B84C2F1B1ACB2|49|{TOKEN}|_|callMaskAction|'
        . '3jw|2vm|com.piag.lweb.ui.masks.shared.actions.Action|4k9|*|3k0|37f|SBK|'
        . '4c060823-0fdb-43b0-b988-7377c71f3f49|8lz|4vl|y2em5e3qC1fcZrCggryW|LWSPEP|'
        . '8mb|5q4|5s5|4vh|8ks|{PARTNER_PNR}|c3ad345f-4112-4414-9ea0-91567242aa59|'
        . '5w1|{CONTEXT_ROLE_ID}|000|8m7|{FROM_DT}|{TO_DT}|8ke|{OBJS_ID}|{OWNER_PNR}|'
        . '37j|{SHIFT_ID}|1|2|3|4|5|6|4|7|8|9|10|7|11|12|0|13|LT1|13|LUS|0|0|14|15|16|0|17|18|0|0|0|'
        . '19|0|0|0|0|0|0|0|0|0|0|8|20|0|21|21|10|1|22|23|24|1|25|14|14|26|27|28|29|30|31|30|32|0|33|0|'
        . '14|34|23|-14|35|14|14|36|D$gt$|37|36|D$hDh|36|D$g5s|0|36|D$hDh|'
    );
}

// ── TTLs & Timeouts (from config/settings.json or defaults) ────────────────
if (!defined('LOGA_SESSION_TTL')) {
    define('LOGA_SESSION_TTL', (int)(_loga_config_val('LOGA_SESSION_TTL') ?? 1800));
}
if (!defined('LOGA_RUNTIME_CONFIG_TTL')) {
    define('LOGA_RUNTIME_CONFIG_TTL', (int)(_loga_config_val('LOGA_RUNTIME_CONFIG_TTL') ?? 21600));
}
if (!defined('LOGA_DATA_CACHE_TTL')) {
    define('LOGA_DATA_CACHE_TTL', (int)(_loga_config_val('LOGA_DATA_CACHE_TTL') ?? 86400));
}
if (!defined('LOGA_CURL_TIMEOUT')) {
    define('LOGA_CURL_TIMEOUT', (int)(_loga_config_val('LOGA_CURL_TIMEOUT') ?? 30));
}
if (!defined('LOGA_CURL_CONNECT_TIMEOUT')) {
    define('LOGA_CURL_CONNECT_TIMEOUT', (int)(_loga_config_val('LOGA_CURL_CONNECT_TIMEOUT') ?? 10));
}

// ── Rate Protection ────────────────────────────────────────────────────────
if (!defined('LOGA_REQUEST_DELAY_MS')) {
    define('LOGA_REQUEST_DELAY_MS', (int)(_loga_config_val('LOGA_REQUEST_DELAY_MS') ?? 500));
}
if (!defined('LOGA_BATCH_DELAY_MS')) {
    define('LOGA_BATCH_DELAY_MS', (int)(_loga_config_val('LOGA_BATCH_DELAY_MS') ?? 2000));
}
if (!defined('LOGA_POST_LOGIN_DELAY_MS')) {
    define('LOGA_POST_LOGIN_DELAY_MS', (int)(_loga_config_val('LOGA_POST_LOGIN_DELAY_MS') ?? 1000));
}
if (!defined('LOGA_SYNC_COOLDOWN')) {
    define('LOGA_SYNC_COOLDOWN', (int)(_loga_config_val('LOGA_SYNC_COOLDOWN') ?? 300));
}
if (!defined('LOGA_CIRCUIT_BREAKER_THRESHOLD')) {
    define('LOGA_CIRCUIT_BREAKER_THRESHOLD', (int)(_loga_config_val('LOGA_CIRCUIT_BREAKER_THRESHOLD') ?? 3));
}
if (!defined('LOGA_STALE_LOCK_TIMEOUT')) {
    define('LOGA_STALE_LOCK_TIMEOUT', (int)(_loga_config_val('LOGA_STALE_LOCK_TIMEOUT') ?? 600));
}
if (!defined('LOGA_MAX_RETRIES')) {
    define('LOGA_MAX_RETRIES', (int)(_loga_config_val('LOGA_MAX_RETRIES') ?? 3));
}
if (!defined('LOGA_RETRY_DELAY')) {
    define('LOGA_RETRY_DELAY', (int)(_loga_config_val('LOGA_RETRY_DELAY') ?? 5));
}

// ── Batch Sizes ────────────────────────────────────────────────────────────
if (!defined('LOGA_DEFAULT_BATCH_SIZE')) {
    define('LOGA_DEFAULT_BATCH_SIZE', (int)(_loga_config_val('LOGA_DEFAULT_BATCH_SIZE') ?? 5));
}
if (!defined('LOGA_SHIFT_BATCH_SIZE')) {
    define('LOGA_SHIFT_BATCH_SIZE', (int)(_loga_config_val('LOGA_SHIFT_BATCH_SIZE') ?? 20));
}

// ── Logging ────────────────────────────────────────────────────────────────
if (!defined('LOGA_LOG_LEVEL')) {
    define('LOGA_LOG_LEVEL', _loga_config_val('LOGA_LOG_LEVEL') ?? 'DEBUG');
}
if (!defined('LOGA_LOG_MAX_SIZE')) {
    define('LOGA_LOG_MAX_SIZE', (int)(_loga_config_val('LOGA_LOG_MAX_SIZE') ?? 1048576));
}

// ── File Paths (relative to loga/ directory) ───────────────────────────────
if (!defined('LOGA_DIR')) {
    define('LOGA_DIR', __DIR__);
}
if (!defined('LOGA_CACHE_DIR')) {
    define('LOGA_CACHE_DIR', __DIR__ . '/cache');
}
if (!defined('LOGA_LOG_DIR')) {
    define('LOGA_LOG_DIR', __DIR__ . '/logs');
}
if (!defined('LOGA_DATA_CACHE_FILE')) {
    define('LOGA_DATA_CACHE_FILE', LOGA_CACHE_DIR . '/loga_data.json');
}
if (!defined('LOGA_SESSION_FILE')) {
    define('LOGA_SESSION_FILE', LOGA_CACHE_DIR . '/loga_session.json');
}
if (!defined('LOGA_RUNTIME_CACHE_FILE')) {
    define('LOGA_RUNTIME_CACHE_FILE', LOGA_CACHE_DIR . '/loga_runtime_cache.json');
}
if (!defined('LOGA_COOLDOWN_FILE')) {
    define('LOGA_COOLDOWN_FILE', LOGA_CACHE_DIR . '/cooldown.json');
}
if (!defined('LOGA_LOCK_FILE')) {
    define('LOGA_LOCK_FILE', LOGA_CACHE_DIR . '/sync.lock');
}
if (!defined('LOGA_LOG_FILE')) {
    define('LOGA_LOG_FILE', LOGA_LOG_DIR . '/loga.log');
}

// ── Valid Modes ────────────────────────────────────────────────────────────
if (!defined('LOGA_MODES')) {
    define('LOGA_MODES', [
        'fetch'          => 'Fetch everything to JSON cache only',
        'process'        => 'Process existing JSON cache to database',
        'pull-shifts'    => 'Pull shifts from LOGA → local DB',
        'push-shifts'    => 'Push local shifts → LOGA',
        'pull-absences'  => 'Pull absences from LOGA → local DB',
        'pull-all'       => 'Pull shifts + absences from LOGA',
    ]);
}

// ── Holiday Region ─────────────────────────────────────────────────────────
if (!defined('LOGA_HOLIDAY_REGION')) {
    define('LOGA_HOLIDAY_REGION', _loga_config_val('LOGA_HOLIDAY_REGION') ?? 'BW');
}

// ── Helper: read from config/settings.json if available ────────────────────
function _loga_config_val(string $key): mixed
{
    static $config = null;
    if ($config === null) {
        $path = defined('DP_CONFIG_PATH') ? DP_CONFIG_PATH : __DIR__ . '/../config/settings.json';
        if (file_exists($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            }
        }
        if ($config === null) {
            $config = [];
        }
    }
    return $config[$key] ?? null;
}
