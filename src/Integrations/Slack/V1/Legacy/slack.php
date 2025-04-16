<?php

use App\Domain\CallEvent\Call;
use App\Domain\CallEvent\CallDirection;
use App\Domain\CallEvent\CallStatus;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Domain\Integration\UserTokenInfos;
use App\Domain\SMSEvent\SMS;
use App\Domain\SMSEvent\SMSDirection;
use App\Integrations\Slack\V1\Service\ContactManager;
use App\Intrastructure\Persistence\CommandQueryPDO;
use DI\Container;

/**
 * Available variables
 * @var Container $containerDI
 * @var CommandQueryPDO $pdoHandler
 * @var Call $callEntity
 * @var SMS $smsEntity
 * @var array $currentRingoverUser User infos array defined in sms_v2
 */

/** @var string RO_API_BASE_URL Base url for icones, to change when prod */
const RO_API_BASE_URL = 'https://api.ringover.xyz';

/** @var int MAX_CONTACTS_TO_SEARCH
 * Nombre de contact à chercher et à utiliser pour construire les liens "Fiche Client"
 */
const MAX_CONTACTS_TO_SEARCH = 3;

/** @var array EXTRA_EVENT_TEXTS_LEGACY Complément pour les textes par défaut */
const EXTRA_EVENT_TEXTS_LEGACY = [
    'noContactFound'        => [
        'fr' => 'Contact inconnu',
        'en' => 'Unknown contact'
    ],
    'multipleContactsFound' => [
        'fr' => 'Plusieurs contacts trouvés',
        'en' => 'Multiple contacts found'
    ]
];

$contactManager = $containerDI->get(ContactManager::class);

/**
 * @param CommandQueryPDO $pdoHandler
 * @param Call $callEntity
 *
 * @return bool
 */
function processCallEventForSlack(CommandQueryPDO $pdoHandler, Call $callEntity, ContactManager $contactManager): bool
{
    /** @var UserTokenInfos $slackTokenInfo */
    $slackTokenInfo = current($callEntity->integrations['SLACK']);
    $slackServiceData = json_decode(json_encode($slackTokenInfo->serviceData), true);

    if (isset($slackServiceData['enabled']) && !$slackServiceData['enabled']) {
        return false;
    }

    if (
        !callFilterV2($callEntity, $slackServiceData, [
            CallStatus::DIALED,
            CallStatus::INCALL,
            CallStatus::MISSED,
            CallStatus::HANGUP
        ])
    ) {
        return false;
    }

    if (
        CallStatus::MISSED === $callEntity->status
        && $callEntity->eventName === 'PERMANENT_TRANSFER_GROUP_CALL_IN'
    ) {
        // Log
        integrationLog('PERMANENT_TRANSFER_GROUP_CALL_IN');
        return false;
    }

    if (
        CallStatus::MISSED === $callEntity->status
        && $callEntity->eventName === 'NOANSWER_TRANSFER_GROUP_CALL_IN'
    ) {
        // Log
        integrationLog('NOANSWER_TRANSFER_GROUP_CALL_IN');
        return false;
    }

    // Récupérer une liste de users Ringover
    $ringoverUserList =  $callEntity->ringoverUsers;

    #region Texte
    // Legacy format de texte
    if (isset($slackServiceData['callEventTexts']['en']['inbound']['hangup']['header'])) {
        try {
            // Construct message block for call
            $formattedMsg = legacyCreateSlackBlocksForCall(
                $slackTokenInfo,
                $pdoHandler,
                $callEntity,
                $slackServiceData
            );
        } catch (Exception $e) {
            // Log
            createCallObjectLog(
                null,
                [
                    'error'   => 'Generate message text for Slack',
                    'message' => $e->getMessage()
                ]
            );
        }
        if (empty($formattedMsg)) {
            integrationLog('Empty callEvent text', '', ['status' => $callEntity->status]);
            return false;
        }
    } // Format général de texte
    else {
        $initialCallEventText = createCallEventTextV2($callEntity, $slackServiceData, [], false, 'Slack');
        // Textes du callEvent sont vides
        if (empty($initialCallEventText['title']) && empty($initialCallEventText['body'])) {
            // Log
            integrationLog(
                'STOP_PROCESSING',
                'CallEvent text is empty',
                ['status' => $callEntity->status]
            );

            return false;
        }

        // Du code suivant sera dans la boucle, à fin d'utiliser user_id
    }
    #endregion

    // Tableau de mappage des users et channels [user_id => channel]
    $ringoverUsersChannels = [];

    // Envoyer la notif dans le channel prédéfini
    if (isset($slackServiceData['callChannel']) && !empty($slackServiceData['callChannel'])) {
        // [first_user => configured_channel]
        $ringoverUsersChannels[$callEntity->firstRingoverUser['id']] = $slackServiceData['callChannel'];
    } // Envoyer la notif pour chaque user configuré
    else {
        // Pour chaque user dans la liste, créer un message si c'est éligible
        foreach ($ringoverUserList as $ringoverUser) {
            /** @var null|string slack userId, equal to channel for DM */
            $channel = getSlackUserIdFromUserMappingAndEmail(
                $slackTokenInfo,
                $slackServiceData,
                $ringoverUser['id'],
                $ringoverUser['email']
            );
            if (is_null($channel)) {
                // Log
                userSearchLog('', null, [
                    'ringoverUserId' => $ringoverUser['id'],
                    'ringoverUserEmail' => $ringoverUser['email'],
                    'source'         => 'user map'
                ]);
                continue;
            }

            $ringoverUsersChannels[$ringoverUser['id']] = $channel;
        }
    }

    foreach ($ringoverUsersChannels as $userId => $channel) {
        // Générer le message avec le nouvel format de texte
        if (!isset($formattedMsg)) {
            $contactInfos = getContactsForSlack(
                $contactManager,
                intval($callEntity->firstRingoverUser['team_id']),
                intval($userId),
                $callEntity->e164CustomerNumber,
                'SLACK'
            );

            try {
                $formattedMsg = createSlackBlocksForCall(
                    $callEntity,
                    $slackServiceData,
                    $initialCallEventText,
                    $contactInfos
                );
            } catch (Exception $e) {
                // Log
                integrationLog(
                    'STOP_PROCESSING',
                    'Failed to create message body',
                    ['error' => $e->getMessage()]
                );
            }
        }

        try {
            // Process message management.
            createOrUpdateCallObjectForSlack(
                $pdoHandler,
                $callEntity,
                $slackTokenInfo->accessToken,
                $channel,
                $formattedMsg,
                'SLACK'
            );
        } catch (Exception $e) {
            // Process message failed
            // Log
            integrationLog('ERROR_CREATE_OR_UPDATE', '', ['msg' => $formattedMsg]);
            continue;
        }
    }

    return true;
}

function processCallEventForSlackQuickTalk(CommandQueryPDO $pdoHandler, Call $callEntity, ContactManager $contactManager): bool
{
    /** @var UserTokenInfos $slackTokenInfo */
    $slackTokenInfo = current($callEntity->integrations['SLACK_QUICKTALK']);
    $slackServiceData = json_decode(json_encode($slackTokenInfo->serviceData), true);

    if (isset($slackServiceData['enabled']) && !$slackServiceData['enabled']) {
        return false;
    }

    if (
        !callFilterV2($callEntity, $slackServiceData, [
            CallStatus::DIALED,
            CallStatus::INCALL,
            CallStatus::MISSED,
            CallStatus::HANGUP
        ])
    ) {
        return false;
    }

    if (
        CallStatus::MISSED === $callEntity->status
        && $callEntity->eventName === 'PERMANENT_TRANSFER_GROUP_CALL_IN'
    ) {
        // Log
        integrationLog('PERMANENT_TRANSFER_GROUP_CALL_IN');
        return false;
    }

    if (
        CallStatus::MISSED === $callEntity->status
        && $callEntity->eventName === 'NOANSWER_TRANSFER_GROUP_CALL_IN'
    ) {
        // Log
        integrationLog('NOANSWER_TRANSFER_GROUP_CALL_IN');
        return false;
    }


    #region Texte
    // Format général de texte (this function create the call event text: [title, body, transcript, scenarion...)
    //change the folder name to use different texts
    $initialCallEventText = createCallEventTextV2($callEntity, $slackServiceData, [], false, 'SlackQuicktalk');
    //$initialCallEventText['body']= json_encode($initialCallEventText['body']);
    // Textes du callEvent sont vides
    if (empty($initialCallEventText['title']) && empty($initialCallEventText['body'])) {
        // Log
        integrationLog(
            'STOP_PROCESSING',
            'SLACK_QUICKTALK CallEvent text is empty',
            ['status' => $callEntity->status]
        );

        return false;
    }

    // Envoyer la notif dans le channel prédéfini
    if (isset($slackServiceData['channels']) && !empty($slackServiceData['channels'])) {
        // [first_user => configured_channel]
        $userId = $callEntity->firstRingoverUser['id'];
        $channel = '';
        foreach($slackServiceData['channels'] as $slack_number_channel){
            if($slack_number_channel['phone_number'] == $callEntity->ringoverNumber){
                $channel = $slack_number_channel['channel_id'];
                break;
            }
        }

        // Générer le message avec le nouvel format de texte
        if (!isset($formattedMsg)) {
            $contactInfos = getContactsForSlack(
                $contactManager,
                intval($callEntity->firstRingoverUser['team_id']),
                intval($userId),
                $callEntity->e164CustomerNumber,
                'SLACK_QUICKTALK'
            );

            try {
                $formattedMsg = createSlackBlocksForCall(
                    $callEntity,
                    $slackServiceData,
                    $initialCallEventText,
                    $contactInfos
                );
            } catch (Exception $e) {
                // Log
                integrationLog(
                    'STOP_PROCESSING',
                    'Failed to create message body',
                    ['error' => $e->getMessage()]
                );
            }
        }

        try {
            // Process message management.
            createOrUpdateCallObjectForSlack(
                $pdoHandler,
                $callEntity,
                $slackTokenInfo->accessToken,
                $channel,
                $formattedMsg,
                'SLACK_QUICKTALK'
            );
        } catch (Exception $e) {
            // Process message failed
            // Log
            integrationLog('ERROR_CREATE_OR_UPDATE', '', ['msg' => $formattedMsg]);
        }
    }
    #endregion
    return true;
}

function processAfterCallEventForSlack(
    Call $callEntity,
    ContactManager $contactManager
): bool {
    /** @var UserTokenInfos $slackTokenInfo */
    $slackTokenInfo = current($callEntity->integrations['SLACK']);
    $slackServiceData = $slackTokenInfo->serviceDataArr;

    // No callObjects found
    if (empty($callEntity->integrations['SLACK']['call_objects'])) {
        // Log
        integrationLog(
            'NO_CALL_OBJECT',
            'No call object for aftercall process',
            [
                'callId' => $callEntity->callId
            ]
        );
        return true;
    }

    $ringoverNumber = strval($callEntity->ringoverNumber);
    $customerNumber = strval($callEntity->customerNumber);
    $ivrNumber = $callEntity->isIVR ? strval($callEntity->ivrNumber) : '';

    // Loop saved callObjects
    foreach ($callEntity->integrations['SLACK']['call_objects'] as $callObject) {
        /** @var bool $isNbrsEqual caller and callee numbers match thoses in history */
        if ($callEntity->isIVR) {
            $isNbrsEqual = (empty($callObject['objectData']['ivrNumber'])
                    || $ivrNumber === $callObject['objectData']['ivrNumber'])
                && $customerNumber === $callObject['objectData']['customerNumber'];
        } else {
            $isNbrsEqual = $ringoverNumber === $callObject['objectData']['ringoverNumber']
                && $customerNumber === $callObject['objectData']['customerNumber'];
        }

        $channel = $callObject['objectData']['channel'] ?? '';
        $ts = $callObject['objectData']['ts'] ?? '';

        // If not the same number, or status is not right, or no ts. Continue
        $newStatus = getCallStatusForSlack($callEntity);
        if (
            !$isNbrsEqual
            || !in_array($newStatus, [CallStatus::HANGUP, CallStatus::MISSED, 'voicemail'])
            || empty($ts)
        ) {
            // Log
            updateCallObjectLog(
                $channel . '::' . $ts,
                false,
                [
                    'isNbrEqual' => $isNbrsEqual,
                    'newStatus'  => $newStatus,
                    'event'      => 'aftercall'
                ]
            );
            continue;
        }

        //region prepare message updating
        // Legacy format de texte
        if (isset($slackServiceData['callEventTexts']['en']['inbound']['hangup']['header'])) {
            $hasTagsNotes = !empty($callEntity->tags) || !empty($callEntity->comments);
            $showTagsNotes = isset($slackServiceData['showTagsNotes']) && $slackServiceData['showTagsNotes'];

            if (!$hasTagsNotes) {
                // Log
                integrationLog(
                    'NO_TAGS_NOTES',
                    'Aftercall does not have tags nor notes',
                    [
                        'callId' => $callEntity->callId
                    ]
                );
                return false;
            }

            if (!$showTagsNotes) {
                // ShowTagsNotes is disabled
                // Log
                integrationLog('STOP_PROCESSING', 'Do not show tags and notes');
                return false;
            }
            $msgHistoryAttachment = legacyCreateSlackBlocksForAfterCall($callEntity, $slackTokenInfo, $slackServiceData,
                $newStatus, $channel, $ts);
            if (empty($msgHistoryAttachment)) {
                // Log
                integrationLog('STOP_PROCESSING', 'Empty text');
                return false;
            }

            $formattedMsg =
                [
                    // Texte vide obligatoire
                    'text'        => '',
                    'attachments' => [$msgHistoryAttachment]

                ];
        } // Format général de texte
        else {
            $initialCallEventText = createCallEventTextV2($callEntity, $slackServiceData, [], false, 'Slack');
            // Textes du callEvent sont vides
            if (empty($initialCallEventText['title']) && empty($initialCallEventText['body'])) {
                // Log
                integrationLog(
                    'STOP_PROCESSING',
                    'CallEvent text is empty',
                    ['status' => $callEntity->status]
                );

                return false;
            }

            $contactInfos = getContactsForSlack(
                $contactManager,
                intval($callEntity->firstRingoverUser['team_id']),
                intval($callEntity->firstRingoverUser['id']),
                $callEntity->e164CustomerNumber,
                'SLACK'
            );

            $formattedMsg = createSlackBlocksForCall($callEntity, $slackServiceData, $initialCallEventText,
                $contactInfos);
        }
        //endregion

        // Update message
        try {
            updateSlackMessage($slackTokenInfo->accessToken, $channel, $ts, $formattedMsg);
            // log
            updateCallObjectLog($channel . '::' . $ts, true, ['callId' => $callEntity->callId]);
        } catch (Exception $e) {
            // log
            updateCallObjectLog(
                $channel . '::' . $ts,
                false,
                [
                    'callId' => $callEntity->callId,
                    'error'  => $e->getMessage(),
                    'msg'    => $formattedMsg
                ]
            );
            return false;
        }
    }

    return true;
}


function processAfterCallEventForSlackQuicktalk(
    Call $callEntity,
    ContactManager $contactManager
): bool {
    /** @var UserTokenInfos $slackTokenInfo */
    $slackTokenInfo = current($callEntity->integrations['SLACK_QUICKTALK']);
    $slackServiceData = $slackTokenInfo->serviceDataArr;

    // No callObjects found
    if (empty($callEntity->integrations['SLACK_QUICKTALK']['call_objects'])) {
        // Log
        integrationLog(
            'NO_CALL_OBJECT',
            'No call object for aftercall process',
            [
                'callId' => $callEntity->callId
            ]
        );
        return true;
    }

    $ringoverNumber = strval($callEntity->ringoverNumber);
    $customerNumber = strval($callEntity->customerNumber);
    $ivrNumber = $callEntity->isIVR ? strval($callEntity->ivrNumber) : '';

    // Loop saved callObjects
    foreach ($callEntity->integrations['SLACK_QUICKTALK']['call_objects'] as $callObject) {
        /** @var bool $isNbrsEqual caller and callee numbers match thoses in history */
        if ($callEntity->isIVR) {
            $isNbrsEqual = (empty($callObject['objectData']['ivrNumber'])
                    || $ivrNumber === $callObject['objectData']['ivrNumber'])
                && $customerNumber === $callObject['objectData']['customerNumber'];
        } else {
            $isNbrsEqual = $ringoverNumber === $callObject['objectData']['ringoverNumber']
                && $customerNumber === $callObject['objectData']['customerNumber'];
        }

        $channel = $callObject['objectData']['channel'] ?? '';
        $ts = $callObject['objectData']['ts'] ?? '';

        // If not the same number, or status is not right, or no ts. Continue
        $newStatus = getCallStatusForSlack($callEntity);
        if (
            !$isNbrsEqual
            || !in_array($newStatus, [CallStatus::HANGUP, CallStatus::MISSED, 'voicemail'])
            || empty($ts)
        ) {
            // Log
            updateCallObjectLog(
                $channel . '::' . $ts,
                false,
                [
                    'isNbrEqual' => $isNbrsEqual,
                    'newStatus'  => $newStatus,
                    'event'      => 'aftercall'
                ]
            );
            continue;
        }

        //region prepare message updating
        // Legacy format de texte
        if (isset($slackServiceData['callEventTexts']['en']['inbound']['hangup']['header'])) {
            $hasTagsNotes = !empty($callEntity->tags) || !empty($callEntity->comments);
            $showTagsNotes = isset($slackServiceData['showTagsNotes']) && $slackServiceData['showTagsNotes'];

            if (!$hasTagsNotes) {
                // Log
                integrationLog(
                    'NO_TAGS_NOTES',
                    'Aftercall does not have tags nor notes',
                    [
                        'callId' => $callEntity->callId
                    ]
                );
                return false;
            }

            if (!$showTagsNotes) {
                // ShowTagsNotes is disabled
                // Log
                integrationLog('STOP_PROCESSING', 'Do not show tags and notes');
                return false;
            }
            $msgHistoryAttachment = legacyCreateSlackBlocksForAfterCall($callEntity, $slackTokenInfo, $slackServiceData,
                $newStatus, $channel, $ts);
            if (empty($msgHistoryAttachment)) {
                // Log
                integrationLog('STOP_PROCESSING', 'Empty text');
                return false;
            }

            $formattedMsg =
                [
                    // Texte vide obligatoire
                    'text'        => '',
                    'attachments' => [$msgHistoryAttachment]

                ];
        } // Format général de texte
        else {
            $initialCallEventText = createCallEventTextV2($callEntity, $slackServiceData, [], false, 'Slack');
            $initialCallEventText['body']= json_encode($initialCallEventText['body']);

            // Textes du callEvent sont vides
            if (empty($initialCallEventText['title']) && empty($initialCallEventText['body'])) {
                // Log
                integrationLog(
                    'STOP_PROCESSING',
                    'CallEvent text is empty',
                    ['status' => $callEntity->status]
                );

                return false;
            }

            $contactInfos = getContactsForSlack(
                $contactManager,
                intval($callEntity->firstRingoverUser['team_id']),
                intval($callEntity->firstRingoverUser['id']),
                $callEntity->e164CustomerNumber,
                'SLACK_QUICKTALK'
            );

            $formattedMsg = createSlackBlocksForCall($callEntity, $slackServiceData, $initialCallEventText,
                $contactInfos);
        }
        //endregion

        // Update message
        try {
            updateSlackMessage($slackTokenInfo->accessToken, $channel, $ts, $formattedMsg);
            // log
            updateCallObjectLog($channel . '::' . $ts, true, ['callId' => $callEntity->callId]);
        } catch (Exception $e) {
            // log
            updateCallObjectLog(
                $channel . '::' . $ts,
                false,
                [
                    'callId' => $callEntity->callId,
                    'error'  => $e->getMessage(),
                    'msg'    => $formattedMsg
                ]
            );
            return false;
        }
    }

    return true;
}
/**
 * @param CommandQueryPDO $pdoHandler
 * @param SMS $smsEntity
 * @param mixed
 *
 * @return bool
 */
function processSMSEventForSlack(CommandQueryPDO $pdoHandler, SMS $smsEntity, ContactManager $contactManager): bool
{
    /** @var UserTokenInfos $slackTokenInfo */
    $slackTokenInfo = $smsEntity->integrations['SLACK'];
    $slackServiceData = json_decode(json_encode($slackTokenInfo->serviceData), true);

    // Early return
    if (isset($slackServiceData['enabled']) && !$slackServiceData['enabled']) {
        return false;
    }

    if (!smsFilter($smsEntity, $slackServiceData)) {
        // Filter not valid
        return false;
    }

    // Préparer les textes initials. Nouvel format
    if (!isset($slackServiceData['smsEventTexts']['en']['IN']['EXTERNAL']['header'])) {
        $initialSmsEventText = createSMSEventText($smsEntity, $slackServiceData, [], 'Slack');

        // Textes du smsEvent sont vides
        if (empty($initialSmsEventText['title']) && empty($initialSmsEventText['body'])) {
            // Log
            integrationLog(
                'STOP_PROCESSING',
                'SmsEvent text is empty'
            );

            return false;
        }
    }

    $e164CustomerNumber = $smsEntity->direction === SMSDirection::IN
        ? $smsEntity->from['number']['format']['e164']
        : $smsEntity->to['number']['format']['e164'];

    // Liste des users Ringover concernés par le sms
    $ringoverUsers = getRingoverUsersFromSmsEntity($smsEntity);

    // Pour chaque user, lui envoie un message
    foreach ($ringoverUsers as $ringoverUser) {
        $ringoverUserId = $ringoverUser['user_id'] - 10000;
        /** @var null|string slack userId, equal to channel for DM */
        $channel = getSlackUserIdFromUserMappingAndEmail(
            $slackTokenInfo,
            $slackServiceData,
            $ringoverUserId,
            $ringoverUser['email']
        );
        if (is_null($channel)) {
            // Log
            userSearchLog('', null, [
                'ringoverUserId' => $ringoverUserId,
                'source'         => 'user map',
                'event'          => 'sms'
            ]);
            continue;
        }

        // Legacy format de texte
        if (isset($slackServiceData['smsEventTexts']['en']['IN']['EXTERNAL']['header'])) {
            // Construct message text.
            $formattedMsg = legacyCreateSMSMessageBlocks(
                $pdoHandler,
                $smsEntity,
                $slackServiceData,
                $ringoverUserId,
                $smsEntity->teamId
            );
        } // Nouvel format de texte
        else {
            $contactInfos = getContactsForSlack(
                $contactManager,
                intval($smsEntity->teamId),
                intval($ringoverUserId),
                $e164CustomerNumber,
                'SLACK'
            );

            $formattedMsg = createSlackBlocksForSMS($smsEntity, $slackServiceData, $initialSmsEventText, $contactInfos);
        }

        try {
            // Post message.
            $result = postSlackMessage($slackTokenInfo->accessToken, $channel, $formattedMsg);

            // Log message creation
            createCallObjectLog(
                is_null($result->channel) ? null : $result->channel . '::' . $result->ts,
                [
                    'smsId'     => $smsEntity->id,
                    'directMsg' => true
                ]
            );
        } catch (Exception $e) {
            // Process message failed
            // Log
            createCallObjectLog(
                null,
                [
                    'message'    => $e->getMessage(),
                    'channelOri' => $channel,
                    'smsId'      => $smsEntity->id,
                    'directMsg'  => true
                ]
            );
            continue;
        }
    }

    // Enovyer msg dans un channel
    if (!empty($slackServiceData['smsChannel'])) {
        // Legacy text
        if (isset($slackServiceData['smsEventTexts']['en']['IN']['EXTERNAL']['header'])) {
            $formattedMsg = legacyCreateSMSMessageBlocks(
                $pdoHandler,
                $smsEntity,
                $slackServiceData,
                0,
                $smsEntity->teamId
            );
        } // Texte de nouvel format
        else {
            $contactInfos = getContactsForSlack(
                $contactManager,
                intval($smsEntity->teamId),
                $ringoverUserId,
                $e164CustomerNumber,
                'SLACK'
            );

            $formattedMsg = createSlackBlocksForSMS($smsEntity, $slackServiceData, $initialSmsEventText, $contactInfos);
        }

        try {
            // Post message.
            $result = postSlackMessage(
                $slackTokenInfo->accessToken,
                $slackServiceData['smsChannel'],
                $formattedMsg
            );

            // Log message creation
            createCallObjectLog(
                is_null($result->channel) ? null : $result->channel . '::' . $result->ts,
                [
                    'smsId'     => $smsEntity->id,
                    'directMsg' => false
                ]
            );
        } catch (Exception $e) {
            // Process message failed
            // Log
            createCallObjectLog(
                null,
                [
                    'message'    => $e->getMessage(),
                    'channelOri' => $slackServiceData['smsChannel'],
                    'smsId'      => $smsEntity->id,
                    'directMsg'  => false
                ]
            );
        }
    }

    return true;
}

//////////////////////////////////////////////
//////////////////////////////////////////////

/**
 * Get external user Id from configuration, for DM of normal call/sms event
 *
 * @param UserTokenInfos $userTokenInfos
 * @param array $slackServiceData
 * @param int $ringoverUserId
 * @param string $ringoverUserEmail
 * @return string|null
 */
function getSlackUserIdFromUserMappingAndEmail(
    UserTokenInfos $userTokenInfos,
    array $slackServiceData,
    int $ringoverUserId,
    string $ringoverUserEmail
): ?string
{
    $slackUserId = getUserIdFromUserMapping($ringoverUserId, $slackServiceData);
    if (is_null($slackUserId)) {
        $slackUserId = getSlackUserIdByEmail($userTokenInfos->accessToken, $ringoverUserEmail);
        if (
            !is_null($slackUserId) &&
            isExternalUserEmailIsDisabled($slackServiceData, $ringoverUserId, $slackUserId)
        ) {
            return null;
        }
    }
    return $slackUserId;
}

/**
 * @param string $token
 * @param string $ringoverUserEmail
 * @return mixed|null
 */
function getSlackUserIdByEmail(string $token, string $ringoverUserEmail) : ?string
{
    /** @var false|stdClass $response */
    $response = genericCallCrmApi(
        'SLACK',
        'GET',
        'https://slack.com/api/users.lookupByEmail?email=' . $ringoverUserEmail,
        [],
        [
            'Content-Type:application/json; charset=utf-8',
            'Authorization: Bearer ' . $token
        ],
        $httpReturnCode
    );
    return $response->user->id ?? null;
}

/**
 * Return <@slackUserId>, if set in user-mapping.
 * @see https://api.slack.com/reference/surfaces/formatting#mentioning-users
 *
 * @param string $ringoverUserEmail
 * @param int $ringoverUserId
 * @param array $slackServiceData
 * @param string $accessToken
 *
 * @return null|string
 */
function mentionSlackUserWithRingoverUserId(
    UserTokenInfos $slackTokenInfo,
    array $slackServiceData,
    int $ringoverUserId,
    string $ringoverUserEmail
) {
    $slackUserId = getSlackUserIdFromUserMappingAndEmail(
        $slackTokenInfo,
        $slackServiceData,
        $ringoverUserId,
        $ringoverUserEmail
    );
    return is_null($slackUserId) ? null : '<@' . $slackUserId . '>';
}

/**
 * FIXME: Faire une fonction générique
 * @param ContactManager $contactManager
 * @param int $ringoverUserTeamId
 * @param int $ringoverUserId
 * @param string $e164CustomerNumber
 *
 * @return IntegrationContactIdentity[] Tableau des contacts trouvés
 */
function getContactsForSlack(
    ContactManager $contactManager,
    int $ringoverUserTeamId,
    int $ringoverUserId,
    string $e164CustomerNumber,
    string $integrationServiceName
): array {
    $externalContact = $contactManager->getSynchronizedContacts($ringoverUserTeamId, $ringoverUserId,
        ltrim($e164CustomerNumber, '+'), MAX_CONTACTS_TO_SEARCH, $integrationServiceName);

    // Aucun contact trouvé, renvoie un contact vide
    if (empty($externalContact)) {
        $contact = new IntegrationContactIdentity();
        $contact->nameWithNumber = $e164CustomerNumber;
        $contact->data =
            [
                'socialService'    => '',
                'socialProfileUrl' => ''
            ];
        return [$contact];
    }

    // Un contact trouvé
    $contact = new IntegrationContactIdentity();
    $contact->id = $externalContact['integration_id'];
    $contact->name = ($externalContact['firstname'] ?? '') . ' ' . ($externalContact['lastname'] ?? '');
    $contact->nameWithNumber = $contact->name . ' (' . $e164CustomerNumber . ')';
    $contact->data['socialService'] = $externalContact['integration_name'] ?? '';
    $contact->data['socialProfileUrl'] = $externalContact['integration_url'] ?? '';

    return [$contact];
}

/**
 * @return stdClass Response contents
 * @throws Exception
 * @var array $formattedMsg
 *
 * @var string $token
 * @var string $channel
 */
function postSlackMessage(string $token, string $channel, array $formattedMsg): stdClass
{
    //region Post msg with curl
    $payload = array_merge(
        $formattedMsg,
        [
            'channel' => $channel
        ]
    );
    $headers = [
        'Content-Type:application/json; charset=utf-8',
        'Authorization: Bearer ' . $token
    ];

    /** @var false|stdClass $response */
    $response = genericCallCrmApi(
        'SLACK',
        'POST',
        'https://slack.com/api/chat.postMessage',
        $payload,
        $headers,
        $httpReturnCode,
        ['attachments.0.blocks']
    );

    $errorMsg = '';
    if (false === $response) {
        $errorMsg .= 'post msg failed';
    }
    if (is_object($response) && !$response->ok) {
        $errorMsg .= $response->error;
    }
    if (JSON_ERROR_NONE !== json_last_error()) {
        $errorMsg .= 'json_decode error: ' . json_last_error();
    }

    if (!empty($errorMsg)) {
        throw new Exception($errorMsg);
    }

    return $response;
}

/**
 * @return void
 * @throws Exception
 * @var string $ts
 * @var array $formattedMsg
 *
 * @var string $token
 * @var string $channel
 */
function updateSlackMessage(string $token, string $channel, string $ts, array $formattedMsg): void
{
    //region Update msg with curl
    $payload = array_merge(
        $formattedMsg,
        [
            'channel' => $channel,
            'ts'      => $ts
        ]
    );
    $header = [
        'Content-Type:application/json; charset=utf-8',
        'Authorization: Bearer ' . $token
    ];

    /** @var false|stdClass $httpResponse */
    $httpResponse = genericCallCrmApi(
        'SLACK',
        'POST',
        'https://slack.com/api/chat.update',
        $payload,
        $header,
        $httpReturnCode,
        ['attachments.0.blocks']
    );

    if (
        isset($httpResponse->ok) && !$httpResponse->ok
        || !$httpResponse
        || !in_array($httpReturnCode, [200, 201, 202, 204])
    ) {
        $error = $httpResponse->error ?? 'update msg failed';
        $message = $httpResponse->response_metadata->messages ?? '';
        throw new Exception(
            json_encode(
                [
                    'error'   => $error,
                    'message' => $message,
                    'payload' => $payload
                ]
            )
        );
    }
}

/**
 * @param Call $callEntity
 * @param array $slackServiceData
 * @param array $translatedEventText
 * @param IntegrationContactIdentity[] $contacts
 * @return array
 */
function createSlackBlocksForCall(
    Call $callEntity,
    array $slackServiceData,
    array $translatedEventText,
    array $contacts
): array {
    $blocks = [];
    $originalBody = $translatedEventText['body'];

    // Text body should be json array, convert it to array
    // line breaks (in note for ex.) may cause json_encode error
    $translatedEventText['body'] = json_decode(preg_replace("/(\r\n)+|\r|\n/", ", ", $translatedEventText['body']),
        true);
    if (JSON_ERROR_NONE !== json_last_error()) {
        // Log
        integrationLog('STOP_PROCESSING', 'Text body is not valid', ['text_body' => $originalBody]);
        return $blocks;
    }

    // Header
    $blocks[] = [
        'type'     => 'context',
        'block_id' => 'header',
        'elements' => createSlackBlockElementHeader($callEntity, $slackServiceData, $contacts, "SlackQuicktalk")
    ];

    // Tags
    $tagsElements = createSlackBlockElementTags($callEntity, $translatedEventText);
    if (!empty($tagsElements)) {
        $blocks[] = [
            'type'     => 'context',
            'block_id' => 'tags',
            'elements' => [$tagsElements]
        ];
    }

    // Notes
    $notesElements = createSlackBlockElementNotes($callEntity, $translatedEventText);
    if (!empty($notesElements)) {
        $blocks[] = [
            'type'     => 'context',
            'block_id' => 'notes',
            'elements' => [$notesElements]
        ];
    }

    // Empower (Transcription)
    $empowerTransElements = createSlackBlockElementEmpowerTranscription($callEntity, $translatedEventText);
    if (!empty($empowerTransElements)) {
        $blocks[] = [
            'type'     => 'context',
            'block_id' => 'transcript',
            'elements' => [$empowerTransElements]
        ];
    }

    // Empower (Summary)
    $empowerSummaryElements = createSlackBlockElementEmpowerSummary($callEntity, $translatedEventText);
    if (!empty($empowerSummaryElements)) {
        $blocks[] = [
            'type'     => 'context',
            'block_id' => 'summary',
            'elements' => [$empowerSummaryElements]
        ];
    }

    // Empower (Summary)
    $empowerNextStepsElements = createSlackBlockElementEmpowerNextSteps($callEntity, $translatedEventText);
    if (!empty($empowerNextStepsElements)) {
        $blocks[] = [
            'type'     => 'context',
            'block_id' => 'next_steps',
            'elements' => [$empowerNextStepsElements]
        ];
    }

    $bodyFields = [];
    // Body (IVR)
    $ivrInfos = createSlackBlockElementIvrInfosForCall($callEntity, $translatedEventText);
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
    $customCardInfos = createSlackBlockElementCustomerCard($slackServiceData, $translatedEventText, $contacts);
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
    $actionBtns = createSlackBlockElementActions($callEntity, $translatedEventText);
    if (!empty($actionBtns)) {
        $blocks[] = [
            'type'     => 'actions',
            'block_id' => 'action',
            'elements' => $actionBtns
        ];
    }
    // Footer
    $footer = createSlackBlockElementFooter($callEntity, $translatedEventText);
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
 * @param SMS $smsEntity
 * @param array $slackServiceData
 * @param array $translatedEventText
 * @param array $contacts
 * @return array
 */
function createSlackBlocksForSMS(
    SMS $smsEntity,
    array $slackServiceData,
    array $translatedEventText,
    array $contacts
): array {
    $blocks = [];

    // Text body should be json array, convert it to array
    $translatedEventText['body'] = json_decode($translatedEventText['body'], true);
    if (JSON_ERROR_NONE !== json_last_error()) {
        // Log
        integrationLog('STOP_PROCESSING', 'Text body is not valid');
        return $blocks;
    }

    // Header
    $blocks[] = [
        'type'     => 'context',
        'block_id' => 'header',
        'elements' => createSlackBlockElementHeader($smsEntity, $slackServiceData, $contacts)
    ];

    // Message contents
    $msgElements = createSlackBlockElementSmsMessage($translatedEventText);
    if (!empty($msgElements)) {
        $blocks[] = [
            'type'     => 'context',
            'block_id' => 'mgs',
            'elements' => $msgElements
        ];
    }

    $bodyFields = [];
    // Body (IVR)
    $ivrInfos = createSlackBlockElementIvrInfosForSMS($smsEntity, $translatedEventText);
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
    $customCardInfos = createSlackBlockElementCustomerCard($slackServiceData, $translatedEventText, $contacts);
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
 * Générer l'url d'icône selon le statut d'appel
 * @param Call $callEntity
 * @return string|null
 */
function getHeaderIconForCallSlack(Call $callEntity): ?string
{
    $icoName = '';
    $newStatus = getCallStatusForSlack($callEntity);
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
        $baseUrl = RO_API_BASE_URL . '/web/img/icons/';
        return $baseUrl . $icoName;
    }
    return null;
}

/**
 * Elements of header block, call
 * @param Call|SMS $telecomEntity
 * @param array $slackServiceData
 * @param IntegrationContactIdentity[] $contacts
 * @return array
 */
function createSlackBlockElementHeader($telecomEntity, array $slackServiceData, array $contacts, string $integrationFolderName = "Slack"): array
{
    $elements = [];

    if ($telecomEntity instanceof CALL) {
        $e164CustomerNumber = $telecomEntity->e164CustomerNumber;

        // For call, insert icon to the begining of header
        $iconUrl = getHeaderIconForCallSlack($telecomEntity);
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
 * @param App\Domain\CallEvent\Call $callEntity
 * @param array $translatedEventText
 * @return array<string>
 */
function createSlackBlockElementTags(Call $callEntity, array $translatedEventText): array
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
 * @param App\Domain\CallEvent\Call $callEntity
 * @param array $translatedEventText
 * @return array<string>
 */
function createSlackBlockElementNotes(Call $callEntity, array $translatedEventText): array
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
function createSlackBlockElementEmpowerTranscription(Call $callEntity, array $translatedEventText): array
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
function createSlackBlockElementEmpowerSummary(Call $callEntity, array $translatedEventText): array
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
function createSlackBlockElementEmpowerNextSteps(Call $callEntity, array $translatedEventText): array
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
function createSlackBlockElementIvrInfosForCall(Call $callEntity, array $translatedEventText): string
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
 * Elements of callEvent Notes
 * @param SMS $callEntity
 * @param array $translatedEventText
 * @return string
 */
function createSlackBlockElementIvrInfosForSMS(SMS $smsEntity, array $translatedEventText): string
{
    if (
        SMS::COLLABORATIVE_CONVERSATION !== $smsEntity->conversationType
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
function createSlackBlockElementCustomerCard(
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
    $suffix = MAX_CONTACTS_TO_SEARCH < count($contacts) ? ' ...' : '';
    for ($i = 0; $i < count($contacts); $i++) {
        if (MAX_CONTACTS_TO_SEARCH < count($contacts)) {
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
function createSlackBlockElementActions(Call $callEntity, array $translatedEventText): array
{
    $isAnonymous = false !== strpos($callEntity->e164CustomerNumber, 'anonymous');
    $status = getCallStatusForSlack($callEntity);
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
function createSlackBlockElementFooter(Call $callEntity, array $translatedEventText): array
{
    if (
        CallDirection::OUT === $callEntity->direction
        || CallStatus::HANGUP !== getCallStatusForSlack($callEntity)
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
 * Element du message SMS
 * @param array $translatedEventText
 * @return array<array>
 */
function createSlackBlockElementSmsMessage(array $translatedEventText): array
{
    $elements = [];

    if (
        isset($translatedEventText['body']['message']['value'])
        && !empty($translatedEventText['body']['message']['value'])
    ) {
        $elements[] = [
            'type' => 'mrkdwn',
            'text' => $translatedEventText['body']['message']['title'] . ' ' . $translatedEventText['body']['message']['value']
        ];
    }
    return $elements;
}

/**
 * Post new or update message, according to saved callObjectHistory.
 *
 * @param CommandQueryPDO $pdoHandler
 * @param Call $callEntity
 * @param string $accessToken
 * @param string $channel
 * @param array $formattedMsg Can be empty: do not update the message, but delete the others
 *
 * @return void
 * @throws Exception
 */
function createOrUpdateCallObjectForSlack(
    CommandQueryPDO $pdoHandler,
    Call $callEntity,
    string $accessToken,
    string $channel,
    array $formattedMsg,
    string $serviceName
) {
    $ringoverNumber = strval($callEntity->ringoverNumber);
    $ivrNumber = $callEntity->isIVR ? strval($callEntity->ivrNumber) : '';

    /**
     * 1. If callObjectHistory exists, means a message has been posted.
     *    Update that message, update callObjectHistory.
     * 2. If history does not exist, means it's the first event/message.
     *    Send message and save infos to DB.
     */
    if (isset($callEntity->integrations[$serviceName]['call_objects']) && 'SLACK_QUICKTALK' != $serviceName) {
        foreach ($callEntity->integrations[$serviceName]['call_objects'] as $callObject) {
            $newStatus = getCallStatusForSlack($callEntity);
            $oldStatus = $callObject['objectData']['status'];

            /** @var bool $isNbrsEqual caller and callee numbers match thoses in history */
            if ($callEntity->isIVR) {
                if (CallStatus::MISSED === $newStatus && CallStatus::INCALL === $oldStatus) {
                    $newStatus = CallStatus::INCALL;
                }
                // If an agent picked up (incall), show incall to all agents.
                if (CallStatus::INCALL === $newStatus && CallStatus::INCOMING === $oldStatus) {
                    $isNbrsEqual = $ivrNumber === $callObject['objectData']['ivrNumber'];
                } else {
                    $isNbrsEqual = $ivrNumber === $callObject['objectData']['ivrNumber']
                        && strval($callEntity->customerNumber) === $callObject['objectData']['customerNumber'];
                }
            } else {
                $isNbrsEqual = $ringoverNumber === $callObject['objectData']['ringoverNumber']
                    && strval($callEntity->customerNumber) === $callObject['objectData']['customerNumber'];
            }

            // For anonymous caller, it may be set in history for previous status
            if ('anonymous' === $callObject['objectData']['customerNumber']) {
                // Set customer number to anonymous if it's not for current status
                if ('anonymous' !== $realCustomerNbr = strval($callEntity->customerNumber)) {
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
                    $isNbrsEqual = $callEntity->isIVR
                        ? $ivrNumber === $callObject['objectData']['ivrNumber']
                        : $ringoverNumber === $callObject['objectData']['ringoverNumber'];
                }
                // Remove callback btn if exist
                foreach ($formattedMsg['attachments'][0]['blocks'] as $bk => $block) {
                    if (!isset($block['type']) || !isset($block['id'])) {
                        // Test and debug
                        integrationLog($serviceName.' DEBUG', 'Empty block type or id', ['block' => $block]);
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
                updateCallObjectLog(
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
                // Update message
                updateSlackMessage($accessToken, $savedChannel, $savedTs, $formattedMsg);
                // Update callObjectHistory
                $callObject['objectData']['status'] = $newStatus;
                updateIntegrationCallObject(
                    $pdoHandler,
                    $serviceName,
                    $callEntity->callId,
                    $callEntity->channelId,
                    $callEntity->teamId,
                    $callObject['objectData'],
                    $callObject['id']
                );
                // Log message updating
                updateCallObjectLog(
                    $savedChannel . '::' . $savedTs,
                    true,
                    [
                        'callId'      => $callEntity->callId,
                        'newStatus'   => $newStatus,
                        'agentStatus' => $callEntity->agentStatus
                    ]
                );
            } catch (Exception $e) {
                // Log message updating
                updateCallObjectLog(
                    $savedChannel . '::' . $savedTs,
                    false,
                    [
                        'callId'      => $callEntity->callId,
                        'newStatus'   => $newStatus,
                        'agentStatus' => $callEntity->agentStatus,
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
            $result = postSlackMessage($accessToken, $channel, $formattedMsg);
            $status = getCallStatusForSlack($callEntity);

            /**
             * Retrieve real channel.
             * For DM, channel (userID) to post is different than the channel in which message is created.
             */
            $channel = $result->channel;

            // Prepare history data
            $objectData = [
                'ringoverNumber' => strval($callEntity->ringoverNumber),
                'customerNumber' => strval($callEntity->customerNumber),
                'ivrNumber'      => $callEntity->isIVR ? strval($callEntity->ivrNumber) : '',
                'status'         => $status,
                'channel'        => $channel,
                'ts'             => $result->ts
            ];
            // Save history data
            saveIntegrationCallObject(
                $pdoHandler,
                $serviceName,
                $callEntity->callId,
                $callEntity->channelId,
                $callEntity->teamId,
                $objectData
            );

            // Log
            createCallObjectLog(
                $result->channel . '::' . $result->ts,
                [
                    'channel'     => $result->channel,
                    'ts'          => $result->ts,
                    'status'      => $status,
                    'agentStatus' => $callEntity->agentStatus,
                ]
            );
        } catch (Exception $e) {
            // Log
            createCallObjectLog(
                null,
                [
                    'error'       => 'Create new Slack message',
                    'message'     => $e->getMessage(),
                    'status'      => getCallStatusForSlack($callEntity),
                    'agentStatus' => $callEntity->agentStatus
                ]
            );

            throw $e;
        }
        //endregion
    }
}

/**
 * Get call status for IVR and normal calls
 *
 * @param Call $callEntity
 *
 * @return string
 */
function getCallStatusForSlack(Call $callEntity): string
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

//////////////////////////////////////////////
//////////////////////////////////////////////

#region Legacy functions

/**
 * @param CommandQueryPDO $pdoHandler
 * @param int $currentUserId
 * @param int $currentUserTeamId default 0
 * @param string $otherUserPhoneNumStr
 *
 * @return array Tableau des contacts trouvés
 */
function legacyGetContactsForSlack(
    CommandQueryPDO $pdoHandler,
    int $currentUserId,
    int $currentUserTeamId,
    string $otherUserPhoneNumStr
): array {
    return [];
}

/**
 * Create text for sms event
 *
 * @param CommandQueryPDO $pdoHandler
 * @param SMS $smsEntity
 * @param mixed $slackServiceData
 * @param int $ringoverUserId
 * @param int $ringoverTeamId
 *
 * @return array
 */
function legacyCreateSlackSMSEventTextSlack(
    CommandQueryPDO $pdoHandler,
    SMS $smsEntity,
    $slackServiceData,
    int $ringoverUserId = 0,
    int $ringoverTeamId = 0
): array {
    // No text defined in config, can not process. Abort.
    if (!isset($slackServiceData['smsEventTexts'])) {
        throw new Exception('SMS : no text defined from config.');
    }
    $language = $slackServiceData['languageCode'] === 'fr' ? 'fr' : 'en';

    if (
        !isset($slackServiceData['smsEventTexts'][$language][$smsEntity->direction][$smsEntity->conversationType])
        || empty($slackServiceData['smsEventTexts'][$language][$smsEntity->direction][$smsEntity->conversationType])
    ) {
        throw new Exception('SMS : no text defined for direction "' . $smsEntity->direction
            . '", type: ' . $smsEntity->conversationType);
    }
    $textFromConfig = $slackServiceData['smsEventTexts'][$language][$smsEntity->direction][$smsEntity->conversationType];

    //region ExternalUser - CustomerCard
    if (isset($textFromConfig['customerCard']) && !empty($textFromConfig['customerCard'])) {
        $customerContactsInfo = legacyGetContactsForSlack(
            $pdoHandler,
            $ringoverUserId,
            $ringoverTeamId,
            $smsEntity->direction === SMSDirection::IN
                ? $smsEntity->from['number']['format']['e164']
                : $smsEntity->to['number']['format']['e164']
        );

        // Condition 1/3 : pas de contact trouvé
        if (empty($customerContactsInfo)) {
            $textFromConfig['customerCard'] = EXTRA_EVENT_TEXTS_LEGACY['noContactFound'][$slackServiceData['languageCode']];
        } elseif (1 === count($customerContactsInfo)) {
            // Condition 2/3 : un contact unique trouvé
            $customerContactInfo = current($customerContactsInfo);

            // If no value to pass, set entire sentence to empty.
            if (empty($customerContactInfo['socialServiceName']) && empty($customerContactInfo['socialProfileUrl'])) {
                $textFromConfig['customerCard'] = '';
            } elseif (empty($customerContactInfo['socialServiceName'])) {
                // socialServiceName est vide, l'envlève, et garde socialProfileUrl
                preg_match(
                    '/.+(\<.+\>)/',
                    $textFromConfig['customerCard'],
                    $matches
                );
                $textFromConfig['customerCard'] = $matches[1];
            } elseif (empty($customerContactInfo['socialProfileUrl'])) {
                // socialProfileUrl est vide, l'enlève, et garde clientServiceName
                preg_match(
                    '/(.+)\\n\s*\<.+/',
                    $textFromConfig['customerCard'],
                    $matches
                );
                $textFromConfig['customerCard'] = $matches[1];
            }

            if (!empty($customerContactInfo['socialServiceName'])) {
                $variableForTextReplacement['/:clientServiceName/'] = $customerContactInfo['socialServiceName'];
            }

            if (!empty($customerContactInfo['socialProfileUrl'])) {
                $variableForTextReplacement['/:socialProfileUrl/'] = $customerContactInfo['socialProfileUrl'];
            }
        } else {
            // Condition 3/3 : plusieurs contacts trouvés
            $customerCardText = '';
            $socialServiceNames = [];
            $socialProfileUrls = [];
            foreach ($customerContactsInfo as $key => $contactInfo) {
                // socialServiceName existe, l'insert au tableau
                if (!empty($contactInfo['socialServiceName'])) {
                    $socialServiceNames[] = $contactInfo['socialServiceName'];
                }

                // socialProfileUrl existe
                if (!empty($contactInfo['socialProfileUrl'])) {
                    // Définit le texte du lien
                    if (empty($contactInfo['firstName']) && empty($contactInfo['lastName'])) {
                        $linkText = 'Link ' . ($key + 1);
                    } else {
                        $linkText = $contactInfo['firstName'] . ' ' . $contactInfo['lastName'];
                    }

                    // Construire les liens de socialProfileUrl
                    if (2 === $key) {
                        // 3 liens max
                        $socialProfileUrls[] = '<' . $contactInfo['socialProfileUrl'] . '|' . $linkText . '> ...';
                        break;
                    } else {
                        $socialProfileUrls[] = '<' . $contactInfo['socialProfileUrl'] . '|' . $linkText . '>';
                    }
                }
            }

            $socialServiceNamesStr = implode(', ', array_unique($socialServiceNames));
            $socialProfileUrlsStr = implode("\n", $socialProfileUrls);

            if (!empty($socialServiceNamesStr)) {
                $customerCardText .= '*' . $socialServiceNamesStr . '*';
            }

            $customerCardText .= "\n " . EXTRA_EVENT_TEXTS_LEGACY['multipleContactsFound'][$slackServiceData['languageCode']];

            if (!empty($socialProfileUrlsStr)) {
                $customerCardText .= ": \n" . $socialProfileUrlsStr;
            }

            $textFromConfig['customerCard'] = $customerCardText;
        }
    }
    //endregion ExternalUser - CustomerCard

    //region Header et IVR infos
    $variableForTextReplacement['/:fromNumberInE164/'] = $smsEntity->from['number']['format']['e164'];
    $variableForTextReplacement['/:toNumberInE164/'] = $smsEntity->to['number']['format']['e164'];

    if ($smsEntity->direction === SMSDirection::IN) {
        $alphanumericName = $smsEntity->from['alphanumeric'] ?? '';

        $fromName = is_null($smsEntity->from['contact'])
            ? $alphanumericName
            : $smsEntity->from['contact']['firstname'] . ' ' . $smsEntity->from['contact']['lastname'];

        $variableForTextReplacement['/:fromName/'] = $fromName;

        if ($smsEntity->conversationType === SMS::COLLABORATIVE_CONVERSATION) {
            $variableForTextReplacement['/:toIvrName/'] = $smsEntity->to['ivr']['name'];
        } else {
            $variableForTextReplacement['/:toName/'] = $smsEntity->to['user']['firstname'] . ' ' . $smsEntity->to['user']['lastname'];
        }
    } else {
        $alphanumericName = $smsEntity->to['alphanumeric'] ?? '';

        $toName = is_null($smsEntity->to['contact'])
            ? $alphanumericName
            : $smsEntity->to['contact']['firstname'] . ' ' . $smsEntity->to['contact']['lastname'];

        $variableForTextReplacement['/:toName/'] = $toName;

        if ($smsEntity->conversationType === SMS::COLLABORATIVE_CONVERSATION) {
            $variableForTextReplacement['/:fromIvrName/'] = $smsEntity->from['ivr']['name'];
        } else {
            $variableForTextReplacement['/:fromName/'] = $smsEntity->from['user']['firstname'] . ' ' . $smsEntity->from['user']['lastname'];
        }
    }
    //endregion Header et IVR infos

    // Necessary replacement
    $variableForTextReplacement['/\(\)/'] = '';
    $variableForTextReplacement['/\*\s*\*/'] = '';

    // Remplacement des tags dans les textes
    $text = preg_replace(
        array_keys($variableForTextReplacement),
        array_values($variableForTextReplacement),
        $textFromConfig
    );

    return $text;
}

/**
 * @param CommandQueryPDO $pdoHandler
 * @param Call $callEntity
 * @param array $slackServiceData
 *
 * @return array
 * @throws Exception
 */
function legacyCreateSlackBlocksForCall(
    UserTokenInfos $userTokenInfos,
    CommandQueryPDO $pdoHandler,
    Call $callEntity,
    array $slackServiceData
): array
{
    $newStatus = getCallStatusForSlack($callEntity);
    $textArray = legacyCreateCallEventTextSlack(
        $userTokenInfos,
        $pdoHandler,
        $callEntity,
        $newStatus,
        $slackServiceData
    );

    //region headerBlock
    $headerBlock = legacyCreateHeaderBlockForCall($textArray['header'], $callEntity, $newStatus);
    //endregion

    //region Initiate message content and header
    $block = [
        'text'        => $textArray['title'],
        'attachments' => [
            [
                'color'  => $textArray['color'],
                "blocks" => [
                    $headerBlock
                ]
            ]
        ]
    ];
    //endregion

    //region tag and note
    if (isset($slackServiceData['showTagsNotes']) && $slackServiceData['showTagsNotes']) {
        $callEntity->loadTagsAndComments();

        $tagNoteBlock = legacyCreateTagNoteBlock($callEntity);
        if (!empty($tagNoteBlock)) {
            $block['attachments'][0]['blocks'] =
                array_merge($block['attachments'][0]['blocks'], array_values($tagNoteBlock));
        }
    }
    //endregion

    //region bodyBlock
    $bodyBlock = legacyCreateBodyBlockForCall(
        $textArray['ivrInfo'] ?? '',
        $textArray['customerCard']
    );
    if (!empty($bodyBlock)) {
        array_push($block['attachments'][0]['blocks'], $bodyBlock);
    }
    //endregion

    //region actionBlock
    $actionBlock = legacyCreateActionBlockForCall(
        $callEntity,
        $newStatus,
        $textArray
    );
    if (!empty($actionBlock)) {
        array_push($block['attachments'][0]['blocks'], $actionBlock);
    }
    //endregion

    //region pre-footerBlock
    $preFooterBlock = legacyrCreatePreFooterBlockForCall($textArray);
    if (!empty($preFooterBlock)) {
        array_push($block['attachments'][0]['blocks'], $preFooterBlock);
    }
    //endregion

    //region footerBlock
    $footerBlock = legacyCreateFooterBlockForCall(
        $pdoHandler,
        $callEntity,
        $newStatus,
        $textArray
    );
    if (!empty($footerBlock)) {
        array_push($block['attachments'][0]['blocks'], $footerBlock);
    }
    //endregion

    return $block;
}

function legacyCreateSlackBlocksForAfterCall(
    Call $callEntity,
    UserTokenInfos $slackTokenInfo,
    array $slackServiceData,
    string $newStatus,
    string $channel,
    string $ts
): array {
    // Retrieve message history
    try {
        $msgHistory = legacyRetrieveSlackMessage($slackTokenInfo->accessToken, $channel, $ts);
        $msgHistoryArray = json_decode(json_encode($msgHistory), true);
        $msgHistoryAttachment = $msgHistoryArray['messages'][0]['attachments'][0];
    } catch (Exception $e) {
        // Log
        updateCallObjectLog(
            $channel . '::' . $ts,
            false,
            [
                'reason' => 'failed to retrieve message',
                'error'  => $e->getMessage(),
                'event'  => 'aftercall'
            ]
        );
        return [];
    }

    // Prepare tag and note
    $aftercallElementsBlock = legacyCreateTagNoteBlock($callEntity);

    // check IVR_CALL
    $isIvrInfoMissed = false;
    if ($callEntity->isIVR) {
        $ivrName = $callEntity->ivrName;
        // If ivr does not present in message
        if (false === strpos(json_encode($msgHistoryAttachment), $ivrName)) {
            $isIvrInfoMissed = true;
        }
    }

    //region Deal with replace or insert tagNote block
    $headerBlockPosition = null; // used for inserting tag, note
    $bodyBlockPosition = null; // used to detect body block existance for ivr info
    $actionBlockPosition = null; // used for inserting ivr info
    $footerBlockPosition = null; // used for inserting ivr info
    $isTagNoteReplaced = false;
    foreach ($msgHistoryAttachment['blocks'] as $key => $block) {
        // If tag or nete already exists, replace them with new ones
        if (
            isset($aftercallElementsBlock[$block['block_id']])
            && !empty($aftercallElementsBlock[$block['block_id']])
        ) {
            $isTagNoteReplaced = true;
            $msgHistoryAttachment['blocks'][$key] = $aftercallElementsBlock[$block['block_id']];
        }

        // Get header block ID
        if (!$isTagNoteReplaced && 'header' === $block['block_id']) {
            $headerBlockPosition = $key;
        }

        // Prepare for missed ivr info
        if ($isIvrInfoMissed) {
            // Get body section block ID
            if (!$isTagNoteReplaced && 'body' === $block['block_id']) {
                $bodyBlockPosition = $key;
            }
            // Get action block ID
            if (!$isTagNoteReplaced && 'action' === $block['block_id']) {
                $actionBlockPosition = $key;
            }
            // Get footer block ID
            if (!$isTagNoteReplaced && 'footer' === $block['block_id']) {
                $footerBlockPosition = $key;
            }
        }
    }

    /**
     *  If action block presents, insert aftercallElements before it.
     */
    if (!$isTagNoteReplaced && !is_null($headerBlockPosition)) {
        array_splice(
            $msgHistoryAttachment['blocks'],
            $headerBlockPosition,
            0,
            array_values($aftercallElementsBlock)
        );
    }
    //endregion

    // If ivr info missed
    if ($isIvrInfoMissed) {
        $language = $slackServiceData['languageCode'] === 'fr' ? 'fr' : 'en';
        $targetText = getTargetText($callEntity);

        if (
            !isset($slackServiceData['callEventTexts'][$language][$targetText][$newStatus])
            || empty($slackServiceData['callEventTexts'][$language][$targetText][$newStatus])
            || !isset($slackServiceData['callEventTexts'][$language][$targetText][$newStatus]['ivrInfo'])
        ) {
            /**
             * Ivr text not defined in config
             * Should not arrive
             */
            // Log
            updateCallObjectLog(
                $channel . '::' . $ts,
                false,
                [
                    'message' => 'IVR text not defined',
                    'event'   => 'aftercall'
                ]
            );
            return [];
        }

        /** @var array $textFromConfig May be empty */
        $textFromConfig = $slackServiceData['callEventTexts'][$language][$targetText][$newStatus];
        $text = preg_replace(
            ['/:ivrName/', '/:ivrNumberInE164/'],
            [$callEntity->ivrName, $callEntity->ivrE164Number],
            $textFromConfig
        );

        $ivrField =
            [
                'type' => 'mrkdwn',
                'text' => $text['ivrInfo']
            ];
        /**
         * 1. body block exists. unshift body with ivr info
         * 2. body inexiste. Create body with ivr info
         */
        if (!is_null($bodyBlockPosition)) {
            array_unshift($msgHistoryAttachment['blocks'][$bodyBlockPosition]['fields'], $ivrField);
        } else {
            $bodyBlock = [
                'type'     => 'section',
                'fields'   => [
                    $ivrField
                ],
                'block_id' => 'body'
            ];

            // Insert body block
            if (!is_null($actionBlockPosition)) {
                array_splice($msgHistoryAttachment['blocks'], $actionBlockPosition, 0, $bodyBlock);
            } elseif (!is_null($footerBlockPosition)) {
                array_splice($msgHistoryAttachment['blocks'], $footerBlockPosition, 0, $bodyBlock);
            } else {
                array_push($msgHistoryAttachment['blocks'], $bodyBlock);
            }
        }
    }

    return $msgHistoryAttachment;
}

/**
 * Retrieve posted slack message. https://api.slack.com/methods/conversations.history
 *
 * @param string $token
 * @param string $channel
 * @param string $ts
 *
 * @return stdClass message history
 * @throws Exception
 */
function legacyRetrieveSlackMessage(string $token, string $channel, string $ts): stdClass
{
    //region Update msg with curl
    $query =
        [
            'channel'   => $channel,
            'latest'    => $ts,
            'inclusive' => true,
            'limit'     => 1
        ];
    $header = [
        'Content-Type:application/json; charset=utf-8',
        'Authorization: Bearer ' . $token
    ];
    $url = 'https://slack.com/api/conversations.history?' . http_build_query($query);

    /** @var false|stdClass $httpResponse */
    $httpResponse = genericCallCrmApi('SLACK', 'GET', $url, [], $header, $httpReturnCode);

    if (!$httpResponse || !$httpResponse->ok) {
        $msg = is_bool($httpResponse) ? 'retrieve msg failed' : $httpResponse->error;
        throw new Exception($msg);
    }

    return $httpResponse;
}

/**
 * Create text for call event
 *
 * @param UserTokenInfos $slackTokenInfo
 * @param CommandQueryPDO $pdoHandler
 * @param Call $callEntity
 * @param string $statusForText
 * @param mixed $slackServiceData
 *
 * @return array
 * @throws Exception
 */
function legacyCreateCallEventTextSlack(
    UserTokenInfos $slackTokenInfo,
    CommandQueryPDO $pdoHandler,
    Call $callEntity,
    string $statusForText,
    $slackServiceData
): array {
    // No text defined in config, can not process. Abort.
    if (!isset($slackServiceData['callEventTexts'])) {
        throw new Exception('Call : no text defined from config.');
    }

    $language = $slackServiceData['languageCode'] === 'fr' ? 'fr' : 'en';
    $targetText = getTargetText($callEntity);

    if (
        !isset($slackServiceData['callEventTexts'][$language][$targetText][$statusForText])
        || empty($slackServiceData['callEventTexts'][$language][$targetText][$statusForText])
    ) {
        throw new Exception('Call' . ($callEntity->isIVR ? ' IVR' : '') . ' : no text defined for status: ' . $statusForText);
    }

    /** @var array $textFromConfig May be empty */
    $textFromConfig = $slackServiceData['callEventTexts'][$language][$targetText][$statusForText];

    //region ExternalUser
    $customerContactsInfo = legacyGetContactsForSlack(
        $pdoHandler,
        $callEntity->firstRingoverUser['id'],
        $callEntity->firstRingoverUser['team_id'],
        $callEntity->customerNumber
    );

    /**
     * Condition 1/3 : pas de contact trouvé
     * Header : affiche numéro. Pas de nom, pas de lien
     * CustomerCard : Contact inconnu.
     */
    if (empty($customerContactsInfo)) {
        preg_match(
            '/(.+)<:clientProfileUrl\|(.+)>(.*)/',
            $textFromConfig['header'],
            $matches
        );
        /**
         * $matches[1] : pre-text
         * $matches[2] : e164 number
         * $matches[3] : post-text
         */
        $textFromConfig['header'] = ($matches[1] ?? '') . ($matches[2] ?? '') . ($matches[3] ?? '');

        if (empty($textFromConfig['header'])) {
            // Log
            integrationLog(
                'MISSING_EVENT_TEXT',
                'header',
                [
                    'onEvent'    => 'call',
                    'condition'  => 'contact not found',
                    'configText' => $textFromConfig
                ]
            );
        }

        $textFromConfig['customerCard'] = EXTRA_EVENT_TEXTS_LEGACY['noContactFound'][$slackServiceData['languageCode']];

        $customerContactName = '';
    } elseif (1 === count($customerContactsInfo)) {
        /**
         * Condition 2/3 : un contact unique trouvé
         * Header : nom, numéro, lien
         * CustomerCard : socialServicaName, socialProfileUrl
         */
        $customerContactInfo = current($customerContactsInfo);

        // If neither socialServiceName nor socialProfileUrl exists.
        if (empty($customerContactInfo['socialProfileUrl']) && empty($customerContactInfo['socialServiceName'])) {
            // For Header: if socialProfileUrl is empty, remove contact link, keep only name and number
            preg_match(
                '/(.+)\<:clientProfileUrl\|(.+)\>(.*)/',
                $textFromConfig['header'],
                $matches
            );
            /**
             * $matches[1] : pre-text
             * $matches[2] : e164 number
             * $matches[3] : post-text
             */
            $textFromConfig['header'] = $matches[1] . $matches[2] . $matches[3];

            if (empty($textFromConfig['header'])) {
                // Log
                integrationLog(
                    'MISSING_EVENT_TEXT',
                    'header',
                    [
                        'onEvent'    => 'call',
                        'condition'  => 'without profile url and service name',
                        'configText' => $slackServiceData['callEventTexts'][$language][$targetText][$statusForText]['header']
                    ]
                );
            }

            // For CustomerCard: set entire sentence to empty
            $textFromConfig['customerCard'] = '';
        }

        if (!empty($customerContactInfo['socialProfileUrl']) && empty($customerContactInfo['socialServiceName'])) {
            // For CustomerCard
            preg_match(
                '/.+(\<.+\>)/',
                $textFromConfig['customerCard'],
                $matches
            );
            $textFromConfig['customerCard'] = $matches[1];

            if (empty($textFromConfig['customerCard'])) {
                // Log
                integrationLog(
                    'MISSING_EVENT_TEXT',
                    'customerCard',
                    [
                        'onEvent'    => 'call',
                        'condition'  => 'without service name',
                        'configText' => $slackServiceData['callEventTexts'][$language][$targetText][$statusForText]['customerCard']
                    ]
                );
            }
        }

        if (empty($customerContactInfo['socialProfileUrl']) && !empty($customerContactInfo['socialServiceName'])) {
            // For Header: if socialProfileUrl is empty, remove contact link, keep only name and number
            preg_match(
                '/(.+)\<:clientProfileUrl\|(.+)\>(.*)/',
                $textFromConfig['header'],
                $matches
            );
            /**
             * $matches[1] : pre-text
             * $matches[2] : e164 number
             * $matches[3] : post-text
             */
            $textFromConfig['header'] = $matches[1] . $matches[2] . $matches[3];

            if (empty($textFromConfig['header'])) {
                // Log
                integrationLog(
                    'MISSING_EVENT_TEXT',
                    'header',
                    [
                        'onEvent'    => 'call',
                        'condition'  => 'without profile url',
                        'configText' => $slackServiceData['callEventTexts'][$language][$targetText][$statusForText]['header']
                    ]
                );
            }

            // For CustomerCard: if socialProfileUrl is empty, remove contact link, keep socialServiceName
            preg_match(
                '/(.+)\\n\s*\<.+/',
                $textFromConfig['customerCard'],
                $matches
            );
            // $matches[1] : socialServiceName
            $textFromConfig['customerCard'] = $matches[1];

            if (empty($textFromConfig['customerCard'])) {
                // Log
                integrationLog(
                    'MISSING_EVENT_TEXT',
                    'customerCard',
                    [
                        'onEvent'    => 'call',
                        'condition'  => 'without profile url',
                        'configText' => $slackServiceData['callEventTexts'][$language][$targetText][$statusForText]['customerCard']
                    ]
                );
            }
        }


        if (!empty($customerContactInfo['socialServiceName'])) {
            $variableForTextReplacement['/:clientServiceName/'] = $customerContactInfo['socialServiceName'];
        }

        if (!empty($customerContactInfo['socialProfileUrl'])) {
            // Surround name and number in title, with the link
            $variableForTextReplacement['/:socialProfileUrl/'] = $customerContactInfo['socialProfileUrl'];
        }

        // Construct contact name for header
        if (empty($customerContactInfo['firstName']) && empty($customerContactInfo['lastName'])) {
            $customerContactName = '';
        } else {
            $customerContactName = $customerContactInfo['firstName'] . ' ' . $customerContactInfo['lastName'];
        }
    } else {
        /**
         * Condition 3/3 : plusieurs contacts trouvés
         * Header : affiche numéro. Pas de nom, pas de lien
         * CustomerCard : SocialServiceName, socialProfileUrl (3 max)
         */
        $headerPatternFounded = preg_match(
            '/(.+)\<:clientProfileUrl\|(.+)\>(.*)/',
            $textFromConfig['header'],
            $matches
        );

        if ($headerPatternFounded === 1) {
            /**
             * $matches[1] : pre-text
             * $matches[2] : e164 number
             * $matches[3] : post-text
             */
            $textFromConfig['header'] = $matches[1] . $matches[2] . $matches[3];
        }

        if (empty($textFromConfig['header'])) {
            // Log
            integrationLog(
                'MISSING_EVENT_TEXT',
                'header',
                [
                    'onEvent'    => 'call',
                    'condition'  => 'many contacts found',
                    'configText' => $slackServiceData['callEventTexts'][$language][$targetText][$statusForText]['header']
                ]
            );
        }
        $customerContactName = '';

        //region customerCard
        $customerCardText = '';
        $socialServiceNames = [];
        $socialProfileUrls = [];
        foreach ($customerContactsInfo as $key => $contactInfo) {
            // socialServiceName existe, l'insert au tableau
            if (!empty($contactInfo['socialServiceName'])) {
                $socialServiceNames[] = $contactInfo['socialServiceName'];
            }

            // socialProfileUrl existe
            if (!empty($contactInfo['socialProfileUrl'])) {
                // Définit le texte du lien
                if (empty($contactInfo['firstName']) && empty($contactInfo['lastName'])) {
                    $linkText = 'Link ' . ($key + 1);
                } else {
                    $linkText = $contactInfo['firstName'] . ' ' . $contactInfo['lastName'];
                }

                // Construire les liens de socialProfileUrl
                if (2 === $key) {
                    // 3 liens max
                    $socialProfileUrls[] = '<' . $contactInfo['socialProfileUrl'] . '|' . $linkText . '> ...';
                    break;
                } else {
                    $socialProfileUrls[] = '<' . $contactInfo['socialProfileUrl'] . '|' . $linkText . '>';
                }
            }
        }

        $socialServiceNamesStr = implode(', ', array_unique($socialServiceNames));
        $socialProfileUrlsStr = implode("\n", $socialProfileUrls);

        if (!empty($socialServiceNamesStr)) {
            $customerCardText .= '*' . $socialServiceNamesStr . '*';
        }

        $customerCardText .= "\n " . EXTRA_EVENT_TEXTS_LEGACY['multipleContactsFound'][$slackServiceData['languageCode']];

        if (!empty($socialProfileUrlsStr)) {
            $customerCardText .= ": \n" . $socialProfileUrlsStr;
        }

        $textFromConfig['customerCard'] = $customerCardText;
        //endregion customerCard
    }
    //endregion ExternalUser

    //region RingoverUser
    $ringoverUserName = mentionSlackUserWithRingoverUserId(
        $slackTokenInfo,
        $slackServiceData,
        $callEntity->firstRingoverUser['id'],
        $callEntity->firstRingoverUser['email']
    ) ?? $callEntity->ringoverUserName;

    if ($callEntity->direction === CallDirection::IN) {
        $variableForTextReplacement['/:toName/'] = $ringoverUserName;
        $variableForTextReplacement['/:fromName/'] = $customerContactName;
    } else {
        $variableForTextReplacement['/:toName/'] = $customerContactName;
        $variableForTextReplacement['/:fromName/'] = $ringoverUserName;
    }
    //endregion RingoverUser

    $customerNumber = $callEntity->e164CustomerNumber;
    if (false !== strpos($customerNumber, 'anonymous')) {
        $customerNumber = ltrim($customerNumber, '+');
    }

    // Numbers and duration
    $variableForTextReplacement['/:fromNumberInE164/'] = $callEntity->direction == CallDirection::IN ? $customerNumber : $callEntity->e164RingoverNumber;
    $variableForTextReplacement['/:toNumberInE164/'] = $callEntity->direction == CallDirection::IN ? $callEntity->e164RingoverNumber : $customerNumber;
    $variableForTextReplacement['/:formatedDuration/'] = $callEntity->formattedDuration;

    // IVR calls
    if ($callEntity->isIVR) {
        $variableForTextReplacement['/:ivrName/'] = $callEntity->ivrName;
        $variableForTextReplacement['/:ivrNumberInE164/'] = $callEntity->ivrE164Number;
    }

    // Necessary replacement
    $variableForTextReplacement['/\(\)/'] = '';
    $variableForTextReplacement['/\*\s*\*/'] = '';

    // Remplacement des tags dans les textes
    $text = preg_replace(
        array_keys($variableForTextReplacement),
        array_values($variableForTextReplacement),
        $textFromConfig
    );

    return $text;
}

/**
 * Header block for IVR call
 * @param string $headerText
 * @param Call $callEntity
 * @param string $newStatus
 *
 * @return array
 */
function legacyCreateHeaderBlockForCall(string $headerText, Call $callEntity, string $newStatus): array
{
    $block = [
        'type'     => 'context',
        'elements' => [
            [
                'type' => 'mrkdwn',
                'text' => $headerText
            ]
        ],
        'block_id' => 'header'
    ];

    //region cdr ico

    $iconUrl = getHeaderIconForCallSlack($callEntity);

    if (!empty($iconUrl)) {
        $iconUrlPaths = explode('/', $iconUrl);
        // Insert ico to the 1st place of block
        array_unshift(
            $block['elements'],
            [
                'type'      => 'image',
                'image_url' => $iconUrl,
                'alt_text'  => substr(end($iconUrlPaths), 0, -4)
            ]
        );
    }
    //endregion

    return $block;
}

/**
 * Body block for IVR call
 *
 * @param string $ivrInfo
 * @param string $customerCard
 *
 * @return array
 */
function legacyCreateBodyBlockForCall(string $ivrInfo = '', string $customerCard = ''): array
{
    if (empty($ivrInfo) && empty($customerCard)) {
        return [];
    }

    $block = [
        'type'     => 'section',
        'fields'   => [],
        'block_id' => 'body'
    ];

    if (!empty($ivrInfo)) {
        $ivrBlock =
            [
                'type' => 'mrkdwn',
                'text' => $ivrInfo
            ];
        array_push($block['fields'], $ivrBlock);
    }
    if (!empty($customerCard)) {
        $customerCardBlock =
            [
                'type' => 'mrkdwn',
                'text' => $customerCard
            ];
        array_push($block['fields'], $customerCardBlock);
    }
    return $block;
}

/**
 * Action (Button) block for IVR call
 *
 * @param Call $callEntity
 * @param string $status
 * @param array $textArrayAction
 *
 * @return array
 */
function legacyCreateActionBlockForCall(Call $callEntity, string $status, $textArray): array
{
    $direction = $callEntity->direction;
    $record = $callEntity->recordFileLink;
    $customerNumber = $callEntity->e164CustomerNumber;
    $isAnonymous = false !== strpos($customerNumber, 'anonymous');

    $properties = [];

    switch ($direction) {
        case CallDirection::IN:
            switch ($status) {
                case 'ringing':
                    break;
                case 'missed':
                case 'hangup':
                case 'voicemail':
                    if (!$isAnonymous && isset($textArray['btnCall']) && !empty($textArray['btnCall'])) {
                        $properties[] = [
                            'text'      => $textArray['btnCall'],
                            'value'     => 'click_me_123',
                            'url'       => 'https://app.ringover.com/call/' . $customerNumber,
                            'action_id' => 'btn_call',
                            'style'     => 'primary'
                        ];
                    }
                    // btn listen record. For voicemail
                    if (!empty($record) && isset($textArray['btnListen']) && !empty($textArray['btnListen'])) {
                        $properties[] = [
                            'text'      => $textArray['btnListen'],
                            'value'     => 'click_me_123',
                            'url'       => $record,
                            'action_id' => 'btn_listen'
                        ];
                    }
                    break;
                default:
                    break;
            };
            break;
        case CallDirection::OUT:
            switch ($status) {
                case 'hangup':
                case 'voicemail':
                    if (!empty($record) && isset($textArray['btnListen']) && !empty($textArray['btnListen'])) {
                        $properties[] = [
                            'text'      => $textArray['btnListen'],
                            'value'     => 'click_me_123',
                            'url'       => $record,
                            'action_id' => 'btn_listen'
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
    if (empty($properties)) {
        return [];
    }

    // Construct empty block
    $block =
        [
            'type'     => 'actions',
            'elements' => [],
            'block_id' => 'action'
        ];

    // insert each btn to block
    foreach ($properties as $property) {
        $element = [
            'type'      => 'button',
            'text'      => [
                'type' => 'plain_text',
                'text' => $property['text']
            ],
            'value'     => $property['value'],
            'url'       => $property['url'],
            'action_id' => $property['action_id']
        ];

        if (isset($property['style'])) {
            $element['style'] = $property['style'];
        }

        array_push($block['elements'], $element);
    }

    return $block;
}

/**
 * Pre-footer block for IVR call
 *
 * @param array $textArray
 *
 * @return array
 */
function legacyrCreatePreFooterBlockForCall(array $textArray): array
{
    return [];

    // /!\ To be enabled for next version
    /*
    if (!isset($textArray['downloadApp']) || empty($textArray['downloadApp'])) {
        return [];
    }

    return
        [
            'type'     => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => $textArray['downloadApp']
                ]
            ],
            'block_id' => 'prefooter',
        ];
    */
}

/**
 * Construct tag and not into blocks, indexed
 *
 * @param Call $callEntity
 *
 * @return array block elements of tag and note in array
 */
function legacyCreateTagNoteBlock(Call $callEntity): array
{
    $aftercallElements = [];

    if (!empty($callEntity->tags)) {
        $aftercallElements['tags'] =
            [
                'type'     => 'context',
                'elements' => [
                    [
                        "type" => "plain_text",
                        "text" => 'Tags: ' . implode(' - ', $callEntity->tags)
                    ]
                ],
                'block_id' => 'tags'
            ];
    }
    if (!empty($callEntity->comments)) {
        $aftercallElements['notes'] =
            [
                'type'     => 'context',
                'elements' => [
                    [
                        "type" => "plain_text",
                        "text" => 'Notes: ' . $callEntity->comments
                    ]
                ],
                'block_id' => 'notes'
            ];
    }

    return $aftercallElements;
}

/**
 * Footer block for IVR call
 *
 * @param CommandQueryPDO $pdoHandler
 * @param Call $callEntity
 * @param string $newStatus
 * @param array $textArray
 *
 * @return array
 */
function legacyCreateFooterBlockForCall(
    CommandQueryPDO $pdoHandler,
    Call $callEntity,
    string $newStatus,
    array $textArray
): array {
    $direction = $callEntity->direction;

    $text = '';
    switch ($direction) {
        case CallDirection::IN:
            switch ($newStatus) {
                case 'hangup':
                    $text = $textArray['answeredBy'] ?? '';
                    break;
                default:
                    break;
            };
            break;
        default:
            break;
    }

    // Return if no text created
    if (empty($text)) {
        return [];
    }

    return
        [
            'type'     => 'context',
            'elements' => [
                [
                    'type' => 'mrkdwn',
                    'text' => $text
                ],
                [
                    'type' => 'mrkdwn',
                    'text' => '*' . $callEntity->ringoverUserName . '*'
                ]
            ],
            'block_id' => 'footer',
        ];
}

/**
 * Construct block structure for sms message.
 *
 * @param CommandQueryPDO $pdoHandler
 * @param SMS $smsEntity
 * @param array $slackServiceData
 * @param int $ringoverUserId
 * @param int $ringoverTeamId
 *
 * @return array
 */
function legacyCreateSMSMessageBlocks(
    CommandQueryPDO $pdoHandler,
    SMS $smsEntity,
    array $slackServiceData,
    int $ringoverUserId,
    int $ringoverTeamId
): array {
    $textArray = legacyCreateSlackSMSEventTextSlack(
        $pdoHandler,
        $smsEntity,
        $slackServiceData,
        $ringoverUserId,
        $ringoverTeamId
    );
    $block = [
        'text'        => $textArray['title'],
        'attachments' => [
            [
                'color'  => $textArray['color'],
                "blocks" => [
                    [
                        "type"     => "context",
                        "elements" => [
                            [
                                'type' => 'mrkdwn',
                                'text' => $textArray['header'],
                            ]
                        ],
                        'block_id' => "header"
                    ]
                    // Other block elements will be added here.
                ]
            ]
        ]
    ];

    // If showContentSms is defined and setted to true
    if (isset($slackServiceData['showContentSms']) && $slackServiceData['showContentSms']) {
        // Prepare message text. Max 200 chars.
        $msg = $smsEntity->body;
        if (200 < strlen($msg)) {
            $msg = substr($msg, 0, 200) . ' ...';
        }

        // Add new block.
        $block['attachments'][0]['blocks'][] = [
            'type'     => 'context',
            'elements' => [
                [
                    "type" => "mrkdwn",
                    "text" => $textArray['content'] . $msg
                ]
            ],
            'block_id' => "msg"
        ];
    }

    //region BodyBlock
    // If is IVR
    $ivrBlock =
        $smsEntity->conversationType !== SMS::COLLABORATIVE_CONVERSATION
            ? []
            : [
            'type' => 'mrkdwn',
            'text' => $textArray['ivrInfo']
        ];

    // If has customerCard
    $customerCardBlock =
        empty($textArray['customerCard'])
            ? []
            : [
            'type' => 'mrkdwn',
            'text' => $textArray['customerCard']
        ];

    if (!empty($ivrBlock) || !empty($customerCardBlock)) {
        $bodyBlock = [
            'block_id' => 'body',
            'type'     => 'section',
            'fields'   => []
        ];
        if (!empty($ivrBlock)) {
            array_push($bodyBlock['fields'], $ivrBlock);
        }
        if (!empty($customerCardBlock)) {
            array_push($bodyBlock['fields'], $customerCardBlock);
        }

        $block['attachments'][0]['blocks'][] = $bodyBlock;
    }
    //endregion

    return $block;
}
#endregion

//region Process telecom events
if (isset($callEntity) && !$callEntity->afterCall) {
    if('SLACK_QUICKTALK' == $integrationName){
        return processCallEventForSlackQuickTalk($pdoHandler, $callEntity, $contactManager);
    }
    return processCallEventForSlack($pdoHandler, $callEntity, $contactManager);
}

if (isset($callEntity) && $callEntity->afterCall) {
    if('SLACK_QUICKTALK' == $integrationName){
        return processAfterCallEventForSlackQuicktalk($callEntity, $contactManager);
    }
    return processAfterCallEventForSlack($callEntity, $contactManager);
}

if (isset($smsEntity)) {
    return processSMSEventForSlack($pdoHandler, $smsEntity, $contactManager);
}
//endregion
