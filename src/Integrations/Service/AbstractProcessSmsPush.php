<?php

namespace App\Integrations\Service;

use App\Domain\Integration\Integration;
use App\Intrastructure\Persistence\IntegrationRepository;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\SMS\GroupedSmsPushHistory;
use App\Intrastructure\Persistence\SmsRepository;
use App\Intrastructure\Persistence\MessageObjectsHistoryRepository;
use App\Domain\MessageObjectHistory\MessageObjectHistory;
use Ramsey\Uuid\Uuid;

/**
 * @property Integration $integration
 */
abstract class AbstractProcessSmsPush extends AbstractProcess
{
    protected SmsRepository $smsRepository;
    protected MessageObjectsHistoryRepository $messageObjectsHistoryRepository;

    public function __construct(
        IntegrationRepository $integrationRepository,
        IntegrationLoggerInterface $logger,
        SmsRepository $smsRepository,
        MessageObjectsHistoryRepository $messageObjectsHistoryRepository
    ) {
        parent::__construct($integrationRepository, $logger);
        $this->smsRepository = $smsRepository;
        $this->messageObjectsHistoryRepository = $messageObjectsHistoryRepository;
    }

    /**
     * @param Integration $integration
     * @return array
     */
    public function __process(Integration $integration, array $data): array {
        $this->integration = $integration;
        $groupedSmsPushHistory = $this->process($data);

        foreach($groupedSmsPushHistory->getHistory() as $conversationId => $conversationSmsIds) {
            $lastConversationSmsId = max($conversationSmsIds);
            $queryResult = $this->smsRepository->getConversationUuidAndMessageUuid(
                $conversationId,
                $lastConversationSmsId,
                $this->integration->getTeamId()
            );
            if (!empty($queryResult)) {
                sort($conversationSmsIds);
                $this->messageObjectsHistoryRepository->insert(
                    new MessageObjectHistory(
                        $this->integration->getIntegrationName(),
                        Uuid::fromBytes($queryResult['conversation_uuid'])->toString(),
                        Uuid::fromBytes($queryResult['mdr_uuid'])->toString(),
                        $this->integration->getTeamId(),
                        [
                            "conversation_id" => $conversationId,
                            "conversation_sms_ids" => $conversationSmsIds
                        ]
                    )
                );
            }
        }

        return [];
    }

    /**
     * @return array
     */
    abstract public function process(array $data): GroupedSmsPushHistory;
}
