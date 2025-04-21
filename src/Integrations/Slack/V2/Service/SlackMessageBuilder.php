<?php

namespace App\Integrations\Slack\V2\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\CallEvent\Call;
use App\Domain\CallEvent\CallDirection;
use App\Domain\CallEvent\CallStatus;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Domain\SMSEvent\SMS;
use App\Domain\SMSEvent\SMSDirection;

class SlackMessageBuilder
{
    const RO_API_BASE_URL = 'https://api.ringover.xyz';

    /**
     * Create Slack message blocks for a call event
     *
     * @param Call $callEvent The call event data
     * @param array $callEventText The text content from i18n
     * @param array $contacts Contact information
     * @param array $config Integration configuration
     * @return array The formatted Slack message
     */
    public static function createSlackBlocksForCall(
        Call $callEntity,
        array $slackServiceData,
        array $translatedEventText,
        array $contacts,
        IntegrationLoggerInterface $logger
    ): array {
        $blocks = [];
        $originalBody = $translatedEventText['body'];

        // Text body should be json array, convert it to array
        // line breaks (in note for ex.) may cause json_encode error
        $translatedEventText['body'] = json_decode(preg_replace("/(\r\n)+|\r|\n/", ", ", $translatedEventText['body']),true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $logger->integrationLog('STOP_PROCESSING', 'Text body is not valid', ['text_body' => $originalBody]);
            return $blocks;
        }

        // Header
        $blocks[] = [
            'type'     => 'context',
            'block_id' => 'header',
            'elements' => self::createSlackBlockElementHeader($callEntity, $slackServiceData, $contacts, "SlackLight")
        ];

        // Tags
        $tagsElements = self::createSlackBlockElementTags($callEntity, $translatedEventText);
        if (!empty($tagsElements)) {
            $blocks[] = [
                'type'     => 'context',
                'block_id' => 'tags',
                'elements' => [$tagsElements]
            ];
        }

        // Notes
        $notesElements = self::createSlackBlockElementNotes($callEntity, $translatedEventText);
        if (!empty($notesElements)) {
            $blocks[] = [
                'type'     => 'context',
                'block_id' => 'notes',
                'elements' => [$notesElements]
            ];
        }

        // Empower (Transcription)
        $empowerTransElements = self::createSlackBlockElementEmpowerTranscription($callEntity, $translatedEventText);
        if (!empty($empowerTransElements)) {
            $blocks[] = [
                'type'     => 'context',
                'block_id' => 'transcript',
                'elements' => [$empowerTransElements]
            ];
        }

        // Empower (Summary)
        $empowerSummaryElements = self::createSlackBlockElementEmpowerSummary($callEntity, $translatedEventText);
        if (!empty($empowerSummaryElements)) {
            $blocks[] = [
                'type'     => 'context',
                'block_id' => 'summary',
                'elements' => [$empowerSummaryElements]
            ];
        }

        // Empower (Summary)
        $empowerNextStepsElements = self::createSlackBlockElementEmpowerNextSteps($callEntity, $translatedEventText);
        if (!empty($empowerNextStepsElements)) {
            $blocks[] = [
                'type'     => 'context',
                'block_id' => 'next_steps',
                'elements' => [$empowerNextStepsElements]
            ];
        }

        $bodyFields = [];
        // Body (IVR)
        $ivrInfos = self::createSlackBlockElementIvrInfosForCall($callEntity, $translatedEventText);
        if (!empty($ivrInfos)) {
            $bodyFields[] = [
                'type' => 'mrkdwn',
                'text' => $ivrInfos
            ];
        }
        /**
         * Body (Customer card)
         * Always display
         */
        $customCardInfos = self::createSlackBlockElementCustomerCard($slackServiceData, $translatedEventText, $contacts);
        if (!empty($customCardInfos)) {
            $bodyFields[] = [
                'type' => 'mrkdwn',
                'text' => $customCardInfos
            ];
        }

        // Add all bodyFields to body block
        if (!empty($bodyFields)) {
            $blocks[] = [
                'type'     => 'section',
                'block_id' => 'body',
                'fields'   => $bodyFields
            ];
        }

        // Actions
        $actionBtns = self::createSlackBlockElementActions($callEntity, $translatedEventText);
        if (!empty($actionBtns)) {
            $blocks[] = [
                'type'     => 'actions',
                'block_id' => 'action',
                'elements' => $actionBtns
            ];
        }
        // Footer
        $footer = self::createSlackBlockElementFooter($callEntity, $translatedEventText);
        if (!empty($footer)) {
            $blocks[] = [
                'type'     => 'context',
                'block_id' => 'footer',
                'elements' => $footer
            ];
        }

        return [
            // Texte vide obligatoire
            'text'        => '',
            'attachments' => [
                [
                    "blocks" => $blocks,
                    'color'  => $translatedEventText['body']['color'] ?? '#aaaaaa'
                ]
            ]
        ];
    }


    /**
     * Get call status for IVR and normal calls
     *
     * @param Call $callEntity
     *
     * @return string
     */
    public static function getCallStatusForSlack(Call $callEntity): string
    {
        if ($callEntity->status === CallStatus::MISSED && !empty($callEntity->recordFileLink)) {
            return 'voicemail';
        }

        if (!$callEntity->isIVR) {
            return $callEntity->status;
        }

        // For IVR_CALL
        $statusForText = '';

        switch ($callEntity->agentStatus) {
            case 'ringing':
                $statusForText = CallStatus::INCOMING;
                break;
            case 'success':
                if (
                    CallStatus::INCALL === $callEntity->status
                    || CallStatus::DIALED === $callEntity->status && 'success' === $callEntity->customerStatus
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
                if (CallStatus::HANGUP === $callEntity->status) {
                    $statusForText = CallStatus::HANGUP;
                }
                if (CallStatus::MISSED === $callEntity->status) {
                    $statusForText = CallStatus::MISSED;
                }
                break;
            default:
                break;
        }

        return $statusForText;
    }

    /**
     * Elements of header block, call
     * @param Call|SMS $telecomEntity
     * @param array $slackServiceData
     * @param IntegrationContactIdentity[] $contacts
     * @return array
     */
    protected static function createSlackBlockElementHeader($telecomEntity, array $slackServiceData, array $contacts, string $integrationFolderName = "Slack"): array
    {
        $elements = [];

        if ($telecomEntity instanceof CALL) {
            $e164CustomerNumber = $telecomEntity->e164CustomerNumber;

            // For call, insert icon to the begining of header
            $iconUrl = self::getHeaderIconForCallSlack($telecomEntity);
            if (!empty($iconUrl)) {
                $iconUrlPaths = explode('/', $iconUrl);

                $elements[] = [
                    'type'      => 'image',
                    'image_url' => $iconUrl,
                    'alt_text'  => substr(end($iconUrlPaths), 0, -4)
                ];
            }
        } else {
            $e164CustomerNumber = $telecomEntity->direction === SMSDirection::IN
                ? $telecomEntity->from['number']['format']['e164']
                : $telecomEntity->to['number']['format']['e164'];
        }

        $showNumberInNationalFormat = $slackServiceData['showNationalFormat'] ?? false;
        $nationalCustomerNumber = isset($telecomEntity->customerNumberDetails['national'])
            ? str_replace(' ', '', $telecomEntity->customerNumberDetails['national'])
            : $e164CustomerNumber;

        $numberToShow = $showNumberInNationalFormat ? $nationalCustomerNumber : $e164CustomerNumber;

        // Un contact trouvé
        if (1 === count($contacts)) {
            $contact = current($contacts);

            // Nom et numéro, sans lien
            if (empty($contact->data['socialProfileUrl'])) {
                $textContact = '*' . $contact->name . ' (' . $numberToShow . ')*';
            } // Nom et numéro, avec lien
            else {
                $textContact = '<' . $contact->data['socialProfileUrl'] . '|*' . $numberToShow . ')*';
            }
        }else{
            $textContact = '*(' . $numberToShow . ')*';
        }

        // Regénérer les textes avec de la bonne info
        if ($telecomEntity instanceof CALL) {
            $callEventText = createCallEventTextV2(
                $telecomEntity,
                $slackServiceData,
                ['/:contactHeader/' => $textContact],
                false,
                $integrationFolderName
            );
        } else {
            $callEventText = createSMSEventText(
                $telecomEntity,
                $slackServiceData,
                ['/:contactHeader/' => $textContact],
                $integrationFolderName
            );
        }

        // Insert the following text
        $elements[] = [
            'type' => 'mrkdwn',
            'text' => $callEventText['title']
        ];

        return $elements;
    }

    /**
     * Elements of callEvent Tags
     * @param Call $callEntity
     * @param array $translatedEventText
     * @return array<string>
     */
    protected static function createSlackBlockElementTags(Call $callEntity, array $translatedEventText): array
    {
        if (
            empty($callEntity->tags)
            || !isset($translatedEventText['body']['tags']['value'])
            || empty($translatedEventText['body']['tags']['value'])
        ) {
            return [];
        }

        return [
            "type" => "plain_text",
            "text" => $translatedEventText['body']['tags']['title']
                . ' ' . $translatedEventText['body']['tags']['value']
        ];
    }

    /**
     * Elements of callEvent Notes
     * @param Call $callEntity
     * @param array $translatedEventText
     * @return array<string>
     */
    protected static function createSlackBlockElementNotes(Call $callEntity, array $translatedEventText): array
    {
        if (
            empty($callEntity->comments)
            || !isset($translatedEventText['body']['notes']['value'])
            || empty($translatedEventText['body']['notes']['value'])
        ) {
            return [];
        }

        return [
            "type" => "plain_text",
            "text" => $translatedEventText['body']['notes']['title']
                . ' ' . $translatedEventText['body']['notes']['value']
        ];
    }

    /**
     * Empower transcription
     * @param Call $callEntity
     * @param array $translatedEventText
     * @return array<string>
     */
    protected static function createSlackBlockElementEmpowerTranscription(Call $callEntity, array $translatedEventText): array
    {
        if (
            !isset($translatedEventText['body']['empower']['transcriptLink'])
            || empty($translatedEventText['body']['empower']['transcriptLink'])
        ) {
            return [];
        }

        /**@var string $customerCardTemplate <link|text> */
        $transcriptTemplate = '<%s|%s>';
        $text = sprintf(
            $transcriptTemplate,
            $translatedEventText['body']['empower']['transcriptLink'],
            $translatedEventText['body']['empower']['transcriptTtitle']
        );
        // Template presents in link text
        if (0 === strpos($translatedEventText['body']['empower']['transcriptLink'], '<')) {
            $text = $translatedEventText['body']['empower']['transcriptLink'];
        }
        return [
            "type" => "mrkdwn",
            "text" => $text
        ];
    }

    /**
     * Empower summary
     * @param Call $callEntity
     * @param array $translatedEventText
     * @return array<string>
     */
    protected static function createSlackBlockElementEmpowerSummary(Call $callEntity, array $translatedEventText): array
    {
        if (
            !isset($translatedEventText['body']['empower']['summaryText'])
            || empty($translatedEventText['body']['empower']['summaryText'])
        ) {
            return [];
        }

        return [
            "type" => "plain_text",
            "text" => $translatedEventText['body']['empower']['summaryTitle']
                . ' ' . $translatedEventText['body']['empower']['summaryText']
        ];
    }

    /**
     * Empower next steps
     * @param Call $callEntity
     * @param array $translatedEventText
     * @return array<string>
     */
    protected static function createSlackBlockElementEmpowerNextSteps(Call $callEntity, array $translatedEventText): array
    {
        if (
            !isset($translatedEventText['body']['empower']['nextStepsText'])
            || empty($translatedEventText['body']['empower']['nextStepsText'])
        ) {
            return [];
        }

        return [
            "type" => "plain_text",
            "text" => $translatedEventText['body']['empower']['nextStepsTitle']
                . ' ' . $translatedEventText['body']['empower']['nextStepsText']
        ];
    }

    /**
     * Elements of callEvent Notes
     * @param Call $callEntity
     * @param array $translatedEventText
     * @return string
     */
    protected static function createSlackBlockElementIvrInfosForCall(Call $callEntity, array $translatedEventText): string
    {
        if (
            !$callEntity->isIVR
            || !isset($translatedEventText['body']['ivrInfos'])
            || empty($translatedEventText['body']['ivrInfos'])
        ) {
            return '';
        }

        return $translatedEventText['body']['ivrInfos'];
    }


    /**
     * section fields of contact block
     * @param array $slackServiceData
     * @param array $translatedEventText
     * @param IntegrationContactIdentity[] $contacts
     * @return string Always filled
     */
    protected static function createSlackBlockElementCustomerCard(
        array $slackServiceData,
        array $translatedEventText,
        array $contacts
    ): string {
        $tempText = '';

        // Un ou plusieurs contact(s) trouvé(s)
        /**@var string $customerCardTemplate <link|text> */
        $customerCardTemplate = '<%s|%s>';
        $textSocialServices = '';
        $textIfMultiple = 1 === count($contacts) ? '' : $translatedEventText['multipleContactsFound'];
        $textCustomCards = '';
        $suffix = Slack::MAX_CONTACTS_TO_SEARCH < count($contacts) ? ' ...' : '';
        for ($i = 0; $i < count($contacts); $i++) {
            if (Slack::MAX_CONTACTS_TO_SEARCH < count($contacts)) {
                break;
            }
            // If the socialService name presents and is not insert yet
            if (
                !empty($contacts[$i]->data['socialService'])
                && false === strpos($textSocialServices, $contacts[$i]->data['socialService'])
            ) {
                $textSocialServices .= '*' . $contacts[$i]->data['socialService'] . '*, ';
            }
            $textCustomCards .= empty($contacts[$i]->data['socialProfileUrl'])
                ? $contacts[$i]->name . "\n"
                : sprintf($customerCardTemplate, $contacts[$i]->data['socialProfileUrl'], $contacts[$i]->name) . "\n";
        }

        // Noms des social services
        if (!empty($textSocialServices)) {
            $tempText .= rtrim($textSocialServices, ', ') . $suffix . "\n";
        }

        // Phrase si multiple contacts trouvés
        if (!empty($textIfMultiple)) {
            $tempText .= $textIfMultiple . "\n";
        }

        // Customer cards
        $tempText .= rtrim($textCustomCards, "\n") . $suffix;

        return $tempText;
    }

    /**
     * Elements des boutons d'action
     * @param Call $callEntity
     * @param array $translatedEventText
     * @return array<array>
     */
    protected static function createSlackBlockElementActions(Call $callEntity, array $translatedEventText): array
    {
        $isAnonymous = false !== strpos($callEntity->e164CustomerNumber, 'anonymous');
        $status = self::getCallStatusForSlack($callEntity);
        $btnsProps = [];
        switch ($callEntity->direction) {
            case CallDirection::IN:
                switch ($status) {
                    case 'ringing':
                        break;
                    case 'missed':
                    case 'hangup':
                    case 'voicemail':
                        if (
                            !$isAnonymous
                            && isset($translatedEventText['body']['btnCall'])
                            && !empty($translatedEventText['body']['btnCall'])
                        ) {
                            $btnsProps[] = [
                                'text'      => $translatedEventText['body']['btnCall'],
                                'value'     => 'btn_call',
                                'url'       => 'https://app.ringover.com/call/' . $callEntity->e164CustomerNumber,
                                'action_id' => 'act_call',
                                'style'     => 'primary'
                            ];
                        }
                        // btn listen record. For voicemail
                        if (
                            !empty($translatedEventText['body']['audioFileLink'])
                            && isset($translatedEventText['body']['btnListen'])
                            && !empty($translatedEventText['body']['btnListen'])
                        ) {
                            $btnsProps[] = [
                                'text'      => $translatedEventText['body']['btnListen'],
                                'value'     => 'btn_record',
                                'url'       => $translatedEventText['body']['audioFileLink'],
                                'action_id' => 'act_listen'
                            ];
                        }
                        break;
                    default:
                        break;
                }
                break;
            case CallDirection::OUT:
                switch ($status) {
                    case 'hangup':
                    case 'voicemail':
                        if (
                            !empty($translatedEventText['body']['audioFileLink'])
                            && isset($translatedEventText['body']['btnListen'])
                            && !empty($translatedEventText['body']['btnListen'])
                        ) {
                            $btnsProps[] = [
                                'text'      => $translatedEventText['body']['btnListen'],
                                'value'     => 'btn_record',
                                'url'       => $translatedEventText['body']['audioFileLink'],
                                'action_id' => 'act_listen'
                            ];
                        }
                        break;
                    default:
                        break;
                }
                break;
            default:
                break;
        }

        // Return if no btn created.
        if (empty($btnsProps)) {
            return [];
        }
        $elements = [];

        foreach ($btnsProps as $btnProp) {
            $btnElem = [
                'type'      => 'button',
                'action_id' => $btnProp['action_id'],
                'text'      => [
                    'type' => 'plain_text',
                    'text' => $btnProp['text']
                ],
                'value'     => $btnProp['value'],
                'url'       => $btnProp['url']
            ];

            // 2 types de style acceptés : primary, danger.
            if (isset($btnProp['style'])) {
                $btnElem['style'] = $btnProp['style'];
            }

            $elements[] = $btnElem;
        }

        return $elements;
    }

    /**
     * Elements du footer
     * @param Call $callEntity
     * @param array $translatedEventText
     * @return array
     */
    protected static  function createSlackBlockElementFooter(Call $callEntity, array $translatedEventText): array
    {
        if (
            CallDirection::OUT === $callEntity->direction
            || CallStatus::HANGUP !== self::getCallStatusForSlack($callEntity)
            || !isset($translatedEventText['body']['answeredByValue'])
        ) {
            return [];
        }

        return [
            [
                'type' => 'mrkdwn',
                'text' => $translatedEventText['body']['answeredByTitle'] ?? 'Answered by'
            ],
            [
                'type' => 'mrkdwn',
                'text' => '*' . $translatedEventText['body']['answeredByValue'] . '*'
            ]
        ];
    }


    /**
     * Générer l'url d'icône selon le statut d'appel
     * @param Call $callEntity
     * @return string|null
     */
    protected static function getHeaderIconForCallSlack(Call $callEntity): ?string
    {
        $icoName = '';
        $newStatus = self::getCallStatusForSlack($callEntity);
        switch ($callEntity->direction) {
            case CallDirection::IN:
                switch ($newStatus) {
                    case CallStatus::INCOMING:
                    case CallStatus::INCALL:
                    case CallStatus::HANGUP:
                        $icoName = 'call-in.png';
                        break;
                    case CallStatus::MISSED:
                        $icoName = 'call-missed.png';
                        break;
                    case 'voicemail':
                        $icoName = 'call-message.png';
                        break;
                    default:
                        break;
                }
                break;
            case CallDirection::OUT:
                $icoName = 'call-out.png';
                break;
            default:
                break;
        }

        if (!empty($icoName)) {
            $baseUrl = self::RO_API_BASE_URL . '/web/img/icons/';
            return $baseUrl . $icoName;
        }
        return null;
    }



}