<?php

namespace App\Intrastructure\Persistence;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\DateTime\CustomFormat;
use App\Domain\Integration\AliasMapper;
use App\Domain\Integration\Integration;
use App\Domain\Integration\UserTokenInfos;
use DateInterval;
use DateTime;
use DateTimeZone;
use Exception;
use League\OAuth2\Client\Token\AccessTokenInterface;
use PDO;
use PDOException;
use PDOStatement;

class IntegrationRepository extends AbstractPDORepository
{
    private AliasMapper $aliasMapper;

    public function __construct(
        CommandQueryPDO            $pdoHandler,
        IntegrationLoggerInterface $logger,
        AliasMapper                $aliasMapper
    ) {
        parent::__construct($pdoHandler, $logger);
        $this->aliasMapper = $aliasMapper;
    }

    /**
     * @throws Exception
     */
    private function rawIntegrationToObject(array $rawIntegration): Integration
    {
        $integration = new Integration(
            $this->aliasMapper->getRealIntegrationName($rawIntegration['service_name']),
            $rawIntegration['id'],
            $this->aliasMapper->getIntegrationAlias($rawIntegration['service_name'])
        );
        $integration->attachToRingoverClient($rawIntegration['team_id'], $rawIntegration['user_id'] ?? null);

        $expirationTokenDateTime = new DateTime(
            $rawIntegration['expiration_date'] ?? 'now',
            new DateTimeZone(CustomFormat::DATE_TIMEZONE_EUROPE_PARIS)
        );

        $integration->setInstanceUrl($rawIntegration['instance_url']);
        $integration->setServiceUser($rawIntegration['service_user']);
        $integration->setConfiguration(json_decode($rawIntegration['service_data'], true) ?? []);

        $integration->setToken(
            $rawIntegration['access_token'],
            $rawIntegration['refresh_token'],
            $expirationTokenDateTime,
            $rawIntegration['expiration_date'] ?? 'now'
        );
        return $integration;
    }

    /*
     * Insérer une nouvelle intégration
     * @param int $teamId Identifiant de la team à qui appartient l'intégration
     * @param string $serviceName
     * @param string $token
     * @param string $instanceUrl
     * @param array $serviceData
     */
    public function insertNewIntegration(
        int    $teamId,
        string $serviceName,
        string $token,
        string $instanceUrl,
        array  $serviceData,
        string $serviceUser = ''
    ): bool {
        $createIntegrationStatement = $this->pdoHandler->prepare(
            "INSERT INTO oauth2_tokens_services (access_token, instance_url, service_user ,expiration_date,
                                    service_data, service_name, team_id)
                VALUES (
                    :accessToken,
                    :instanceUrl,
                    :service_user,
                    null,
                    :serviceData,
                    :serviceName,
                    :teamId)"
        );
        $params = [
            'accessToken'  => $token,
            'instanceUrl'  => $instanceUrl,
            'service_user' => $serviceUser,
            'serviceData'  => json_encode($serviceData),
            'serviceName'  => $serviceName,
            'teamId'       => $teamId
        ];

        try {
            return $createIntegrationStatement->execute($params);
        } catch (PDOException $exception) {
            $this->logger->alert(
                'INTEGRATIONS :: Impossible de créer l\'integration',
                [
                    'exception'    => $exception,
                    'service_name' => $serviceName
                ]
            );
            return false;
        }
    }

    /**
     * Mise à jour de l'intégration
     * @param string $teamId
     * @param string $serviceName
     * @param string $token
     * @param array $serviceData
     * @param string $serviceUser
     * @return bool
     */
    public function updateIntegration(
        string $teamId,
        string $serviceName,
        string $token,
        array  $serviceData,
        string $serviceUser = ''
    ): bool {
        $sql = "
        UPDATE oauth2_tokens_services
        SET service_data = :serviceData,
            access_token = :token,
            service_user = :service_user
        WHERE team_id = :teamId AND service_name = :serviceName";

        try {
            $stmt = $this->pdoHandler->prepare($sql);
            $stmt->execute(
                [
                    'serviceData'  => json_encode($serviceData),
                    'teamId'       => $teamId,
                    'serviceName'  => $serviceName,
                    'token'        => $token,
                    'service_user' => $serviceUser
                ]
            );

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $this->logger->error('Erreur lors de la mise à jour de l\'intégration : ' . $e->getMessage());
            return false;
        }
    }


    /**
     * @param Integration $newIntegration
     * @return int|false New ID. False: failed
     */
    public function createNewIntegration(Integration $newIntegration)
    {
        /*
         * En base de données le nom de l'intégration est celle de l'alias s'il existe.
         * Sinon c'est le nom de l'intégration générique
         */
        if (!empty($newIntegration->getIntegrationAlias())) {
            $integrationNameInDB = $newIntegration->getIntegrationAlias();
        } else {
            $integrationNameInDB = $newIntegration->getIntegrationName();
        }

        $createIntegrationStatement = $this->pdoHandler->prepare(
            "INSERT INTO oauth2_tokens_services (
                    refresh_token,
                    access_token,
                    instance_url,
                    expiration_date,
                    service_user,
                    service_name,
                    service_data,
                    user_id,
                    team_id
                )
            VALUES (
                    :refreshToken,
                    :accessToken,
                    :instanceUrl,
                    :expirationDate,
                    :serviceUser,
                    :serviceName,
                    :serviceData,
                    :userId,
                    :teamId
                )"
        );

        $createIntegrationStatement->bindValue('refreshToken', $newIntegration->getRefreshToken());
        $createIntegrationStatement->bindValue('accessToken', $newIntegration->getAccessToken());
        $createIntegrationStatement->bindValue('instanceUrl', $newIntegration->getInstanceUrl());
        $createIntegrationStatement->bindValue(
            'expirationDate',
            $newIntegration->getTokenExpirationDateTime()->format(CustomFormat::DATE_ANSI_SQL)
        );
        $createIntegrationStatement->bindValue('serviceUser', $newIntegration->getServiceUser());
        $createIntegrationStatement->bindValue('serviceName', $integrationNameInDB);
        $createIntegrationStatement->bindValue('serviceData', json_encode($newIntegration->getConfiguration()));
        $createIntegrationStatement->bindValue('userId', $newIntegration->getUserId(), PDO::PARAM_INT);
        $createIntegrationStatement->bindValue('teamId', $newIntegration->getTeamId(), PDO::PARAM_INT);

        try {
            $this->pdoHandler->beginTransaction();
            $insertResult = $createIntegrationStatement->execute();
            $newId = $this->pdoHandler->lastInsertId();
            $this->pdoHandler->commit();
            return !$insertResult ? false : $newId;
        } catch (PDOException $exception) {
            $this->logger->alert(
                'INTEGRATIONS :: Impossible de créer l\'integration',
                [
                    'exception'    => $exception,
                    'service_name' => $newIntegration->getServiceClassName()
                ]
            );
            return false;
        }
    }

    public function reconnectUpdateIntegration(Integration $integration): bool
    {
        $updateIntegrationStmt = $this->pdoHandler->prepare(
            "UPDATE
                oauth2_tokens_services
            SET
                refresh_token = :refreshToken,
                access_token = :accessToken,
                instance_url = :instanceUrl,
                expiration_date = :expirationDate,
                service_data = :serviceData,
                service_user = :serviceUser
            WHERE
                id = :integrationId"
        );

        $updateIntegrationStmt->bindValue('integrationId', $integration->getId());
        $updateIntegrationStmt->bindValue('refreshToken', $integration->getRefreshToken());
        $updateIntegrationStmt->bindValue('accessToken', $integration->getAccessToken());
        $updateIntegrationStmt->bindValue('instanceUrl', $integration->getInstanceUrl());
        $updateIntegrationStmt->bindValue(
            'expirationDate',
            $integration->getTokenExpirationDateTime()->format(CustomFormat::DATE_ANSI_SQL)
        );
        $updateIntegrationStmt->bindValue('serviceData', json_encode($integration->getConfiguration()));
        $updateIntegrationStmt->bindValue('serviceUser', $integration->getServiceUser());

        try {
            return $updateIntegrationStmt->execute();
        } catch (PDOException $exception) {
            $this->logger->alert(
                'INTEGRATIONS :: Impossible de mettre à jour l\'integration',
                [
                    'exception'      => $exception,
                    'integration_id' => $integration->getId()
                ]
            );
            return false;
        }
    }

    /**
     * @param array $users Liste d'utilisateur ringover
     * @param array $filterServiceByName
     * @return array Retourne la liste de token de l'utilisateur concerné
     */
    public function getUsersTokensInfo(array $users, array $filterServiceByName = []): array
    {
        if (empty($users)) {
            return [];
        }

        $sqlInFilter = [];
        foreach ($users as $user) {
            $teamId = $user['team_id'];
            $sqlInFilter[] = $this->pdoHandler->quote($user['id']);
        }
        $sqlInFilter = implode(',', $sqlInFilter);

        $getUserTokensInfoStmt = $this->pdoHandler->prepare(
            "
    SELECT
      ots_user.id,
      ots_user.instance_url,
      ots_user.refresh_token,
      ots_user.access_token,
      ots_user.creation_date,
      ots_user.expiration_date,
      ots_user.service_user,
      ots_user.service_name,
      ots_user.user_id,
      ots_user.service_data as user_service_data,
      ots_team.service_data as team_service_data
    FROM
      oauth2_tokens_services ots_user LEFT JOIN
      oauth2_tokens_services ots_team ON (
        ots_user.service_name = ots_team.service_name AND
        ots_team.user_id IS NULL AND
        ots_team.team_id = :teamId
      )
    WHERE
      ots_user.user_id IN ($sqlInFilter) OR
      (ots_user.team_id = :teamId AND ots_user.access_token != '')"
        );

        try {
            $getUserTokensInfoStmt->execute(['teamId' => $teamId]);
        } catch (PDOException $exception) {
            $this->logger->alert(
                'INTEGRATIONS :: Impossible de récupérer les informations des utilisateurs concernés par l\'appel'
            );
            $this->logger->alert($exception);
            return [];
        }

        $userByIntegrations = [];
        while ($userTokenInfoRow = $getUserTokensInfoStmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($filterServiceByName) && !in_array(
                    strtoupper($userTokenInfoRow['service_name']),
                    $filterServiceByName
                )) {
                continue;
            }

            if (!empty($userTokenInfoRow['team_service_data']) && !empty($userTokenInfoRow['user_id'])) {
                $finalServiceData = array_merge(
                    json_decode($userTokenInfoRow['user_service_data'], true),
                    json_decode($userTokenInfoRow['team_service_data'], true)
                );
            } elseif (!empty($userTokenInfoRow['team_service_data'])) {
                $finalServiceData = json_decode($userTokenInfoRow['team_service_data'], true);
            } else {
                $finalServiceData = json_decode($userTokenInfoRow['user_service_data'], true);
            }

            $userTokenInfo = new UserTokenInfos();

            $userTokenInfo->id = $userTokenInfoRow['id'];
            $userTokenInfo->serviceName = $this->aliasMapper->getRealIntegrationName(
                $userTokenInfoRow['service_name']
            );
            $userTokenInfo->serviceAliasName = $this->aliasMapper->getIntegrationAlias(
                $userTokenInfoRow['service_name']
            );
            $userTokenInfo->accessToken = $userTokenInfoRow['access_token'];
            $userTokenInfo->refreshToken = $userTokenInfoRow['refresh_token'];
            $userTokenInfo->instanceUrl = $userTokenInfoRow['instance_url'];
            $userTokenInfo->expirationDate = $userTokenInfoRow['expiration_date'];
            $userTokenInfo->creationDate = $userTokenInfoRow['creation_date'];

            $userTokenInfo->userServiceData = json_decode($userTokenInfoRow['user_service_data']);
            $userTokenInfo->serviceUser = $userTokenInfoRow['service_user'];
            $userTokenInfo->serviceData = json_decode(json_encode($finalServiceData));
            $userTokenInfo->serviceDataArr = $finalServiceData;

            $userTokenInfo->userId = $userTokenInfoRow['user_id'];
            $userTokenInfo->teamId = $teamId;

            $userByIntegrations[$userTokenInfoRow['service_name']][$userTokenInfo->userId] = $userTokenInfo;
        }

        return $userByIntegrations;
    }


    /**
     * Retourne la liste des intégrations, pour une team donnée, avec toutes les informations de connexion et de
     * configuration
     * @param int $teamId Identifiant de la team concernée par la récupération des intégrations
     * @param array $filterServiceByName Liste blanche d'intégration à récupérer (par le nom)
     * @return Integration[] Liste des intégrations, pour la team donnée, avec toutes les informations de connexion et
     * de configuration
     * @throws Exception
     */
    public function getTeamIntegrationsData(
        int   $teamId,
        array $filterServiceByName = [],
        bool  $returnIntegrationObject = false
    ): array {
        $filterServiceByName = array_map('strtoupper', $filterServiceByName);

        $getTeamIntegrationsInfoStmt = $this->pdoHandler->prepare(
            "SELECT
            ots_team.id,
            ots_team.instance_url,
            ots_team.refresh_token,
            ots_team.access_token,
            ots_team.creation_date,
            ots_team.expiration_date,
            ots_team.service_user,
            ots_team.service_name,
            ots_team.service_data,
            ots_team.team_id
        FROM
            oauth2_tokens_services ots_team
        WHERE
            ots_team.team_id = :teamId AND ots_team.user_id IS NULL AND ots_team.service_name IS NOT NULL"
        );

        try {
            $getTeamIntegrationsInfoStmt->execute(['teamId' => $teamId]);
        } catch (PDOException $exception) {
            $this->logger->alert(
                'INTEGRATIONS :: Impossible de récupérer les informations des utilisateurs concernés par l\'appel'
            );
            $this->logger->alert($exception);
            return [];
        }

        $teamIntegrations = [];
        while ($teamIntegrationInfoRow = $getTeamIntegrationsInfoStmt->fetch(PDO::FETCH_ASSOC)) {
            $currentServiceName = strtoupper($teamIntegrationInfoRow['service_name']);
            if (
                !empty($filterServiceByName) &&
                !in_array(
                    $currentServiceName,
                    $filterServiceByName
                )
            ) {
                continue;
            }

            if ($returnIntegrationObject === false) {
                if (
                    isset($teamIntegrationInfoRow['user_service_data'])
                    && !empty($teamIntegrationInfoRow['user_service_data'])
                ) {
                    $serviceData = json_decode($teamIntegrationInfoRow['user_service_data'], true);
                } else {
                    $serviceData = json_decode($teamIntegrationInfoRow['service_data'], true);
                }

                $integrationInfo = new UserTokenInfos();
                $integrationInfo->id = $teamIntegrationInfoRow['id'];
                $integrationInfo->serviceName = $this->aliasMapper->getRealIntegrationName(
                    $teamIntegrationInfoRow['service_name']
                );

                $integrationInfo->accessToken = $teamIntegrationInfoRow['access_token'];
                $integrationInfo->refreshToken = $teamIntegrationInfoRow['refresh_token'];
                $integrationInfo->instanceUrl = $teamIntegrationInfoRow['instance_url'];
                $integrationInfo->expirationDate = $teamIntegrationInfoRow['expiration_date'];
                $integrationInfo->creationDate = $teamIntegrationInfoRow['creation_date'];
                $integrationInfo->serviceUser = $teamIntegrationInfoRow['service_user'];
                $integrationInfo->teamId = $teamIntegrationInfoRow['team_id'];
                $integrationInfo->serviceData = $serviceData;
                $integrationInfo->serviceDataArr = $serviceData;

                $teamIntegrations[$teamIntegrationInfoRow['service_name']] = $integrationInfo;
            } else {
                $integrationInstance = $this->rawIntegrationToObject($teamIntegrationInfoRow);
                $teamIntegrations[$teamIntegrationInfoRow['service_name']] = $integrationInstance;
            }
        }

        /**
         * On renvoie le tableau ordonné en fonction des intégrations demandées si le paramètre est non vide
         */
        if (!empty($filterServiceByName)) {
            $filteredTeamIntegrations = [];
            foreach ($filterServiceByName as $serviceName) {
                $serviceName = strtoupper($serviceName);
                if (empty($teamIntegrations[$serviceName])) {
                    continue;
                }

                $filteredTeamIntegrations[$serviceName] = $teamIntegrations[$serviceName];
            }
            return $filteredTeamIntegrations;
        }

        return $teamIntegrations;
    }


    /**
     * @param string $integrationName
     * @param array $usersId
     *
     * @return UserTokenInfos[]
     */
    public function getUsersIntegrationData(string $integrationName, array $usersId): array
    {
        $usersIdPartRequest = implode(',', $usersId);

        $getUsersIntegrationInfoStmt = $this->pdoHandler->prepare(
            "
    SELECT
      ots_user.id,
      ots_user.instance_url,
      ots_user.refresh_token,
      ots_user.access_token,
      ots_user.creation_date,
      ots_user.expiration_date,
      ots_user.service_user,
      ots_user.service_name,
      ots_user.user_id,
      ots_user.service_data as user_service_data,
      ots_user.team_id
    FROM
      oauth2_tokens_services ots_user
    WHERE
      ots_user.user_id IN ($usersIdPartRequest) AND
      ots_user.service_name = :integrationName"
        );

        try {
            $getUsersIntegrationInfoStmt->execute(['integrationName' => $integrationName]);
        } catch (PDOException $exception) {
            $this->logger->alert(
                'INTEGRATIONS :: Impossible de récupérer les informations des utilisateurs concernés par l\'appel'
            );
            $this->logger->alert($exception);
            throw $exception;
        }

        $usersIntegration = [];
        while ($userIntegrationInfoRow = $getUsersIntegrationInfoStmt->fetch(PDO::FETCH_ASSOC)) {
            if (
                isset($userIntegrationInfoRow['user_service_data'])
                && !empty($userIntegrationInfoRow['user_service_data'])
            ) {
                $serviceData = json_decode($userIntegrationInfoRow['user_service_data'], true);
            } else {
                $serviceData = json_decode($userIntegrationInfoRow['service_data'], true);
            }

            $integrationInfo = new UserTokenInfos();
            $integrationInfo->id = $userIntegrationInfoRow['id'];
            $integrationInfo->serviceName = $this->aliasMapper->getRealIntegrationName(
                $userIntegrationInfoRow['service_name']
            );
            $integrationInfo->accessToken = $userIntegrationInfoRow['access_token'];
            $integrationInfo->refreshToken = $userIntegrationInfoRow['refresh_token'];
            $integrationInfo->instanceUrl = $userIntegrationInfoRow['instance_url'];
            $integrationInfo->expirationDate = $userIntegrationInfoRow['expiration_date'];
            $integrationInfo->creationDate = $userIntegrationInfoRow['creation_date'];
            $integrationInfo->serviceUser = $userIntegrationInfoRow['service_user'];
            $integrationInfo->teamId = $userIntegrationInfoRow['team_id'];
            $integrationInfo->serviceData = $serviceData;

            $usersIntegration[$userIntegrationInfoRow['user_id']] = $integrationInfo;
        }

        return $usersIntegration;
    }


    public function getLastIntegrationCallObjectByContactNumber(
        int    $teamId,
        int    $e164ContactNumber,
        string $integrationName
    ): array {
        $oldestInterval = new DateInterval('PT1M');
        $currentDate = new DateTime(
            'now',
            new DateTimeZone(CustomFormat::DATE_TIMEZONE_EUROPE_PARIS)
        );
        $getIntegrationCallObjectParams = [
            'oldest_call_start_time' => $currentDate->sub($oldestInterval)->format('Y-m-d H:i:s'),
            'team_id'                => $teamId,
            'contact_number'         => $e164ContactNumber
        ];

        $integrationNameRestriction = "";
        if (!empty($integrationName)) {
            $integrationNameRestriction = " AND integration_name = :integration_name";
            $getIntegrationCallObjectParams['integration_name'] = $integrationName;
        }

        $getIntegrationCallObjectRequest = "
    SELECT
        ico.id,
        integration_name,
        object_data
    FROM
        cdr JOIN
        integrations_call_object_history ico ON (cdr.team_id = ico.team_id AND cdr.call_id = ico.call_id)
    WHERE
        cdr.team_id = :team_id AND
        cdr.call_start_time > :oldest_call_start_time AND
        (
            cdr.onumber = :contact_number OR
            cdr.anumber = :contact_number
        )
        $integrationNameRestriction
    ";

        return $this->getIntegrationCallObject($getIntegrationCallObjectRequest, $getIntegrationCallObjectParams);
    }

    /**
     * Récupération de la liste des objets ajoutés dans les intégrations pour un identifiant d'appel donné
     * @param string $callId Identifiant de l'appel
     * @param string $channelId Identifiant de l'appel
     * @param int $teamId Identifiant de la team concerné par l'objet
     * @param string $integrationName Nom de l'intégration ciblé si besoin
     * @return null|array
     */
    public function getIntegrationCallObjectByCallId(
        string $callId,
        string $channelId,
        int    $teamId,
        string $integrationName = ''
    ): ?array {
        $getIntegrationCallObjectParams = [
            'call_id'    => $callId,
            'channel_id' => $channelId,
            'team_id'    => $teamId
        ];

        $integrationNameRestriction = "";
        if (!empty($integrationName)) {
            $integrationNameRestriction = " AND integration_name = :integration_name";
            $getIntegrationCallObjectParams['integration_name'] = $integrationName;
        }

        $getIntegrationCallObjectRequest = "
    SELECT
        id,
        integration_name,
        object_data
    FROM
        integrations_call_object_history
    WHERE
        call_id = :call_id AND
        channel_id = :channel_id AND
        team_id = :team_id
        $integrationNameRestriction
    ";

        return $this->getIntegrationCallObject($getIntegrationCallObjectRequest, $getIntegrationCallObjectParams);
    }

    public function getIntegrationCallObjectByChannelId(
        string $channelId,
        int    $teamId,
        string $integrationName = ''
    ): ?array {
        $getIntegrationCallObjectParams = [
            'channel_id' => $channelId,
            'team_id'    => $teamId
        ];

        $integrationNameRestriction = "";
        if (!empty($integrationName)) {
            $integrationNameRestriction = " AND integration_name = :integration_name";
            $getIntegrationCallObjectParams['integration_name'] = $integrationName;
        }

        $getIntegrationCallObjectRequest = "
    SELECT
        id,
        integration_name,
        object_data
    FROM
        integrations_call_object_history
    WHERE
        channel_id = :channel_id AND
        team_id = :team_id
        $integrationNameRestriction
    ";

        return $this->getIntegrationCallObject($getIntegrationCallObjectRequest, $getIntegrationCallObjectParams);
    }

    private function getIntegrationCallObject(
        string $getIntegrationCallObjectRequest,
        array  $getIntegrationCallObjectParams
    ): array {
        $getIntegrationCallObjectStmt = $this->pdoHandler->prepare($getIntegrationCallObjectRequest);
        $getIntegrationCallObjectStmt->execute($getIntegrationCallObjectParams);

        $objectList = [];
        while ($currentObjectRaw = $getIntegrationCallObjectStmt->fetch(PDO::FETCH_ASSOC)) {
            $objectList[$currentObjectRaw['integration_name']][] = [
                'id'         => $currentObjectRaw['id'],
                'objectData' => json_decode($currentObjectRaw['object_data'], true)
            ];
        }

        return $objectList;
    }


    /**
     * Sauvegarde des informations utiles de l'objet créé sur un outil métier
     * @param string $integrationName Nom de l'outil sur lequel a été créé l'objet
     * @param string $callId Identifiant de l'appel représenté par l'objet
     * @param int $teamId Identifiant de la team à qui appartient l'intégration
     * @param array $objectData Données utiles de l'objet créé
     * @return void
     */
    public function saveIntegrationCallObject(
        string $integrationName,
        string $callId,
        string $channelId,
        int    $teamId,
        array  $objectData
    ) {
        $insertIntegrationCallObjectStmt = $this->pdoHandler->prepare(
            "
    INSERT INTO integrations_call_object_history(integration_name, call_id, channel_id, team_id, object_data)
    VALUES(:integration_name, :call_id, :channel_id, :team_id, :object_data)"
        );

        $insertIntegrationCallObjectStmt->execute(
            [
                'integration_name' => $integrationName,
                'call_id'          => $callId,
                'channel_id'       => $channelId,
                'team_id'          => $teamId,
                'object_data'      => json_encode($objectData)
            ]
        );
    }

    /**
     * Mise à jour des informations utiles de l'objet créé sur un outil métier
     * @param string $integrationName Nom de l'outil sur lequel a été créé l'objet
     * @param string $callId Identifiant de l'appel représenté par l'objet
     * @param int $teamId Identifiant de la team à qui appartient l'intégration
     * @param array $objectData Données utiles de l'objet créé
     * @param int|null $callObjectId Identifiant Ringover
     * @return void
     */
    public function updateIntegrationCallObject(
        string $integrationName,
        string $callId,
        string $channelId,
        int    $teamId,
        array  $objectData,
        ?int   $callObjectId = null
    ) {
        $updateIntegrationCallObjectQuery =
            "UPDATE
            integrations_call_object_history
        SET
            object_data = :object_data
        WHERE
            call_id = :call_id AND
            channel_id = :channel_id AND
            team_id = :team_id AND
            integration_name = :integration_name";

        $updateIntegrationCallObjectParams =
            [
                'integration_name' => $integrationName,
                'call_id'          => $callId,
                'channel_id'       => $channelId,
                'team_id'          => $teamId,
                'object_data'      => json_encode($objectData)
            ];

        if (!is_null($callObjectId)) {
            $updateIntegrationCallObjectQuery .= ' AND id = :id';
            $updateIntegrationCallObjectParams['id'] = $callObjectId;
        }

        $updateIntegrationCallObjectStmt = $this->pdoHandler->prepare($updateIntegrationCallObjectQuery);

        $updateIntegrationCallObjectStmt->execute($updateIntegrationCallObjectParams);
    }

    /**
     * Save integration fields new configuration
     * @param int $integrationId
     * @param array $integrationFields
     * @param string $configurationKey
     * @return int
     */
    public function saveIntegrationConfigurationAsJson(
        int    $integrationId,
        array  $integrationFields,
        string $configurationKey
    ): int {
        $query = $this->pdoHandler->prepare(
            "
            UPDATE
                oauth2_tokens_services
            SET
                service_data = JSON_SET(service_data, '$.$configurationKey', CAST(:json_configuration AS JSON))
            WHERE id = :id
        "
        );
        $query->bindValue('id', $integrationId, PDO::PARAM_INT);
        $query->bindValue('json_configuration', json_encode($integrationFields));
        $query->execute();
        return $query->rowCount();
    }

    /**
     * Remplace la valeur d'une seule clé de la configuration d'une intégration
     * @param int $integrationId
     * @param string $fieldNameToUpdate
     * @param array $newValue
     * @return int
     */
    public function updateIntegrationFieldConfiguration(
        int    $integrationId,
        string $fieldNameToUpdate,
        array  $newValue
    ): int {
        $jsonPathToUpdate = "$.$fieldNameToUpdate";
        $encodedNewValue = json_encode($newValue);

        $updateIntegrationFieldConfigurationStmt = $this->pdoHandler->prepare(
            "
UPDATE
    oauth2_tokens_services
SET
    service_data = JSON_SET(service_data, '$jsonPathToUpdate', CAST(:new_value AS JSON))
WHERE id = :integration_id
        "
        );

        $updateIntegrationFieldConfigurationStmt->execute([
                                                              'integration_id' => $integrationId,
                                                              'new_value'      => $encodedNewValue
                                                          ]);
        return $updateIntegrationFieldConfigurationStmt->rowCount();
    }

    /**
     * Remplace l'intégralité de la configuration d'une intégration
     * @param int $integrationId
     * @param array $integrationConfiguration
     * @return int
     */
    public function saveIntegrationConfiguration(int $integrationId, array $integrationConfiguration): int
    {
        $updateIntegrationConfigurationStmt = $this->pdoHandler->prepare(
            "
UPDATE
    oauth2_tokens_services
SET
    service_data = :integration_configuration
WHERE id = :integration_id
        "
        );
        $updateIntegrationConfigurationStmt->execute(
            [
                ':integration_id'            => $integrationId,
                ':integration_configuration' => json_encode(
                    $integrationConfiguration
                )
            ]
        );

        return $updateIntegrationConfigurationStmt->rowCount();
    }

    public function updateIntegrationToken(
        int      $integrationId,
        string   $accessToken,
        string   $refreshToken,
        DateTime $expirationDateTime
    ): int {
        $updateIntegrationTokenStmt = $this->pdoHandler->prepare(
            "
UPDATE
    oauth2_tokens_services
SET
    access_token = :access_token,
    refresh_token = :refresh_token,
    expiration_date = :expiration_date
WHERE id = :integration_id
        "
        );
        $updateIntegrationTokenStmt->execute(
            [
                'integration_id'  => $integrationId,
                'refresh_token'   => $refreshToken,
                'access_token'    => $accessToken,
                'expiration_date' => $expirationDateTime->format(
                    CustomFormat::DATE_ANSI_SQL
                )
            ]
        );

        return $updateIntegrationTokenStmt->rowCount();
    }

    /**
     * @param string $integrationName
     * @param string $jsonKey
     * @param $jsonValueToSearch
     * @return \App\Domain\Integration\Integration|null
     * @throws Exception
     */
    public function getIntegrationByJsonExtract(
        string $integrationName,
        string $jsonKey,
               $jsonValueToSearch
    ): ?Integration {
        $getIntegrationByJsonExtractStmt = $this->getIntegrationByJsonExtractStatement(
            $integrationName,
            $jsonKey,
            $jsonValueToSearch
        );

        if (!$getIntegrationByJsonExtractStmt) {
            return null;
        }

        $integrationData = $getIntegrationByJsonExtractStmt->fetch(PDO::FETCH_ASSOC);
        return $integrationData ? $this->rawIntegrationToObject($integrationData) : null;
    }

    /**
     * @param string $integrationName
     * @param string $jsonKey
     * @param $jsonValueToSearch
     * @return array|null
     * @throws Exception
     */
    public function getAllIntegrationsByJsonExtract(
        string $integrationName,
        string $jsonKey,
               $jsonValueToSearch
    ): array {
        $integrationsArray = [];
        $getIntegrationByJsonExtractStmt = $this->getIntegrationByJsonExtractStatement(
            $integrationName,
            $jsonKey,
            $jsonValueToSearch
        );

        if (!$getIntegrationByJsonExtractStmt) {
            return [];
        }

        $integrationDataResult = $getIntegrationByJsonExtractStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($integrationDataResult as $integrationData) {
            $integrationsArray[] = $this->rawIntegrationToObject($integrationData);
        }
        return $integrationsArray;
    }

    /**
     * @param string $integrationName
     * @param string $jsonKey
     * @param $jsonValueToSearch
     * @return PDOStatement|null
     * @throws Exception
     */
    public function getIntegrationByJsonExtractStatement(
        string $integrationName,
        string $jsonKey,
               $jsonValueToSearch
    ): ?PDOStatement {
        $sql = "SELECT
            ots_team.id,
            ots_team.instance_url,
            ots_team.refresh_token,
            ots_team.access_token,
            ots_team.creation_date,
            ots_team.expiration_date,
            ots_team.service_user,
            ots_team.service_name,
            ots_team.service_data,
            ots_team.team_id
        FROM
            oauth2_tokens_services ots_team JOIN
            team tea ON (tea.id = ots_team.team_id)
        WHERE
            tea.status = 1 AND
            ots_team.service_name = :integration_name AND
            JSON_EXTRACT(service_data, '$jsonKey') = :value_to_search";

        if (is_bool($jsonValueToSearch)) {
            // bindValue PDO::PARAM_BOOL not working
            $boolValue = $jsonValueToSearch ? 'true' : 'false';
            $sql = str_replace(':value_to_search', $boolValue, $sql);
        }

        $getIntegrationByJsonExtractStmt = $this->pdoHandler->prepare($sql);

        try {
            $getIntegrationByJsonExtractStmt->bindValue('integration_name', $integrationName);

            if (!is_bool($jsonValueToSearch)) {
                if (is_int($jsonValueToSearch)) {
                    $valueType = PDO::PARAM_INT;
                } else {
                    $valueType = PDO::PARAM_STR;
                }
                $getIntegrationByJsonExtractStmt->bindValue('value_to_search', $jsonValueToSearch, $valueType);
            }
            $getIntegrationByJsonExtractStmt->execute();
        } catch (PDOException $exception) {
            $this->logger->alert(
                'INTEGRATIONS :: Impossible de récupérer les informations des utilisateurs concernés par l\'appel',
                [
                    'exception'            => $exception,
                    'json_key'             => $jsonKey,
                    'json_value_to_search' => $jsonValueToSearch
                ]
            );
            return null;
        }

        return $getIntegrationByJsonExtractStmt;
    }

    /**
     * @throws Exception
     */
    public function getIntegrationById(
        $integrationId
    ): ?Integration {
        $getIntegrationByIdInfoStmt = $this->pdoHandler->prepare(
            "SELECT
            ots_team.id,
            ots_team.instance_url,
            ots_team.refresh_token,
            ots_team.access_token,
            ots_team.creation_date,
            ots_team.expiration_date,
            ots_team.service_user,
            ots_team.service_name,
            ots_team.service_data,
            ots_team.team_id
        FROM
            oauth2_tokens_services ots_team
        WHERE
            ots_team.id = :id"
        );
        $getIntegrationByIdInfoStmt->bindValue('id', $integrationId, PDO::PARAM_INT);
        try {
            $getIntegrationByIdInfoStmt->execute();
        } catch (PDOException $exception) {
            $this->logger->alert(
                'INTEGRATIONS :: Impossible de récupérer les informations des utilisateurs concernés par l\'appel',
                [
                    'exception'      => $exception,
                    'integration_id' => $integrationId
                ]
            );
            return null;
        }
        $integrationData = $getIntegrationByIdInfoStmt->fetch(PDO::FETCH_ASSOC);
        return $integrationData ? $this->rawIntegrationToObject($integrationData) : null;
    }

    /**
     * Supprime le token d'une intégration
     *
     * @param int $integrationId Id de l'intégration.
     * @return bool
     */
    public function deleteTokenIntegration(int $integrationId): bool
    {
        try {
            // Suppression de la clé de connexion
            $deleteCrmTokenStmt = $this->pdoHandler->prepare('DELETE FROM oauth2_tokens_services WHERE id = :token_id');
            return $deleteCrmTokenStmt->execute(['token_id' => $integrationId]);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return false;
        }
    }

    /**
     * Récupération du token OAuth2 d'une intégration
     * "expiration_date" est le TS converti du string en UTC
     * @throws Exception
     */
    public function getRawOAuth2AccessTokenDetailsFromId(int $integrationId): array
    {
        $getAccessTokenDetailsStmt = $this->pdoHandler->prepare(
            "SELECT
            refresh_token,
            access_token,
            instance_url,
            expiration_date,
            service_user
        FROM
            oauth2_tokens_services
        WHERE
            id = :integration_id"
        );
        $getAccessTokenDetailsStmt->bindValue('integration_id', $integrationId, PDO::PARAM_INT);
        $getAccessTokenDetailsStmt->execute();

        $rawAccessTokenDetails = $getAccessTokenDetailsStmt->fetch(PDO::FETCH_ASSOC);

        $expirationDate = new DateTime($rawAccessTokenDetails['expiration_date'], new DateTimeZone('UTC'));
        $rawAccessTokenDetails['expiration_date'] = $expirationDate->getTimestamp();
        return $rawAccessTokenDetails;
    }

    /**
     * Sauvegarde d'un token oauth2 en base, pour une intégration donnée
     * @param int $integrationId
     * @param AccessTokenInterface $accessToken
     * @return void
     */
    public function persistOAuth2AccessToken(int $integrationId, AccessTokenInterface $accessToken)
    {
        $expirationDateStr = (new DateTime())->setTimezone(new DateTimeZone('UTC'))
                                             ->setTimestamp($accessToken->getExpires())->format(
                CustomFormat::DATE_ANSI_SQL
            );
        $options = [
            'integration_id'  => $integrationId,
            'access_token'    => $accessToken->getToken(),
            'expiration_date' => $expirationDateStr,
            'service_user'    => $accessToken->getResourceOwnerId() ?? ''
        ];
        $refreshToken = '';
        if (!is_null($accessToken->getRefreshToken())) {
            $refreshToken = "refresh_token = :refresh_token,";
            $options['refresh_token'] = $accessToken->getRefreshToken();
        }
        $persistAccessTokenStmt = $this->pdoHandler->prepare(
            "
            UPDATE
                oauth2_tokens_services
            SET     $refreshToken
                    access_token = :access_token,
                    expiration_date = :expiration_date,
                    service_user = :service_user
            WHERE
                id = :integration_id
        "
        );
        $persistAccessTokenStmt->execute($options);
    }

    public function getCallTagsById(string $callId): string
    {
        $getCallTagsStmt = $this->pdoHandler->prepare(
            "select  team_tags.name from cdr_tags_custom
        LEFT JOIN team_tags ON team_tags.id = cdr_tags_custom.tag_id
        WHERE call_id = :call_id ORDER BY cdr_tags_custom.updated_at DESC LIMIT 1;"
        );

        $getCallTagsStmt->execute(['call_id' => $callId]);
        $inlineTags = $getCallTagsStmt->fetchColumn();
        return empty($inlineTags) ? '' : $inlineTags;
    }
}
