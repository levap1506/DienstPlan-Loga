<?php
/**
 * Read-only: fetch a date range from LOGA and list every shift that carries
 * split data (shiftSplitData). Useful to verify where a Mask split landed.
 *
 * Usage (must run as www-data):
 *   sudo -u www-data php loga/tools/split-probe/list-splits.php 2026-11-01 2026-12-31
 */

$root = dirname(__DIR__, 2);
require_once $root . '/config.php';
foreach (['LogaLogger', 'LogaClient', 'LogaRuntimeConfig', 'LogaAuth', 'LogaCache', 'LogaFetcher'] as $f) {
    require_once $root . '/' . $f . '.php';
}

$from = $argv[1] ?? date('Y-m-01');
$to   = $argv[2] ?? date('Y-m-t');

$logger = LogaLogger::getInstance();
$client = new LogaClient($logger);
$auth   = new LogaAuth($client, $logger);
if (!$auth->authenticate()) {
    fwrite(STDERR, "LOGA authentication failed\n");
    exit(1);
}

$cache = new LogaCache($logger);
$fetcher = (new LogaFetcher($client, $from, $to, $logger, $cache))->setSkipCache(true);
$result = $fetcher->fetch(true);

$rows = [];
foreach (($result['personsData'] ?? []) as $person) {
    $pnr  = $person['manAkPnrVertnr']['pnr'] ?? '';
    $name = $person['fullName'] ?? '';
    foreach (($person['shiftData'] ?? []) as $date => $day) {
        foreach (($day['shifts'] ?? []) as $shift) {
            $split = $shift['shiftSplitData'] ?? null;
            if (!$split) {
                continue;
            }
            $rows[] = sprintf(
                '%s pnr=%s %s order=%s ownerPnr=%s split=%s shift=%s iv=%s',
                $date,
                $pnr,
                $name,
                $split['order'] ?? '?',
                $split['manAkPnrVertnr']['pnr'] ?? '?',
                $split['splitShift_id'] ?? '?',
                $shift['shortcut'] ?? '?',
                json_encode($shift['timeInterval'] ?? null, JSON_UNESCAPED_UNICODE)
            );
        }
    }
}

sort($rows);
echo implode("\n", $rows) . "\n";
