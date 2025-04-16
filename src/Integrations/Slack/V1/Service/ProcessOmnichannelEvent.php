<?php

namespace App\Integrations\Slack\V1\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\Integration;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Domain\MessageObjectHistory\MessageObjectHistory;
use App\Domain\OmnichannelEvent\OmnichannelEvent;
use App\Domain\OmnichannelEvent\OmnichannelEventType;
use App\Domain\OmnichannelEvent\OmnichannelName;
use App\Domain\OmnichannelEvent\Service\OmnichannelHelper;
use App\Domain\OmnichannelEvent\Whatsapp\WhatsappConversation;
use App\Integrations\Service\AbstractProcessOmnichannelEvent;
use App\Intrastructure\Persistence\MessageObjectsHistoryRepository;
use DateTime;
use Exception;

class ProcessOmnichannelEvent extends AbstractProcessOmnichannelEvent
{

    private Slack $slack;

    private const MESSAGE_TITLE = [
        'en' => [
            'out' => 'Whatsapp message to',
            'in' => 'Whatsapp message from',
        ],
        'fr' => [
            'out' => 'Message de Whatsapp à',
            'in' => 'Message Whatsapp de'
        ],
        'es' => [
            'out' => 'Mensaje Whatsapp a',
            'in' => 'Mensaje de Whatsapp de'
        ]
    ];

    public function __construct(
        IntegrationLoggerInterface      $logger,
        OmnichannelHelper               $omnichannelHelper,
        MessageObjectsHistoryRepository $messageObjectsHistoryRepository,
        Slack                           $slack
    ) {
        parent::__construct($logger, $omnichannelHelper, $messageObjectsHistoryRepository);
        $this->slack = $slack;
    }

    /**
     * @throws Exception
     */
    public function process(Integration $integration, OmnichannelEvent $omnichannelEvent): bool
    {
        $this->integration = $integration;
        $this->omnichannelEvent = $omnichannelEvent;
        if (!$this->omnichannelHelper->omnichannelFilter($this->integration, $this->omnichannelEvent)) {
            return false;
        }
        $slackOwnerId = $this->slack->getSlackUserId(
            $this->integration,
            $this->omnichannelEvent->ringoverUser->id,
            $this->omnichannelEvent->ringoverUser->email
        );
        if (is_null($slackOwnerId)) {
            return false;
        }
        if ($omnichannelEvent->getChannel() === OmnichannelName::WHATSAPP) {
            return $this->processWhatsappConversation($slackOwnerId);
        }
        return false;
    }

    /**
     * @param string $slackUserChannel
     * @return bool
     * @throws Exception
     */
    private function processWhatsappConversation(string $slackUserChannel): bool
    {

        if ($this->omnichannelEvent->getType() === OmnichannelEventType::DELETE_MESSAGE) {
            return false;
        }
        $this->createOmnichannelLogMessageForSeparateLoggingWhatsapp();
        if (is_null($this->getCurrentMessageObjectHistory())) {
            return $this->logNewWhatsappConversation($slackUserChannel);
        }
        return false;
    }

    /**
     * @return void
     * @throws Exception
     */
    private function createOmnichannelLogMessageForSeparateLoggingWhatsapp(): void
    {
        $language = $this->integration->getConfiguration()['languageCode'] === 'fr' ? 'fr' : 'en';
        $this->omnichannelHelper->generateOmnichannelSeperateMessageForWhatsapp(
            $this->omnichannelEvent,
            $this->integration->getConfiguration()
        );
        $this->omnichannelEvent->messageTitle = self::MESSAGE_TITLE[$language][
            $this->omnichannelEvent->eventData['message']['direction']
        ];

    }


    /**
     * @throws Exception
     */
    private function logNewWhatsappConversation(string $slackUserChannel): bool
    {
        /** @var WhatsappConversation $whatsappConversation */
        $whatsappConversation = $this->omnichannelEvent->omnichannelConversation;
        $contact = $this->slack->getSynchronizedContact(
            $whatsappConversation->getExternalNumber()->getNumber(),
            $this->omnichannelEvent->ringoverUser->teamId,
            $this->omnichannelEvent->ringoverUser->id
        );
        return $this->createNewWhatsappLog(
            $slackUserChannel,
            $contact,
            $whatsappConversation->getExternalNumber()->getNumber()->e164
        );
    }

    /**
     * Create a new object with conversation
     * @param string $slackUserChannel
     * @param IntegrationContactIdentity|null $contact
     * @param string $phoneNumber
     * @return bool
     */
    private function createNewWhatsappLog(
        string $slackUserChannel,
        ?IntegrationContactIdentity $contact,
        string $phoneNumber
    ): bool
    {
        if (is_null($contact)) {
            $blocks = $this->createSlackBlocksForWhatsapp($phoneNumber);
        } else {
            $blocks = $this->createSlackBlocksForWhatsappWithContact($contact, $phoneNumber);
        }
        $message = $this->slack->postSlackMessage(
            $this->integration,
            $slackUserChannel,
            $blocks
        );
        if (!empty($message['error']) || !$message['ok']) {
            return false;
        }
        $objectMessage[] = [
            'id' => $message['message']['bot_id'],
            'channel' => $message['channel']
        ];
        if (!empty($this->integration->getConfiguration()['whastappChannel'])) {
            $message = $this->slack->postSlackMessage(
                $this->integration,
                $this->integration->getConfiguration()['whastappChannel'],
                $blocks
            );
            if (empty($message['error']) || $message['ok']) {
                $objectMessage[] = [
                    'id' => $message['message']['bot_id'],
                    'channel' => $message['channel']
                ];
            }
        }
        $this->messageObjectsHistoryRepository->insert(
            new MessageObjectHistory(
                $this->slack->getIntegrationName(),
                $this->omnichannelEvent->omnichannelConversation->getUuid(),
                $this->omnichannelEvent->eventData['message']['uuid'],
                $this->integration->getTeamId(),
                $objectMessage
            )
        );
        return true;
    }

    private function createSlackBlocksForWhatsappWithContact(
        IntegrationContactIdentity $integrationContactIdentity,
        string $phoneNumber
    ): array
    {
        return [
            'text' => '',
            'attachments' => [
                [
                    "blocks" => [
                        [
                            'type' => 'context',
                            'block_id' => 'header',
                            'elements' => $this->createSlackBlockElementHeader(
                                $integrationContactIdentity,
                                $phoneNumber
                            )
                        ],
                        [
                            'type' => 'context',
                            'block_id' => 'body',
                            'elements' => $this->createSlackBlockElementBody($integrationContactIdentity)
                        ],
                        [
                            'type' => 'context',
                            'block_id' => 'summary',
                            'elements' => $this->createSlackBlockElementSummary()
                        ]
                    ],
                    'color' => '#aaaaaa'
                ]
            ]
        ];
    }

    /**
     * Elements of header block, call
     * @param IntegrationContactIdentity $contact
     * @param string $number
     * @return array
     */
    private function createSlackBlockElementHeader(IntegrationContactIdentity $contact, string $number): array
    {
        if (empty($contact->data['socialProfileUrl'])) {
            $textContact = '*' . $contact->nameWithNumber . '*';
        } else {
            $textContact = '<' . $contact->data['socialProfileUrl'] . '|*' . $contact->name . '*> *(' . $number . ')*';
        }
        return [[
            'type' => 'mrkdwn',
            'text' => $textContact
        ]];
    }

    private function createSlackBlockElementHeaderWithPhoneNumber(string $number): array
    {

        return [[
            'type' => 'mrkdwn',
            'text' => '*' . $number . '*'
        ]];
    }

    private function createSlackBlockElementBody(IntegrationContactIdentity $contact): array
    {
        return [[
            'type' => 'mrkdwn',
            'text' => $this->omnichannelEvent->messageTitle . ' ' . $contact->name
        ]];
    }

    private function createSlackBlockElementBodyWithPhoneNumber(string $phoneNumber): array
    {
        return [[
            'type' => 'mrkdwn',
            'text' => $this->omnichannelEvent->messageTitle . ' ' . $phoneNumber
        ]];
    }

    private function createSlackBlockElementSummary(): array
    {
        return [[
            'type' => 'mrkdwn',
            'text' =>  $this->omnichannelEvent->logMessage
        ]];
    }

    private function createSlackBlocksForWhatsapp(string $phoneNumber) :array
    {
        return [
            // Text must be empty (Slack API recommendation)
            'text' => '',
            'attachments' => [
                [
                    "blocks" => [
                        [
                            'type' => 'context',
                            'block_id' => 'header',
                            'elements' => $this->createSlackBlockElementHeaderWithPhoneNumber($phoneNumber)
                        ],
                        [
                            'type' => 'context',
                            'block_id' => 'body',
                            'elements' => $this->createSlackBlockElementBodyWithPhoneNumber($phoneNumber)
                        ],
                        [
                            'type' => 'context',
                            'block_id' => 'summary',
                            'elements' => $this->createSlackBlockElementSummary()
                        ]
                    ],
                    'color' => '#aaaaaa'
                ]
            ]
        ];
    }
}
