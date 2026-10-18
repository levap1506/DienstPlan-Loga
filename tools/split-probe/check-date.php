<?php
/**
 * Read-only: fetch one date from LOGA and print the shifts of the given PNRs.
 *
 * Usage (must run as www-data):
 *   sudo -u www-data php loga/tools/split-probe/check-date.php 2026-12-20 3017484 3028559
 *   sudo -u www-data php loga/tools/split-probe/check-date.php 2026-12-20   # all persons
 */

$root = dirname(__DIR__, 2);
require_once $root . '/config.php';
foreach (['LogaLogger', 'LogaClient', 'LogaRuntimeConfig', 'LogaAuth', 'LogaCache', 'LogaFetcher'] as $f) {
    require_once $root . '/' . $f . '.php';
}

$date = $argv[1] ?? date('Y-m-d');
$pnrs = array_slice($argv, 2);

$logger = LogaLogger::getInstance();
$client = new LogaClient($logger);
$auth   = new LogaAuth($client, $logger);
if (!$auth->authenticate()) {
    fwrite(STDERR, "LOGA authentication failed\n");
    exit(1);
}

$cache = new LogaCache($logger);
$fetcher = (new LogaFetcher($client, $date, $date, $logger, $cache))->setSkipCache(true);
$result = $fetcher->fetch(true);

foreach (($result['personsData'] ?? []) as $person) {
    $pnr  = $person['manAkPnrVertnr']['pnr'] ?? '';
    $name = $person['fullName'] ?? '';
    if ($pnrs && !in_array($pnr, $pnrs, true)) {
        continue;
    }
    echo "PNR {$pnr} ({$name}) {$date}:\n";
    echo json_encode($person['shiftData'][$date] ?? null, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
