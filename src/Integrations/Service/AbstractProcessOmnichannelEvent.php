<?php

namespace App\Integrations\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Exception\IntegrationException;
use App\Domain\Integration\Integration;
use App\Domain\MessageObjectHistory\MessageObjectHistory;
use App\Domain\OmnichannelEvent\OmnichannelEvent;
use App\Domain\OmnichannelEvent\Service\OmnichannelHelper;
use App\Intrastructure\Persistence\MessageObjectsHistoryRepository;
use DateTime;
use Fig\Http\Message\StatusCodeInterface;
use InvalidArgumentException;

abstract class AbstractProcessOmnichannelEvent
{
    const REGEX_PATTERN_24H_7D = '/([0-9]|1[0-9]|2[0-4])h|1d|7d/';

    protected IntegrationLoggerInterface $logger;
    protected MessageObjectsHistoryRepository $messageObjectsHistoryRepository;
    protected OmnichannelHelper $omnichannelHelper;
    protected OmnichannelEvent $omnichannelEvent;
    protected AbstractIntegration $integrationService;
    protected Integration $integration;

    /**
     * @param IntegrationLoggerInterface $logger
     * @param OmnichannelHelper $omnichannelHelper
     * @param MessageObjectsHistoryRepository $messageObjectsHistoryRepository
     */
    public function __construct(
        IntegrationLoggerInterface $logger,
        OmnichannelHelper $omnichannelHelper,
        MessageObjectsHistoryRepository $messageObjectsHistoryRepository
    ) {
        $this->logger = $logger;
        $this->omnichannelHelper = $omnichannelHelper;
        $this->messageObjectsHistoryRepository = $messageObjectsHistoryRepository;
    }

    /**
     * @throws \Exception
     */
    protected function createOmnichannelLogMessageForSameLoggingWhatsapp(): void
    {
        $this->omnichannelHelper->generateOmnichannelFullConversationLogForWhatsapp(
            $this->omnichannelEvent,
            $this->integration->getConfiguration()
        );
    }

    /**
     * @throws \Exception
     */
    protected function createOmnichannelSeperateMessageForWhatsapp(): void
    {
        $this->omnichannelHelper->generateOmnichannelSeperateMessageForWhatsapp(
            $this->omnichannelEvent,
            $this->integration->getConfiguration()
        );
    }

    protected function getCurrentMessageObjectHistory(): ?MessageObjectHistory
    {
        return $this->messageObjectsHistoryRepository->getObjectMessageByMessageUuid(
            $this->omnichannelEvent->eventData['message']['uuid'],
            $this->omnichannelEvent->omnichannelConversation->getUuid(),
            $this->integration->getIntegrationAliasOrName()
        );
    }

    protected function insertNewMessageObjectHistory(array $objectData): void
    {
        $this->messageObjectsHistoryRepository->insert(
            new MessageObjectHistory(
                $this->integration->getIntegrationAliasOrName(),
                $this->omnichannelEvent->omnichannelConversation->getUuid(),
                $this->omnichannelEvent->eventData['message']['uuid'],
                $this->integration->getTeamId(),
                $objectData
            )
        );
    }

    public function updateMessageObjectHistory(MessageObjectHistory $messageObjectHistory): void
    {
        $this->messageObjectsHistoryRepository->update($messageObjectHistory);
    }

    protected function deleteMessageObjectHistoryByMessageUuid(string $messageUuid): void
    {
        $this->messageObjectsHistoryRepository->delete(
            $messageUuid,
            $this->integration->getIntegrationAliasOrName()
        );
    }

    /**
     * Get last conv from DB
     * Conversations grouped by default or customized setting
     * @throws InvalidArgumentException
     * @throws IntegrationException
     * @return MessageObjectHistory|null
     */
    protected function getLastMessageObjectHistoryToContinue(): ?MessageObjectHistory
    {
        $convGroupBy = $this->integration->getConfiguration()['whatsappConvGroupBy'] ?? 'conv';

        // 1. Group by conversation. Defaut case, group by not set
        if ('conv' === $convGroupBy) {
            // Get last messageObject of the same conversation
            return $this->getLastMessageObjectHistory();
        }

        // 2. Group by hours, day or week
        if (!preg_match(self::REGEX_PATTERN_24H_7D, $convGroupBy)) {
            throw new InvalidArgumentException(
                "Invalid conversation group by value: $convGroupBy",
                StatusCodeInterface::STATUS_BAD_REQUEST
            );
        }

        $currentMsgCreationDate = new DateTime($this->omnichannelEvent->eventData['message']['creation_date']);
        $localMsgCreationDate   = $currentMsgCreationDate->setTimezone($this->omnichannelEvent->ringoverUser->timezone);
        $localInterval          = $this->omnichannelHelper
            ->calculateCreationDateTimeInterval($localMsgCreationDate, $convGroupBy);

        // Start and end dateTime are same, error
        if ($localInterval['startsAt'] === $localInterval['endsAt']) {
            throw new IntegrationException('Identique start and end datetime');
        }

        return $this->getLastMessageObjectHistoryBetweenDateTimeInterval(
            $localInterval['startsAt'],
            $localInterval['endsAt']
        );
    }

    protected function isNewMessageAlreadyTreated() : bool
    {
        return !is_null($this->messageObjectsHistoryRepository->getObjectMessageByMessageUuid(
            $this->omnichannelEvent->eventData['message']['uuid'],
            $this->omnichannelEvent->omnichannelConversation->getUuid(),
            $this->integrationService->getIntegrationName()
        ));
    }

    private function getLastMessageObjectHistoryBetweenDateTimeInterval(
        DateTime $startsAt,
        DateTime $endsAt
    ): ?MessageObjectHistory {
        return $this->messageObjectsHistoryRepository
            ->getLastObjectMessageByUuidAndDatetimeInterval(
                $this->omnichannelEvent->omnichannelConversation->getUuid(),
                $this->integration->getIntegrationAliasOrName(),
                $startsAt,
                $endsAt
            );
    }

    protected function getLastMessageObjectHistory(): ?MessageObjectHistory
    {
        return $this->messageObjectsHistoryRepository
            ->getLastObjectMessageByConversationUuidAndIntegrationName(
                $this->omnichannelEvent->omnichannelConversation->getUuid(),
                $this->integration->getIntegrationAliasOrName()
            );
    }

    abstract public function process(Integration $integration, OmnichannelEvent $omnichannelEvent);
}
