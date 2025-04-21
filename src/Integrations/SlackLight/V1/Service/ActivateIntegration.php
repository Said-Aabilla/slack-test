<?php

namespace App\Integrations\SlackLight\V1\Service;

use App\Domain\Integration\Integration;
use App\Domain\Integration\Service\IntegrationCreation;
use App\Integrations\Service\AbstractActivateIntegration;
use App\Integrations\Slack\V2\Service\Slack;
use App\Intrastructure\Persistence\UserRepository;
use Exception;
use InvalidArgumentException;

/** @property SlackLight $integrationService */
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

        $authCode     = $requestBody['code'];

        if (empty($authCode)) {
            throw new InvalidArgumentException('Empty auth code', 400);
        }

        $tokenData = $this->integrationService->oauthProvider->generateToken($this->integration, $authCode, 'slack_quicktalk');

        $this->loadExistingIntegration();

        $this->installDefaultConfig($userId, $tokenData);

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

        // Ne pas enregistrer callEventTexts en base, à fin d'utiliser les fichiers i18n de Slack
        $defaultConfig['callEventTexts'] = [];


        $slackLightConfig = [
            'enabled'                   => true,
            'botUseId'                  => $tokenData['bot_user_id'],
            'internal'                  => 'on',
            'languageCode'              => 'en',
            'channelsConfig'            => [],
            'records'                   => 'all'
        ];


        $slackLightConfig['syncUserId'] = $userId;
        $slackLightConfig['syncCC'] = $currentUser["cc"];

        $this->integration->setConfiguration(array_merge($defaultConfig, $slackLightConfig));
        $this->integration->setInstanceUrl(Slack::API_URL);
    }
}