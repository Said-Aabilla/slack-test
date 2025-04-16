<?php

namespace App\Integrations\Slack\V2\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\CallEvent\Call;
use App\Domain\ContactSync\Service\ManageSync;
use App\Domain\Integration\Integration;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Integrations\Service\AbstractContactManager;
use App\Intrastructure\Service\WebsocketClient;

class ContactManager extends AbstractContactManager
{
    private ManageSync $contactSyncService;
    private IntegrationLoggerInterface $logger;

    public function __construct(
        WebsocketClient            $websocketClient,
        IntegrationLoggerInterface $logger,
        ManageSync                 $contactSyncService
    ) {
        parent::__construct($websocketClient, $logger);
        $this->contactSyncService = $contactSyncService;
        $this->logger = $logger;
    }

    public function getContactURL(
        Call                       $call,
        IntegrationContactIdentity $contactIdentity,
        Integration                $integration
    ): string {
        return '';
    }

    /**
     * @param int $teamId
     * @param int $userId
     * @param mixed $phoneNumber int or string
     * @param int $limit
     * @param bool $debugMode
     * @return array
     */
    public function getSynchronizedContacts(
        int  $teamId,
        int  $userId,
             $phoneNumber,
        int  $limit = 3,
        bool $debugMode = false,
        string $integrationName = 'SLACK'
    ): array {
        // Numbers in search list are int
        $phoneNumber = intval(ltrim($phoneNumber, '+'));
        $response = $this->contactSyncService
            ->searchContactsByNumbersV4($teamId, $userId, [$phoneNumber], $debugMode,$integrationName );
        $contactInfo = $response[$phoneNumber] ?? [];
        $this->logger->contactSearchLog($phoneNumber, !empty($contactInfo), ['source' => 'contacts v4']);

        return $contactInfo;
    }
}