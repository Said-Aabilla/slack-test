<?php

namespace App\Integrations\SlackLight\V1\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\CallEvent\CallStatus;
use App\Domain\CallEvent\Service\CallHelper;
use App\Integrations\Service\AbstractProcessCallEvent;
use App\Integrations\Slack\V2\Service\ContactManager;
use App\Integrations\Slack\V2\Service\Slack;
use App\Integrations\Slack\V2\Service\SlackMessageBuilder;
use App\Intrastructure\Persistence\IntegrationRepository;
use Google\Exception;

/** @property SlackLight $integrationService */
class ProcessCallEvent extends AbstractProcessCallEvent
{


    private ContactManager $contactManager;
    private CallHelper $callHelper;

    public function __construct(
        ContactManager             $contactManager,
        CallHelper                 $callHelper,
        IntegrationRepository      $integrationRepository,
        IntegrationLoggerInterface $logger
    ) {
        parent::__construct($integrationRepository, $logger);
        $this->contactManager = $contactManager;
        $this->callHelper = $callHelper;
    }

    public function process(): ?bool
    {
        if (
            !$this->callHelper->callFilterV2(
                $this->callEvent,
                $this->integration->getConfiguration(),
                [CallStatus::INCOMING, CallStatus::INCALL, CallStatus::DIALED, CallStatus::MISSED, CallStatus::HANGUP]
            )
        ) {
            return false;
        }

        return $this->processCallEvent();
    }



    private function processCallEvent(): bool
    {

        $slackServiceData = $this->integration->getConfiguration();
        $initialCallEventText = createCallEventTextV2($this->callEvent, $slackServiceData, [], false, 'SlackLight');

        if (empty($initialCallEventText['title']) && empty($initialCallEventText['body'])) {
           $this->logger->integrationLog('STOP_PROCESSING','SLACK_LIGHT: CallEvent text is empty',['status' => $this->callEvent->status]);
            return false;
        }

        if(!empty($slackServiceData['channelsConfig'])){

            $channelsToNotify = $this->getChannelsToNotify($slackServiceData['channelsConfig']);

            if(empty($channelsToNotify)){
                $this->logger->integrationLog('STOP_PROCESSING','SLACK_LIGHT: No channels to notify', ['channelsConfig' => $slackServiceData['channelsConfig']]);
                return false;
            }

            $contactInfos = $this->getContactsForSlack();

            try {
                $formattedMsg = SlackMessageBuilder::createSlackBlocksForCall(
                    $this->callEvent,
                    $slackServiceData,
                    $initialCallEventText,
                    $contactInfos,
                    $this->logger
                );
            } catch (Exception $e) {
                $this->logger->integrationLog('STOP_PROCESSING', 'Failed to create message body', ['error' => $e->getMessage()]);
            }

            foreach ($channelsToNotify as $channel){
                try {
                    $response = $this->integrationService->postSlackMessage($this->integration, $channel, $formattedMsg);
                } catch (Exception $e) {
                    // Process message failed
                    $this->logger->integrationLog('STOP_PROCESSING', 'SLACK_LIGHT: Message could not be sent', ['msg' => $formattedMsg, 'response' => $response]);
                }
            }

        }

        return true;
    }

    protected function getContactsForSlack(): array {

         $ringoverUserTeamId = intval($this->callEvent->firstRingoverUser['team_id']);
         $ringoverUserId =  intval($this->callEvent->firstRingoverUser['id']);

        $externalContact = $this->contactManager->getSynchronizedContacts($ringoverUserTeamId, $ringoverUserId,
            ltrim($this->callEvent->e164CustomerNumber, '+'), Slack::MAX_CONTACTS_TO_SEARCH, "SLACK_QUICKTALK");

        // Aucun contact trouvé, renvoie un contact vide
        if (empty($externalContact)) {
            $contact = $this->integrationService->emptyIntegrationContactIdentity($this->callEvent->e164CustomerNumber);
            return [$contact];
        }

        // Un contact trouvé
        $contact =  $this->integrationService->mapExternalToIntegrationContactIdentity($externalContact, $this->callEvent->e164CustomerNumber);
        return [$contact];
    }

    private function getChannelsToNotify(array $channelsConfigData): array
    {
        $channels = [];

        // Get the user number from the call event
        $ringoverNumber = $this->callEvent->ringoverNumber;

        // Convert call status to event type for matching
        $eventType = $this->convertCallStatusToEventType($this->callEvent->status);

        // Look through the channels config
        foreach ($channelsConfigData as $channelConfig) {
            // Check if the phone number matches
            if (!empty($channelConfig['phone_number']) && $this->isPhoneNumberMatch($ringoverNumber, $channelConfig['phone_number'])) {
                // Check if the event type is in the allowed events for this channel
                if (!empty($channelConfig['event_types']) && is_array($channelConfig['event_types'])
                    && in_array($eventType, $channelConfig['event_types'])) {
                    // Add this channel to our result
                    $channels[] = $channelConfig['channel_id'];
                }
            }
        }

        if (empty($channels)) {
            $this->logger->integrationLog('INFO', 'SLACK_LIGHT: No matching channels found for call event',
                ['number' => $ringoverNumber, 'status' => $this->callEvent->status]);
        }

        //this is just for testing, later it will be return $channels;
        return ['C08KMSMPBDZ'];
    }

    /**
     * Convert CallStatus constants to event type strings used in channel config
     */
    private function convertCallStatusToEventType(string $callStatus): ?string
    {
        switch ($callStatus) {
            case CallStatus::INCOMING:
            case CallStatus::INCALL:
                return 'incoming';
            case CallStatus::DIALED:
            case CallStatus::HANGUP:
                return 'outgoing';
            case CallStatus::MISSED:
                return 'missed';
            case CallStatus::VOICEMAIL:
                return 'voicemail';
            default:
                return null;
        }
    }

    /**
     * Check if the phone numbers match, accounting for possible formatting differences
     */
    private function isPhoneNumberMatch(string $callNumber, string $configNumber): bool
    {
        // Strip any non-digit characters for comparison
        $callNumberDigits = preg_replace('/\D/', '', $callNumber);
        $configNumberDigits = preg_replace('/\D/', '', $configNumber);

        // Check if one is contained within the other
        return strpos($callNumberDigits, $configNumberDigits) !== false ||
            strpos($configNumberDigits, $callNumberDigits) !== false;
    }
}