<?php

namespace App\Integrations\Slack\V2\Service;

use App\Domain\Integration\Integration;
use App\Domain\Integration\Service\IntegrationCreation;
use App\Integrations\Service\AbstractActivateIntegration;
use App\Intrastructure\Persistence\UserRepository;
use Exception;
use InvalidArgumentException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use stdClass;

/** @property Slack $integrationService */
class ActivateIntegration extends AbstractActivateIntegration
{
    private $userRepository;

    public function __construct(
        \App\Intrastructure\Persistence\IntegrationRepository $integrationRepository,
        \App\Application\Logger\IntegrationLoggerInterface $logger,
        UserRepository $userRepository
    ){
        $this->userRepository = $userRepository;
        parent::__construct($integrationRepository, $logger);
    }

    /**
     * @throws Exception
     */
    public function process(array $requestBody, array $queryParams, int $teamId, ?int $userId = null): Integration
    {
        $authCode     = trim($requestBody['code'] ?? '');

        if (empty($authCode)) {
            throw new InvalidArgumentException('Empty auth code', 400);
        }

        $tokenData = $this->integrationService->oauthProvider->generateToken($this->integration, $authCode,);

        $this->loadExistingIntegration();

        $this->installDefaultConfig($userId, $tokenData);

        $this->syncRingoverSlackUsers($teamId, $userId);

        return $this->integration;
    }

    /**
     * Cherche une intégration existante pour cette team.
     * Si trouvé alors, on remplace l'intégration présente.
     * Sinon, on ne fait rien.
     * @return void
     * @throws Exception
     */
    private function loadExistingIntegration()
    {
        // Search for existing integrations
        /** @var array|Integration[] $currentIntegration */
        $integrationsByName = $this->integrationRepository->getTeamIntegrationsData(
            $this->integration->getTeamId(),
            [
                $this->integration->getIntegrationName()
            ],
            true
        );

        if (!empty($integrationsByName)) {
            $this->integration = current($integrationsByName);
        }
    }

    /**
     * Installe la configuration par défaut, si et seulement si l'intégration est nouvelle.
     * C'est-à-dire qu'elle n'a pas d'identifiant unique généré par la BDD
     * @return void
     */
    private function installDefaultConfig(int $userId = null, $tokenData)
    {
        if (!empty($this->integration->getId())) {
            return;
        }

        $currentUser = $this->userRepository->getUserDetailsById($userId);
        $defaultConfig = IntegrationCreation::getDefaultConfiguration();

        // Remove unused params
        unset($defaultConfig['contactCreationCondition']);

        // Ne pas enregistre callEventTexts en base, à fin d'utiliser les fichiers i18n de Slack
        $defaultConfig['callEventTexts'] = [];


        $slackConfig = [
            'enabled'                   => true,
            'botUseId'                  => $tokenData['bot_user_id'],
            'internal'                  => 'on',
            'languageCode'              => 'en',
            'ringover_user_to_external' => ['users' => new stdClass()],
            'showContentSms'            => false,
            'showTagsNotes'             => false,
            'smsDirection'              => 'all',
            'channelsConfig'            => [],
            'records'                   => 'all'
        ];


        $slackConfig['syncUserId'] = $userId;
        $slackConfig['syncCC'] = $currentUser["cc"];

        $this->integration->setConfiguration(array_merge($defaultConfig, $slackConfig));
        $this->integration->setInstanceUrl(Slack::API_URL);
    }

    private function syncRingoverSlackUsers(int $teamId, ?int $userId): void
    {
        try {
            $config = $this->integration->getConfiguration();
            $userMapList = $config['ringover_user_to_external']['users'];

            // Convert stdClass to array if needed
            if ($userMapList instanceof stdClass) {
                $userMapList = json_decode(json_encode($userMapList), true);
            }

            // Get auto-mapping user list using the Slack service
            $result = $this->integrationService->autoMapRingoverSlackUsers(
                $teamId,
                $this->integration->getAccessToken(),
                $userMapList
            );

            // Update the configuration with new user mapping
            $config['ringover_user_to_external']['users'] = $result;
            $this->integration->setConfiguration($config);

            $this->logger->debug('SLACK :: sync user map done');
        } catch (Exception $e) {
            $this->logger->debug('SLACK :: sync user map : ' . $e->getMessage());
            throw $e;
        }
    }

}