<?php
/**
 * LOGA Portal - Split Shift Creator
 *
 * Creates a Dienstsplit in LOGA via the encrypted Mask privateRPC
 * (MaskActionSrv.callMaskAction), using the captured envelope template
 * LOGA_SPLIT_ACTION_TEMPLATE and substituting persons/date/shift.
 *
 * @author  DienstPlan System
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/LogaLogger.php';
require_once __DIR__ . '/LogaClient.php';
require_once __DIR__ . '/LogaRpc.php';

class LogaSplitCreator {
    private LogaClient $client;
    private array $runtimeConfig;
    private LogaLogger $logger;

    public function __construct(LogaClient $client, array $runtimeConfig, ?LogaLogger $logger = null) {
        $this->client = $client;
        $this->runtimeConfig = $runtimeConfig;
        $this->logger = $logger ?? LogaLogger::getInstance();
    }

    /**
     * Create a split duty in LOGA.
     *
     * @param string $ownerPnr        PNR of the primary (order 0) person
     * @param string $partnerPnr      PNR of the second (order 1) person
     * @param string $date            Split date (YYYY-MM-DD)
     * @param string $shiftOriginalId LOGA shift id, e.g. '*|ÄDNCHR06|O4'
     * @param string $objsId          Objekt short id, e.g. 'VSÄDNCH'
     * @param string $contextRoleId   LOGA context role id
     * @return array
     */
    public function create(
        string $ownerPnr,
        string $partnerPnr,
        string $date,
        string $shiftOriginalId,
        string $objsId,
        string $contextRoleId = LOGA_CONTEXT_ROLE_ID
    ): array {
        $yearMonth = substr($date, 0, 7);
        $from = $yearMonth . '-01T00:00:00.000';
        $to   = date('Y-m-t', strtotime($yearMonth . '-01')) . 'T00:00:00.000';

        $token      = $this->client->getXsrfToken();
        $version    = $this->runtimeConfig['logaVersion'] ?? '';
        $moduleBase = LOGA_BASE_URL . "bts/{$version}/L2Main/";

        $envelope = LogaRpc::fill(LOGA_SPLIT_ACTION_TEMPLATE, [
            'MODULE_BASE'     => $moduleBase,
            'TOKEN'           => $token,
            'PARTNER_PNR'     => $partnerPnr,
            'OWNER_PNR'       => $ownerPnr,
            'CONTEXT_ROLE_ID' => $contextRoleId,
            'OBJS_ID'         => $objsId,
            'SHIFT_ID'        => $shiftOriginalId,
            'FROM_DT'         => $from,
            'TO_DT'           => $to,
        ]);

        $this->logger->info(
            "Creating LOGA split: owner={$ownerPnr} partner={$partnerPnr} date={$date} shift={$shiftOriginalId}",
            'SplitCreator'
        );

        $response = $this->client->privateRpc('MaskActionSrv', $envelope, $this->runtimeConfig);

        $decoded = null;
        try {
            $decoded = LogaRpc::decryptBody($token, $response);
        } catch (\Throwable $e) {
            $this->logger->debug('Split response not encrypted: ' . substr($response, 0, 120), 'SplitCreator');
        }

        $this->logger->info(
            'Split response: ' . substr($decoded ?? $response, 0, 300),
            'SplitCreator'
        );

        return ['success' => true, 'response' => $response, 'decoded' => $decoded];
    }
}
