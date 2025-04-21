<?php

namespace App\Integrations\Slack\V2\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\CallEvent\Call;
use App\Domain\CallEvent\CallStatus;
use App\Domain\CallEvent\Service\CallHelper;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Integrations\Service\AbstractProcessCallEvent;
use App\Intrastructure\Persistence\IntegrationRepository;
use Google\Exception;
use function PHPUnit\Framework\isEmpty;


/** @property Slack $integrationService */
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

    /**
     * @throws Exception
     */
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

        if(!$this->callEvent->afterCall){
            return $this->processCallEvent();
        }else{
            return $this->processAfterCallEvent();
        }

    }

    /**
     * @throws Exception
     */
    private function processCallEvent(): bool
    {
        $slackServiceData = $this->integration->getConfiguration();

        $initialCallEventText = createCallEventTextV2($this->callEvent, $slackServiceData, [], false, 'Slack');
        if (empty($initialCallEventText['title']) && empty($initialCallEventText['body'])) {
            $this->logger->integrationLog('STOP_PROCESSING','SLACK: CallEvent text is empty',['status' => $this->callEvent->status]);
            return false;
        }

        if(!empty($slackServiceData['channelsConfig'])){

            $channelsToNotify = $this->getChannelsToNotify($slackServiceData['channelsConfig']);

            if(empty($channelsToNotify)){
                return false;
            }

            $contactInfos = $this->getContactInfos();

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


                $ringoverNumber = $this->callEvent->ringoverNumber;
                $ivrNumber = $this->callEvent->isIVR ? strval($this->callEvent->ivrNumber) : '';

                /**
                 * 1. If callObjectHistory exists, means a message has been posted.
                 *    Update that message, update callObjectHistory.
                 * 2. If history does not exist, means it's the first event/message.
                 *    Send message and save infos to DB.
                 */
                if (isset($this->callEvent->integrations['SLACK']['call_objects'])) {
                    foreach ($this->callEvent->integrations['SLACK']['call_objects'] as $callObject) {
                        $newStatus = $this->getCallStatusForSlack();
                        $oldStatus = $callObject['objectData']['status'];

                        /** @var bool $isNbrsEqual caller and callee numbers match thoses in history */
                        if ($this->callEvent->isIVR) {
                            if (CallStatus::MISSED === $newStatus && CallStatus::INCALL === $oldStatus) {
                                $newStatus = CallStatus::INCALL;
                            }
                            // If an agent picked up (incall), show incall to all agents.
                            if (CallStatus::INCALL === $newStatus && CallStatus::INCOMING === $oldStatus) {
                                $isNbrsEqual = $ivrNumber === $callObject['objectData']['ivrNumber'];
                            } else {
                                $isNbrsEqual = $ivrNumber === $callObject['objectData']['ivrNumber']
                                    && strval($this->callEvent->customerNumber) === $callObject['objectData']['customerNumber'];
                            }
                        } else {
                            $isNbrsEqual = $ringoverNumber === $callObject['objectData']['ringoverNumber']
                                && strval($this->callEvent->customerNumber) === $callObject['objectData']['customerNumber'];
                        }

                        // For anonymous caller, it may be set in history for previous status
                        if ('anonymous' === $callObject['objectData']['customerNumber']) {
                            // Set customer number to anonymous if it's not for current status
                            if ('anonymous' !== $realCustomerNbr = strval($this->callEvent->customerNumber)) {
                                // Replace explicite number with "anonymous"
                                $formattedMsg = json_decode(
                                    preg_replace(
                                        '/\+?' . $realCustomerNbr . '/',
                                        'anonymous',
                                        json_encode($formattedMsg)
                                    ),
                                    true
                                );
                            }
                            // Compare Ringover numbers for voicemail
                            if ('voicemail' === $newStatus && CallStatus::MISSED === $oldStatus) {
                                $isNbrsEqual = $this->callEvent->isIVR
                                    ? $ivrNumber === $callObject['objectData']['ivrNumber']
                                    : $ringoverNumber === $callObject['objectData']['ringoverNumber'];
                            }
                            // Remove callback btn if exist
                            foreach ($formattedMsg['attachments'][0]['blocks'] as $bk => $block) {
                                if (!isset($block['type']) || !isset($block['id'])) {
                                    // Test and debug
                                    integrationLog('SLACK DEBUG', 'Empty block type or id', ['block' => $block]);
                                }
                                elseif ('actions' === $block['type'] || 'action' === $block['id']) {
                                    $actionBlockElements = $block['elements'];
                                    foreach ($actionBlockElements as $ek => $element) {
                                        if ('btn_call' === $element['action_id']) {
                                            unset($formattedMsg['attachments'][0]['blocks'][$bk]['elements'][$ek]);
                                        }
                                    }
                                }
                            }
                        }

                        // Get real channel from saved call object history
                        $savedChannel = $callObject['objectData']['channel'] ?? '';
                        // Get real timestamp from saved call object history
                        $savedTs = $callObject['objectData']['ts'] ?? '';

                        /**
                         * Continue if:
                         * new status is same as saved one
                         * new status is invalid
                         * formatted message is empty
                         * saved ts is empty
                         */
                        if (
                            !$isNbrsEqual
                            || $newStatus === $oldStatus
                            || !in_array($newStatus, [CallStatus::INCALL, CallStatus::HANGUP, CallStatus::MISSED, 'voicemail'])
                            || empty($formattedMsg)
                            || empty($savedTs)
                        ) {
                            // Log
                            $this->logger->updateCallObjectLog(
                                $savedChannel . '::' . $savedTs,
                                false,
                                [
                                    'isNbrEqual' => $isNbrsEqual,
                                    'newStatus'  => $newStatus,
                                    'oldStatus'  => $oldStatus,
                                    'isMsgEmpty' => empty($formattedMsg),
                                    'event'      => 'call'
                                ]
                            );
                            continue;
                        }

                        try {
                            // UPDATE the corresponding message to the call event.
                            $this->integrationService->updateSlackMessage($this->integration, $savedChannel, $savedTs, $formattedMsg);

                            // Update callObjectHistory
                            $callObject['objectData']['status'] = $newStatus;

                            $this->integrationRepository->saveIntegrationCallObject(
                                $this->integration->getIntegrationName(),
                                $this->callEvent->callId,
                                $this->callEvent->channelId,
                                $this->integration->getTeamId(),
                                $callObject['objectData'],
                                $callObject['id']
                            );

                            // Log message updating
                            $this->logger->updateCallObjectLog(
                                $savedChannel . '::' . $savedTs,
                                true,
                                [
                                    'callId'      => $this->callEvent->callId,
                                    'newStatus'   => $newStatus,
                                    'agentStatus' => $this->callEvent->agentStatus
                                ]
                            );

                        } catch (Exception $e) {
                            // Log message updating
                            $this->logger->updateCallObjectLog(
                                $savedChannel . '::' . $savedTs,
                                false,
                                [
                                    'callId'      => $this->callEvent->callId,
                                    'newStatus'   => $newStatus,
                                    'agentStatus' => $this->callEvent->agentStatus,
                                    'details'     => json_decode($e->getMessage(), true),
                                    'msg'         => $formattedMsg
                                ]
                            );

                            throw $e;
                        }
                    }
                } else {
                    //region Post message, insert callObjectHistory
                    try {
                        // Post message
                        $result = $this->integrationService->postSlackMessage($this->integration, $channel, $formattedMsg);
                        $status = $this->getCallStatusForSlack();

                        /**
                         * Retrieve real channel.
                         * For DM, channel (userID) to post is different than the channel in which message is created.
                         */
                        $channel = $result['channel'];

                        // Prepare history data
                        $objectData = [
                            'ringoverNumber' => strval($this->callEvent->ringoverNumber),
                            'customerNumber' => strval($this->callEvent->customerNumber),
                            'ivrNumber'      => $this->callEvent->isIVR ? strval($this->callEvent->ivrNumber) : '',
                            'status'         => $status,
                            'channel'        => $channel,
                            'ts'             => $result['ts']
                        ];
                        // Save history data
                        $this->integrationRepository->saveIntegrationCallObject(
                            $this->integration->getIntegrationName(),
                            $this->callEvent->callId,
                            $this->callEvent->channelId,
                            $this->callEvent->teamId,
                            $objectData
                        );

                        // Log
                        $this->logger->createCallObjectLog(
                            $result['channel'] . '::' . $result['ts'],
                            [
                                'channel'     => $result['channel'],
                                'ts'          => $result['ts'],
                                'status'      => $status,
                                'agentStatus' => $this->callEvent->agentStatus,
                            ]
                        );
                    } catch (Exception $e) {
                        // Log
                        $this->logger->createCallObjectLog(
                            null,
                            [
                                'error'       => 'Create new Slack message',
                                'message'     => $e->getMessage(),
                                'status'      => $this->getCallStatusForSlack(),
                                'agentStatus' => $this->callEvent->agentStatus
                            ]
                        );

                        throw $e;
                    }
                    //endregion
                }
            }

        }
        return true;
    }


    private function processAfterCallEvent(): bool
    {
        return true;
    }

    private function getContactInfos(): array
    {
        $ringoverUserTeamId = intval($this->callEvent->firstRingoverUser['team_id']);
        $ringoverUserId =  intval($this->callEvent->firstRingoverUser['id']);

        $externalContact =  $this->contactManager->getSynchronizedContacts(
            $ringoverUserTeamId,
            $ringoverUserId,
            $this->callEvent->e164CustomerNumber,
            Slack::MAX_CONTACTS_TO_SEARCH
        );

        // Aucun contact trouvé, renvoie un contact vide
        if (empty($externalContact)){
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
            $this->logger->integrationLog('INFO', 'SLACK: No matching channels found for call event',
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

    function getCallStatusForSlack(): string
    {
        if ($this->callEvent->status === CallStatus::MISSED && !empty($this->callEvent->recordFileLink)) {
            return 'voicemail';
        }

        if (!$this->callEvent->isIVR) {
            return $this->callEvent->status;
        }

        // For IVR_CALL
        $statusForText = '';

        switch ($this->callEvent->agentStatus) {
            case 'ringing':
                $statusForText = CallStatus::INCOMING;
                break;
            case 'success':
                if (
                    CallStatus::INCALL === $this->callEvent->status
                    || CallStatus::DIALED === $this->callEvent->status && 'success' === $this->callEvent->customerStatus
                ) {
                    $statusForText = CallStatus::INCALL;
                }
                break;
            case 'missed':
                $statusForText = CallStatus::MISSED;
                break;
            case 'hangup':
                $statusForText = CallStatus::HANGUP;
                break;
            case null:
            case 'undefined':
                if (CallStatus::HANGUP === $this->callEvent->status) {
                    $statusForText = CallStatus::HANGUP;
                }
                if (CallStatus::MISSED === $this->callEvent->status) {
                    $statusForText = CallStatus::MISSED;
                }
                break;
            default:
                break;
        }

        return $statusForText;
    }

}