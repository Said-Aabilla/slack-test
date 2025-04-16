<?php

namespace App\Integrations\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Exception\IntegrationException;
use App\Domain\Integration\Integration;
use App\Domain\MessageObjectHistory\MessageObjectHistory;
use App\Domain\SMSEvent\Service\SmsHelper;
use App\Domain\SMSEvent\SMS;
use App\Intrastructure\Persistence\IntegrationRepository;
use App\Intrastructure\Persistence\MessageObjectsHistoryRepository;
use DateTime;
use Fig\Http\Message\StatusCodeInterface;
use InvalidArgumentException;

abstract class AbstractProcessSmsEvent extends AbstractProcess
{
    /**
     * @var SMS
     */
    protected SMS $smsEvent;

    protected const NOT_GROUP = 'not_group';

    protected MessageObjectsHistoryRepository $messageObjectsHistoryRepository;

    public function __construct(
        IntegrationRepository $integrationRepository,
        IntegrationLoggerInterface $logger,
        MessageObjectsHistoryRepository $messageObjectsHistoryRepository
    ) {
        parent::__construct($integrationRepository, $logger);

        $this->messageObjectsHistoryRepository = $messageObjectsHistoryRepository;
    }

    /**
     * @param Integration $integration
     * @param SMS $smsEvent
     * @return void
     */
    public function __process(
        Integration $integration,
        SMS $smsEvent
    ) {
        $this->integration = $integration;
        $this->smsEvent    = $smsEvent;

        $this->process();
    }

    /**
     * Code métier de gestion de l'évènement téléphonique
     * @return mixed
     */
    abstract public function process();

    /**
     * @return MessageObjectHistory|null
     */
    public function getCurrentMessageObjectHistory(): ?MessageObjectHistory
    {
        if (empty($this->smsEvent->conversationUuid) || empty($this->smsEvent->messageUuid)) {
            return null;
        }

        return $this->messageObjectsHistoryRepository->getObjectMessageByMessageUuid(
            $this->smsEvent->messageUuid,
            $this->smsEvent->conversationUuid,
            $this->integration->getIntegrationName()
        );
    }

    /**
     * @param SmsHelper $smsHelper
     * @throws InvalidArgumentException
     * @throws IntegrationException
     * @return MessageObjectHistory|null
     */
    public function getLastMessageObjectHistoryToContinue(SmsHelper $smsHelper): ?MessageObjectHistory
    {
        $convGroupBy = $this->integration->getConfiguration()['smsConvGroupBy'] ?? self::NOT_GROUP;

        // 1. Do not group
        if ($convGroupBy === self::NOT_GROUP) {
            return null;
        }

        // 2. Group by conversation.
        if ('conv' === $convGroupBy) {
            // Get last messageObject of the same conversation
            return $this->messageObjectsHistoryRepository->
                getLastObjectMessageByConversationUuidAndIntegrationName(
                    $this->smsEvent->conversationUuid,
                    $this->integration->getIntegrationName()
                );
        }

        // 3. Group by hours, day or week
        if (!preg_match(AbstractProcessOmnichannelEvent::REGEX_PATTERN_24H_7D, $convGroupBy)) {
            throw new InvalidArgumentException(
                "Invalid conversation group by value: $convGroupBy",
                StatusCodeInterface::STATUS_BAD_REQUEST
            );
        }

        $currentMsgCreationDate = $localMsgCreationDate = $this->smsEvent->utcSMSDate;
        if (!is_null($this->smsEvent->ringoverUser) && !is_null($this->smsEvent->ringoverUser->timezone)) {
            $localMsgCreationDate = (clone $currentMsgCreationDate)
                ->setTimezone($this->smsEvent->ringoverUser->timezone);
        }
        $localInterval = $smsHelper->
            calculateCreationDateTimeInterval($localMsgCreationDate, $convGroupBy);

        // Start and end dateTime are same, error
        if ($localInterval['startsAt'] === $localInterval['endsAt']) {
            throw new IntegrationException('Identique start and end datetime');
        }

        return $this->getLastMessageObjectHistoryBetweenDateTimeInterval(
            $localInterval['startsAt'],
            $localInterval['endsAt']
        );
    }

    public function insertNewMessageObjectHistory(array $objectData): void
    {
        $this->messageObjectsHistoryRepository->insert(
            new MessageObjectHistory(
                $this->integration->getIntegrationName(),
                $this->smsEvent->conversationUuid,
                $this->smsEvent->messageUuid,
                $this->integration->getTeamId(),
                $objectData
            )
        );
    }

    public function updateMessageObjectHistory(MessageObjectHistory $messageObjectHistory): void
    {
        $this->messageObjectsHistoryRepository->update($messageObjectHistory);
    }

    public function deleteMessageObjectHistoryByMessageUuid(string $messageUuid): void
    {
        $this->messageObjectsHistoryRepository->delete($messageUuid, $this->integration->getIntegrationName());
    }

    private function getLastMessageObjectHistoryBetweenDateTimeInterval(
        DateTime $startsAt,
        DateTime $endsAt
    ): ?MessageObjectHistory {
        return $this->messageObjectsHistoryRepository
            ->getLastObjectMessageByUuidAndDatetimeInterval(
                $this->smsEvent->conversationUuid,
                $this->integration->getIntegrationName(),
                $startsAt,
                $endsAt
            );
    }

}
