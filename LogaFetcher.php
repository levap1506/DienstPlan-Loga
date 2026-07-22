<?php
/**
 * LOGA Portal - Data Fetcher
 * 
 * Fetches persons list and persons data (shifts + absences) from the LOGA API.
 * Supports batched fetching with retry logic and writes results to JSON cache.
 * This is the "fetch" mode — no database writes, only cache population.
 * 
 * @author  DienstPlan System
 * @date    2026-04-17
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaClient.php';
require_once __DIR__ . '/LogaCache.php';

class LogaFetcher {
    private LogaClient $client;
    private LogaLogger $logger;
    private LogaCache $cache;

    private string $dateFrom;
    private string $dateTo;
    private string $monthKey;
    private int $batchSize;
    private bool $skipCache = false;

    public function __construct(
        LogaClient $client,
        string $dateFrom,
        string $dateTo,
        ?LogaLogger $logger = null,
        ?LogaCache $cache = null,
        int $batchSize = 0
    ) {
        $this->client = $client;
        $this->logger = $logger ?? LogaLogger::getInstance();
        $this->cache = $cache ?? new LogaCache($this->logger);
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->monthKey = date('Y-m', strtotime($dateFrom));
        $this->batchSize = $batchSize ?: LOGA_DEFAULT_BATCH_SIZE;
    }

    /**
     * When true, skip writing to the JSON cache after fetching.
     * Used when the caller handles cache writes itself (e.g. filtered per-month slices).
     */
    public function setSkipCache(bool $skip): self {
        $this->skipCache = $skip;
        return $this;
    }

    /**
     * Build the clientContext payload used in LOGA API requests.
     */
    private function buildClientContext(): array {
        return [
            'interval' => [
                'dateFrom' => $this->dateFrom,
                'dateTo'   => $this->dateTo,
            ],
            'countryNls'    => LOGA_COUNTRY_NLS,
            'man'           => LOGA_MANDANT,
            'contextRoleId' => LOGA_CONTEXT_ROLE_ID,
        ];
    }

    /**
     * Build the path payload used in LOGA API requests.
     */
    private function buildPath(): array {
        return ['path' => LOGA_OBJEKT_PATH];
    }

    /**
     * Fetch everything from LOGA and write to JSON cache.
     * Returns a result summary.
     * 
     * @param bool $force Bypass cache (re-fetch even if fresh)
     * @return array Result with keys: success, persons, personsData, fromCache, stats
     */
    public function fetch(bool $force = false): array {
        // Check cache first
        if (!$force) {
            $cached = $this->cache->read($this->monthKey);
            if ($cached !== null) {
                $this->logger->info(
                    "Using cached data for {$this->monthKey} (fetched: {$cached['fetchedAt']})",
                    'LogaFetcher'
                );
                return [
                    'success'     => true,
                    'persons'     => $cached['persons'],
                    'personsData' => $cached['personsData'],
                    'fromCache'   => true,
                    'stats'       => [
                        'personsCount'     => count($cached['persons']),
                        'personsDataCount' => count($cached['personsData']),
                    ],
                ];
            }
        }

        $this->logger->info("Fetching data from LOGA for {$this->dateFrom} to {$this->dateTo}", 'LogaFetcher');

        // Step 1: Fetch persons list
        $persons = $this->fetchPersonsList();
        if (empty($persons)) {
            $this->logger->info("No persons found for date range", 'LogaFetcher');
            return [
                'success'     => true,
                'persons'     => [],
                'personsData' => [],
                'fromCache'   => false,
                'stats'       => ['personsCount' => 0, 'personsDataCount' => 0],
            ];
        }

        // Step 2: Fetch persons data in batches
        $personsData = $this->fetchPersonsData($persons);

        // Step 3: Write to cumulative cache (unless caller handles caching itself)
        if (!$this->skipCache) {
            $this->cache->write($this->monthKey, $this->dateFrom, $this->dateTo, $persons, $personsData);
        }

        $stats = [
            'personsCount'     => count($persons),
            'personsDataCount' => count($personsData),
        ];

        $this->logger->info(
            "Fetch complete: {$stats['personsCount']} persons, {$stats['personsDataCount']} data records",
            'LogaFetcher'
        );

        return [
            'success'     => true,
            'persons'     => $persons,
            'personsData' => $personsData,
            'fromCache'   => false,
            'stats'       => $stats,
        ];
    }

    /**
     * Fetch the persons list from LOGA.
     * 
     * @return array Array of person objects
     */
    public function fetchPersonsList(): array {
        $this->logger->info("Fetching persons list...", 'LogaFetcher');

        $payload = [
            'clientContext' => $this->buildClientContext(),
            'path'          => $this->buildPath(),
        ];

        $response = $this->client->apiRequestWithRetry(
            'private/api/spepdataservice/loadspepobjektpersons',
            $payload
        );

        if (!isset($response['persons']) || !is_array($response['persons'])) {
            throw new \RuntimeException("Invalid persons response: missing 'persons' array");
        }

        $count = count($response['persons']);
        $this->logger->info("Loaded {$count} persons", 'LogaFetcher');

        return $response['persons'];
    }

    /**
     * Fetch detailed persons data (shifts + absences) in batches.
     * 
     * @param array $persons Persons list from fetchPersonsList()
     * @return array Combined persons data from all batches
     */
    public function fetchPersonsData(array $persons): array {
        $batches = array_chunk($persons, $this->batchSize);
        $totalBatches = count($batches);
        $allResults = [];
        $failedBatches = 0;

        $this->logger->info("Fetching persons data: {$totalBatches} batches of {$this->batchSize}", 'LogaFetcher');

        foreach ($batches as $index => $batch) {
            $batchNum = $index + 1;

            $payload = [
                'clientContext' => $this->buildClientContext(),
                'path'          => $this->buildPath(),
                'persons'       => $batch,
            ];

            try {
                $this->logger->debug(
                    "Processing batch {$batchNum}/{$totalBatches} (" . count($batch) . " persons)",
                    'LogaFetcher'
                );

                $response = $this->client->apiRequestWithRetry(
                    'private/api/spepdataservice/loadspepobjektpersonsdata',
                    $payload
                );

                if (isset($response['persons']) && is_array($response['persons'])) {
                    $allResults = array_merge($allResults, $response['persons']);
                    $this->logger->info(
                        "Batch {$batchNum}/{$totalBatches} OK — " . count($response['persons']) . " records",
                        'LogaFetcher'
                    );
                } else {
                    throw new \RuntimeException("Unexpected response format in batch {$batchNum}");
                }
            } catch (\Exception $e) {
                $failedBatches++;
                $this->logger->error(
                    "Batch {$batchNum}/{$totalBatches} FAILED permanently: " . $e->getMessage(),
                    'LogaFetcher'
                );
            }

            // Inter-batch delay
            if ($batchNum < $totalBatches) {
                $this->client->batchDelay();
            }
        }

        if ($failedBatches > 0) {
            $this->logger->error(
                "Fetched {$failedBatches} failed batches out of {$totalBatches}",
                'LogaFetcher'
            );
            throw new \RuntimeException("{$failedBatches} batches failed during data fetching");
        }

        $this->logger->info(
            "All batches complete: " . count($allResults) . " person records fetched",
            'LogaFetcher'
        );

        return $allResults;
    }
}
