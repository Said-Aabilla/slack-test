<?php
/**
 * Available variables
 * @var array $container Conteneur de dépendance
 * @var \DI\Container $containerDI
 * @var array $_COMMAND Paramètres passés dans l'url (format domain.tld/cle1/valeur1/cle2/valeur2)
 * @var \App\Domain\CallEvent\Call $callEntity
 * @var \App\Intrastructure\Persistence\CommandQueryPDO $pdoHandler
 * @var SMS $smsEntity
 * @var array $callEventText
 * @var array $usersInfos
 * @var array $currentRingoverUser
 * @var \App\Application\Logger\IntegrationLoggerInterface $logger
 * @var \Psr\Http\Message\ServerRequestInterface $request
 */

use App\Intrastructure\Persistence\CommandQueryPDO;

// slack pour quicktalk
define('SLACK_QUICKTALK_REDIRECT_URI', $container['settings']['integrations']['slack_quicktalk']['redirect_url_local']);
define('SLACK_QUICKTALK_CLIENT_ID', $container['settings']['integrations']['slack_quicktalk']['client_id']);
define('SLACK_QUICKTALK_CLIENT_SECRET', $container['settings']['integrations']['slack_quicktalk']['client_secret']);

// slack pour ro
define('SLACK_REDIRECT_URI', $container['settings']['integrations']['slack']['redirect_url']);
define('SLACK_CLIENT_ID', $container['settings']['integrations']['slack']['client_id']);
define('SLACK_CLIENT_SECRET', $container['settings']['integrations']['slack']['client_secret']);
define('SLACK_OAUTH_URL', 'https://slack.com/api/oauth.v2.access');
define('SLACK_AUTHORIZE_URL', 'https://slack.com/oauth/v2/authorize');
// define('SLACK_INVITE_USER_URL', 'https://slack.com/api/conversations.invite');
// define('SLACK_REMOVE_USER_URL', 'https://slack.com/api/conversations.kick');
define(
    'SLACK_COLORS',
    [
        'red'    => '#f04e4f',
        'orange' => '#ffb12a',
        'yellow' => '#ffe699',
        'green'  => '#00ddd0',
        'blue'   => '#037dfc'
    ]
);

$currentCrmName = 'SLACK';
$httpMethod = $request->getMethod();
$requestBody = $request->getParsedBody();
$scope = 'users:read,channels:read,users:read.email,chat:write,channels:history';

include_once dirname(__DIR__, 3) . '/Legacy/include_crm.php';

if (empty($currentUser)) {
    return;
}

if (empty($currentUser['team_id'])) {
    $logger->error('SLACK :: Missing user team');
    http_response_code(401);
    return;
}

//region Interactive response
// Ref: https://api.slack.com/apps/app_id/interactive-messages?
if ($httpMethod == 'POST' && 'interactive-endpoint' === $_COMMAND['slack']) {
    http_response_code(200);
    exit();
}
//endregion

//region Récupérer l'Url auth
if ($httpMethod == 'GET' && 'authurl' === $_COMMAND['slack']) {
    $params = [
        'client_id'    => SLACK_CLIENT_ID,
        'scope'        => $scope,
        'redirect_uri' => SLACK_REDIRECT_URI,
        'state'        => $_COMMAND['token']
    ];
    $url = SLACK_AUTHORIZE_URL . '?' . http_build_query($params);
    http_response_code(200);
    echo json_encode(['url' => $url]);
    exit();
}
//endregion

//region default configurations
$slackDefaultServiceData = array_merge(
    $integrationDefaultServiceData,
    [
        'enabled'                   => false,
        'internal'                  => 'on',
        'languageCode'              => 'en',
        'ringover_user_to_external' => ['users' => new stdClass()],
        'showContentSms'            => false,
        'showTagsNotes'             => false,
        'smsDirection'              => 'all',
        'callChannel'               => '',
        'smsChannel'                => '',
        'whatsappChannel'           => '',
        'records'                   => 'all'
    ]
);

// Remove unused params
unset($slackDefaultServiceData['contactCreationCondition']);
// Ne pas enregistre callEventTexts en base, à fin d'utiliser les fichiers i18n de Slack
$slackDefaultServiceData['callEventTexts'] = [];

$integrationDataArray = getIntegrationData($pdoHandler, 'SLACK', $currentUser['team_id']);

if(empty($integrationDataArray)){
    $integrationDataArray = getIntegrationData($pdoHandler, 'SLACK_QUICKTALK', $currentUser['team_id']);
}

if (!empty($integrationDataArray)) {
    $integrationData = current($integrationDataArray);
    $serviceData = json_decode($integrationData['service_data'], true);
} else {
    $serviceData = $slackDefaultServiceData;
}

//region Erreurs autorisation sur OAuth2
if ($httpMethod == 'GET' && isset($_GET['error'])) {
    if ('access_denied' === $_GET['error']) {
        http_response_code(401);
        echo json_encode(['error' => 'Autorisation : accès dénié.']);
        exit();
    }
}
//endregion

//region Nouvelle connexion Slack via OAuth2
if ('GET' === $httpMethod && isset($_GET['code'])) {

    $is_slack_quicktalk = isset($_GET['callback']) && $_GET['callback']='slack_quicktalk';

    if ($is_slack_quicktalk){
        $currentCrmName = 'SLACK_QUICKTALK';
        $serviceData['showNationalFormat'] = true;
        $serviceData['showIvrScenario'] = true;
        $serviceData['showCallSummaryRingover'] = true;
        $serviceData['empower'] = true;
        $serviceData['channels'] = [];
        unset($serviceData['ringover_user_to_external']);
        unset($serviceData['createTasksFromNextSteps']);
    }

    //region Demande du token d'accès avec du code d'auth
    $data = [
        'client_id'     => $is_slack_quicktalk ? SLACK_QUICKTALK_CLIENT_ID : SLACK_CLIENT_ID,
        'client_secret' => $is_slack_quicktalk ? SLACK_QUICKTALK_CLIENT_SECRET : SLACK_CLIENT_SECRET,
        'code'          => $_GET['code'],
        'redirect_uri'  => $is_slack_quicktalk ? SLACK_QUICKTALK_REDIRECT_URI : SLACK_REDIRECT_URI
    ];
    $encodedParams = http_build_query($data);
    $options = [
        'http' => [
            'protocol_version' => '1.1',
            'follow_redirect'  => true,
            'method'           => 'POST',
            'header'           => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'          => $encodedParams,
            'ignore_errors'    => true,
        ],
    ];
    $context = stream_context_create($options);
    $response = file_get_contents(SLACK_OAUTH_URL, false, $context);
    $responseArray = json_decode($response, true);

    // Erreur dans la réponse.
    if (!$response || !$responseArray['ok']) {
        $logger->error(
            $currentCrmName.' :: Impossible de récupérer le token',
            [
                'response' => $http_response_header,
                'error'    => $responseArray['error'] ?? $response,
                'teamId'   => $currentUser['team_id']
            ]
        );
        http_response_code(500);
        echo json_encode($responseArray['error'] ?? '');
        exit();
    }
    //endregion

    /**
     * Pour Slack, l'accessToken n'expire pas, donc il n'y a pas un refreshToken ni temps d'expiration
     */
    $accessToken = $responseArray['access_token'] ?? $responseArray['authed_user']['access_token'];
    $serviceData['enabled'] = true;
    $serviceData['botUseId'] = $responseArray['bot_user_id'];

    try {
        $pdoHandler->beginTransaction();
        /**
         * - If integration exists, update.
         * - Otherwise, insert new.
         */
        if (isset($integrationData)) {
            updateServiceDataSlack($pdoHandler, $integrationData['id'], $currentCrmName, $accessToken, $serviceData);
            $pdoHandler->commit();

            // Auto sync ringover - slack user map (if not quicktalk)
            if(!$is_slack_quicktalk){
                syncRingoverSlackUsers(
                    $pdoHandler, $currentUser['team_id'], $integrationData['id'],
                    $accessToken,
                    $serviceData,
                    $logger
                );
            }

            $logger->debug($currentCrmName.' :: Intégration MAJ.');
            http_response_code(200);
            echo json_encode(['oauth2TokenId' => $integrationData['id']]);
        } else {
            addIntegrationSlack($pdoHandler, $accessToken, $currentCrmName, $serviceData, $currentUser['team_id']);
            $oauth2TokenId = $pdoHandler->lastInsertId();
            $pdoHandler->commit();

            // Auto sync ringover - slack user map (if not quicktalk)
            if(!$is_slack_quicktalk){
                syncRingoverSlackUsers($pdoHandler, $currentUser['team_id'], $oauth2TokenId, $accessToken, $serviceData,
                    $logger);
            }

            $logger->debug(
                $currentCrmName.' :: Nouvelle intégration enregistrée.',
                ['teamId' => $currentUser['team_id']]
            );
            http_response_code(201);
            echo json_encode(['oauth2TokenId' => $oauth2TokenId]);
        }
    } catch (Exception $e) {
        $pdoHandler->rollBack();
        $logger->error(
            $currentCrmName.' :: Enregistrement impossible',
            [
                'error'  => $e->getMessage(),
                'teamId' => $currentUser['team_id']
            ]
        );
        http_response_code(500);
        exit();
    }
}
//endregion

//Aucun token en base donc utilisateur non connecté à Slack, on quitte le fichier
if (!isset($integrationData)) {
    return;
}

//region Supprimer integration
if ('DELETE' === $httpMethod) {
    $crmName = strtoupper(array_key_first($_COMMAND));
    try {
        $pdoHandler->beginTransaction();
        deleteCrmToken($pdoHandler, $integrationData['id']);
        $pdoHandler->commit();
        http_response_code(204);
    } catch (Exception $e) {
        $pdoHandler->rollBack();
        $logger->error(
            $crmName . ' :: Erreur suppression',
            [
                'error'  => $e->getMessage(),
                'teamId' => $currentUser['team_id']
            ]
        );
    }
    $logger->debug($crmName . ' :: intégration supprimée', ['teamId' => $currentUser['team_id']]);
    exit();
}
//endregion

//region Make automatic user mapping, on users have same email address
/**
 * Request url example: https://baseUrl/slack/sync-user-map
 * Return updated user mapping list
 */
if ('POST' === $httpMethod && 'sync-user-map' === $_COMMAND['slack']) {
    try {
        $result = syncRingoverSlackUsers(
            $pdoHandler,
            $currentUser['team_id'],
            $integrationData['id'],
            $integrationData['access_token'],
            $serviceData,
            $logger
        );
    } catch (Exception $e) {
        http_response_code(0 !== $e->getCode() ? $e->getCode() : 500);
        echo $e->getMessage();
        exit;
    }

    http_response_code(200);
    echo json_encode($result);
    exit;
}
//endregion

if ('GET' === $httpMethod && 'list-channels' === $_COMMAND['slack']) {
    $result = [];
    $cursor = '';

    while (empty($result) && empty($cursor) || !empty($result) && !empty($cursor)) {
        try {
            $responseArray = listSlackChannels(
                $integrationData['access_token'],
                $cursor
            );
        } catch (Exception $e) {
            $logger->debug('SLACK :: Error listing channels', ['error' => $e->getMessage()]);
            http_response_code(0 !== $e->getCode() ? $e->getCode() : 500);
            echo $e->getMessage();
            exit;
        }

        $result = array_merge($result, $responseArray['channels']);
        $cursor = $responseArray['next_cursor'];
    }

    $result = array_merge(['' => 'None'], $result);

    http_response_code(200);
    echo json_encode($result);
    exit;
}

//region Modification de la configuration de Slack
if ('POST' === $httpMethod && 'config' === $_COMMAND['slack']) {
    $validCallStatus = ['missed', 'incall', 'hangup', 'voicemail'];
    $validSmsConversationTypes = ['EXTERNAL', 'COLLABORATIVE'];

    // Validate languageCode
    if (isset($requestBody['languageCode'])) {
        if (!in_array($requestBody['languageCode'], \App\i18n\Translator::VALID_LANGUAGES)) {
            http_response_code('400');
            echo json_encode(['error' => 'Langue non valide :'. implode(',', \App\i18n\Translator::VALID_LANGUAGES)]);
            exit;
        }

        $serviceData['languageCode'] = $requestBody['languageCode'];
        unset($requestBody['languageCode']);
    }

    if (isset($requestBody['languageCode']) && in_array($requestBody['languageCode'], \App\i18n\Translator::VALID_LANGUAGES)) {
        $serviceData['languageCode'] = $requestBody['languageCode'];
    }

    if (isset($requestBody['callEventTexts'])) {
        $serviceData['callEventTexts'] = $requestBody['callEventTexts'];
    }

    if (isset($requestBody['internal']) && in_array($requestBody['internal'], ['on', 'off'])) {
        $serviceData['internal'] = $requestBody['internal'];
    }

    if (isset($requestBody['smsDirection'])) {
        $serviceData['smsDirection'] = $requestBody['smsDirection'];
    }

    if (isset($requestBody['callChannel'])) {
        $serviceData['callChannel'] = $requestBody['callChannel'];
    }

    if (isset($requestBody['smsChannel'])) {
        $serviceData['smsChannel'] = $requestBody['smsChannel'];
    }

    if (isset($requestBody['whastappChannel'])) {
        $serviceData['whastappChannel'] = $requestBody['whastappChannel'];
    }

    if (isset($requestBody['logOmnichannelEvent'])) {
        $serviceData['logOmnichannelEvent'] = $requestBody['logOmnichannelEvent'];
    }

    if (
        isset($requestBody['ringover_user_to_external']['users']) &&
        is_array($requestBody['ringover_user_to_external']['users'])
    ) {
        $serviceData['ringover_user_to_external']['users'] = $requestBody['ringover_user_to_external']['users'];
    }

    // Si showTagsNotes est défini
    if (isset($requestBody['showTagsNotes'])) {
        $serviceData['showTagsNotes'] = boolval($requestBody['showTagsNotes']);
    }

    // Si showContentSms est défini
    if (isset($requestBody['showContentSms'])) {
        $serviceData['showContentSms'] = boolval($requestBody['showContentSms']);
    }

    try {
        $pdoHandler->beginTransaction();
        updateServiceDataSlack(
            $pdoHandler,
            $integrationData['id'],
            'SLACK',
            $integrationData['access_token'],
            $serviceData
        );
        $pdoHandler->commit();

        $logger->debug(
            'SLACK :: Configuration est modifiée avec succès.',
            ['teamId' => $currentUser['team_id']]
        );
        http_response_code(200);
    } catch (Exception $e) {
        $pdoHandler->rollBack();
        $logger->error('SLACK :: Erreur MAJ :: ' . $e->getMessage());
        http_response_code(500);
        exit();
    }
}
//endregion


//region Modification de la configuration de SLACK_QUICKTALK
if ('POST' === $httpMethod &&  'config' === $_COMMAND['slack_quicktalk']) {


    if (isset($requestBody['languageCode']) && in_array($requestBody['languageCode'], \App\i18n\Translator::VALID_LANGUAGES)) {
        $serviceData['languageCode'] = $requestBody['languageCode'];
    }

    if (isset($requestBody['enabled']) && in_array($requestBody['enabled'], [true, false])) {
        $serviceData['enabled'] = $requestBody['enabled'];
    }

    if (isset($requestBody['showIvrScenario']) && in_array($requestBody['showIvrScenario'], [true, false])) {
        $serviceData['showIvrScenario'] = $requestBody['showIvrScenario'];
    }

    if (isset($requestBody['showTagsNotes']) && in_array($requestBody['showTagsNotes'], [true, false])) {
        $serviceData['showTagsNotes'] = $requestBody['showTagsNotes'];
    }

    if (isset($requestBody['channels'])) {
        $serviceData['channels'] = $requestBody['channels'];
    }

    try {
        $pdoHandler->beginTransaction();
        updateServiceDataSlack(
            $pdoHandler,
            $integrationData['id'],
            "SLACK_QUICKTALK",
            $integrationData['access_token'],
            $serviceData
        );
        $pdoHandler->commit();

        $logger->debug(
            'SLACK_QUICKTALK :: Configuration est modifiée avec succès.',
            ['teamId' => $currentUser['team_id']]
        );
        http_response_code(200);
    } catch (Exception $e) {
        $pdoHandler->rollBack();
        $logger->error('SLACK_QUICKTALK :: Erreur MAJ :: ' . $e->getMessage());
        http_response_code(500);
        exit();
    }
}
//endregion


//region Functions
/**
 * @return void
 * @var string $accessToken
 * @var string $serviceName
 * @var array $serviceData
 * @var int $teamId
 * @var CommandQueryPDO $pdo
 */
function addIntegrationSlack(
    CommandQueryPDO $pdo,
    string $accessToken,
    string $serviceName,
    array $serviceData,
    int $teamId
): void {
    $stmt =
        "INSERT INTO oauth2_tokens_services
            (access_token, service_name, service_data, team_id)
        VALUES (
            :accessToken,
            :serviceName,
            :serviceData,
            :teamId
        )";

    $pdo->prepare($stmt)->execute(
        [
            ':accessToken' => $accessToken,
            ':serviceName' => $serviceName,
            ':serviceData' => json_encode($serviceData),
            ':teamId'      => $teamId,
        ]
    );
}

/**
 * Pour un lien webhook, mettre à jour seulement `service_data`
 * @return void
 * @var string $teamId
 * @var string $serviceName
 * @var string $accessToken
 * @var array $serviceData
 * @var CommandQueryPDO $pdo
 */
function updateServiceDataSlack(
    CommandQueryPDO $pdo,
    int $id,
    string $serviceName,
    string $accessToken,
    array $serviceData
): void {
    $sql = "UPDATE oauth2_tokens_services
            SET service_data = :serviceData,
            access_token = :accessToken
            WHERE id = :id
            AND service_name = :serviceName";

    $params = [
        ':id'          => $id,
        ':serviceName' => $serviceName,
        ':accessToken' => $accessToken,
        ':serviceData' => json_encode($serviceData)
    ];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * Synchronize ringover and slack users, complete user map.
 * And save new user mapping list into serviceData
 *
 * @param CommandQueryPDO $pdoHandler
 * @param int $teamId
 * @param int $integrationId
 * @param string $accessToken
 * @param array $serviceData
 * @param mixed $logger
 *
 * @return stdClass
 */
function syncRingoverSlackUsers(
    CommandQueryPDO $pdoHandler,
    int $teamId,
    int $integrationId,
    string $accessToken,
    array $serviceData,
    $logger
): array {
    try {
        // Get auto-mapping user list
        $result = autoMapRingoverSlackUsers(
            $pdoHandler,
            $teamId,
            $accessToken,
            json_decode(json_encode($serviceData['ringover_user_to_external']['users']), true)
        );
        // re-initiate ringover_user_to_external if exists.
        $serviceData['ringover_user_to_external']['users'] = $result;
        updateServiceDataSlack($pdoHandler, $integrationId, 'SLACK', $accessToken, $serviceData);
        $logger->debug('SLACK :: sync user map done');
    } catch (Exception $e) {
        $logger->debug('SLACK :: sync user map : ' . $e->getMessage());
        throw $e;
    }

    return $result;
}

/**
 * Map users Ringover - Slack, on same email address
 * Exclude already mapped ones.
 *
 * @param CommandQueryPDO $pdoHandler
 * @param int $teamId
 * @param string $accessToken
 * @param array $userMapList value of: ringover_user_to_external['users']
 *
 * @return array user mapping list
 */
function autoMapRingoverSlackUsers(
    CommandQueryPDO $pdoHandler,
    int $teamId,
    string $accessToken,
    array $userMapList
): array {
    //region Get Ringover and external users
    // Ringover users
    $ringoverUsers = getRingoverUsersByTeam($pdoHandler, $teamId);

    // Slack users
    $externalUsers = [];
    $limit = 0;
    $cursor = '';
    do {
        $rawExternalUsers = getSlackUsers($accessToken, $limit, $cursor);
        $cursor = $rawExternalUsers['nextCursor'] ?? '';
        $externalUsers = array_merge($externalUsers, $rawExternalUsers['members']);
    } while (!empty($cursor));
    //endregion

    //region Extract mapped user IDs, for Ringover and external
    $mappedRingoverUsers = [];
    $mappedExternalUsers = [];

    if (!empty($userMapList)) {
        foreach ($userMapList as $ringoverUserId => $externalInfo) {
            $externalUserId = $externalInfo['externalId'] ?? '';
            if (empty($externalUserId)) {
                continue;
            }
            $mappedRingoverUsers[] = $ringoverUserId;
            $mappedExternalUsers[] = $externalUserId;
        }
    }
    //endregion

    //region Prepare available users for Ringover and external
    $availableRingoverUsers = [];
    $availableExternalUsers = [];

    /**
     * Loop full user list,
     * skip if user id presents in mapped user list.
     * Form arrays with email and id: ['email' => 'id']
     */
    foreach ($ringoverUsers as $rUser) {
        if (in_array($rUser['id'], $mappedRingoverUsers)) {
            continue;
        }

        $availableRingoverUsers[$rUser['email']] = $rUser['id'];
    }

    foreach ($externalUsers as $eUser) {
        if (in_array($eUser['id'], $mappedExternalUsers)) {
            continue;
        }

        $availableExternalUsers[$eUser['email']] = $eUser['id'];
    }
    //endregion

    // Early return, if no available Ringover users to match
    if (empty($availableRingoverUsers)) {
        return $userMapList;
    }

    // Loop available ringover users
    foreach ($availableRingoverUsers as $email => $id) {
        // If external user has the same email address
        if (isset($availableExternalUsers[$email])) {
            // Insert new user mapping pairs
            $userMapList[$id] =
                [
                    'externalId' => $availableExternalUsers[$email],
                    'enabled'    => true
                ];
        }
    }

    return $userMapList;
}

/**
 * @param string $accessToken
 * @param int $limit
 * @param string $cursor
 *
 * @return array ['members' => ['id', 'email', 'fullName', 'photo' ], 'nextCursor' => '']
 */
function getSlackUsers(string $accessToken, int $limit = 0, string $cursor = ''): array
{
    $rawResult = slackApiListUsers($accessToken, $limit, $cursor);
    $members = $rawResult['members'];
    $result = [];
    if (empty($members)) {
        return $result;
    }

    foreach ($members as $member) {
        if ($member['deleted'] || $member['is_bot'] || !isset($member['profile']['email'])) {
            continue;
        }

        $result['members'][] = [
            'id'       => $member['id'],
            'email'    => $member['profile']['email'],
            'fullName' => $member['profile']['real_name'],
            'photo'    => $member['profile']['image_32']
        ];
    }

    if (!empty($rawResult['response_metadata']['next_cursor'])) {
        $result['nextCursor'] = $rawResult['response_metadata']['next_cursor'];
    }

    return $result;
}

/**
 * List slack users by calling Slack api
 * Ref: https://api.slack.com/methods/users.list
 * @param string $accessToken
 * @param int $limit
 * @param string $cursor
 *
 * @return array Raw result in array
 * @throws Exception
 */
function slackApiListUsers(string $accessToken, int $limit = 0, string $cursor = ''): array
{
    $endpoint = 'https://slack.com/api/users.list';

    $params = [];
    if (0 < $limit) {
        $params['limit'] = $limit;
    }
    if (!empty($cursor)) {
        $params['cursor'] = $cursor;
    }

    $queryParams = http_build_query($params);
    if (!empty($queryParams)) {
        $endpoint .= '?' . $queryParams;
    }

    $headers =
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/x-www-form-urlencoded'
        ];

    /** @var false|stdClass $result */
    $result = genericCallIntegrationApi(
        'SLACK',
        'GET',
        $endpoint,
        '',
        $headers,
        $httpReturnCode
    );

    if (is_bool($result) && !$result) {
        throw new Exception('SLACK : Error listing users', $httpReturnCode);
    }

    if (false === $result->ok) {
        throw new Exception('SLACK : ' . $result->error, $httpReturnCode);
    }

    return json_decode(json_encode($result), true);
}

/**
 * Lister les canaux par page.
 * Pour lister que les channels publiques : type = public_channel
 * @param string $accessToken
 * @param string $cursor
 * @return array [channels[{id: name}], next_cursor]
 * @throws Exception
 */
function listSlackChannels(string $accessToken, string $cursor): array
{
    $endpoint = 'https://slack.com/api/conversations.list';

    $params = [
        'exclude_archived' => true,
        'limit'            => 999
    ];
    if (!empty($cursor)) {
        $params['cursor'] = $cursor;
    }

    $queryParams = http_build_query($params);
    if (!empty($queryParams)) {
        $endpoint .= '?' . $queryParams;
    }

    $headers = ['Authorization: Bearer ' . $accessToken];

    /** @var false|stdClass $response */
    $response = genericCallIntegrationApi(
        'SLACK',
        'GET',
        $endpoint,
        '',
        $headers,
        $httpReturnCode
    );

    if (is_bool($response) && !$response) {
        throw new Exception('SLACK : Error listing channels', $httpReturnCode);
    }

    if (false === $response->ok) {
        throw new Exception('SLACK : ' . $response->error, $httpReturnCode);
    }

    $resultArray = json_decode(json_encode($response), true);
    $result = ['channels' => [], 'next_cursor' => $resultArray['response_metadata']['next_cursor'] ?? ''];

    foreach ($resultArray['channels'] as $channel) {
        $result['channels'][$channel['id']] = ($channel['is_private'] ? '' : '# ') . $channel['name'];
    }

    return $result;

}
//endregion
