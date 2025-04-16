<?php

namespace App\Integrations\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\Integration;
use App\Intrastructure\Persistence\ContactSyncRepository;
use App\Intrastructure\Persistence\IntegrationRepository;
use App\Intrastructure\Service\HttpClient;
use App\Settings\SettingsInterface;
use stdClass;

abstract class AbstractDeleteIntegration extends AbstractProcess
{
    protected bool $debugMode = false;
    private HttpClient $httpClient;
    private ContactSyncRepository $contactSyncRepository;
    /**
     * @var SettingsInterface
     */
    private SettingsInterface $settings;

    public function __construct(
        SettingsInterface $settings,
        HttpClient $httpClient,
        IntegrationRepository $integrationRepository,
        ContactSyncRepository $contactSyncRepository,
        IntegrationLoggerInterface $logger
    ) {
        parent::__construct($integrationRepository, $logger);
        $this->httpClient = $httpClient;
        $this->contactSyncRepository = $contactSyncRepository;
        $this->settings = $settings;
    }

    public function __process(Integration $integration, string $authorizationHeader): bool
    {
        $isDelete = $this->process($integration, $authorizationHeader);

        if (!$isDelete) {
            $this->logger->error("Erreur lors de la suppression");
            return false;
        }

        $userId = $integration->getUserId() ?? 0;

        if (!$this->isLastDeleteTaskDone(
            $integration->getId(),
            $integration->getIntegrationName(),
            $integration->getTeamId(),
            $userId,
            $authorizationHeader
        )) {
            $this->logger->error("Erreur lors de l'appel de l'API pour la suppression de contact");
            return false;
        }

        // Suppression de l'intégration dans la table contacts_sync
        $this->contactSyncRepository->deleteSyncTasksForIntegrationId($integration->getId());

        $deleteTokenIntegration = $this->integrationRepository->deleteTokenIntegration($integration->getId());

        if (!$deleteTokenIntegration) {
            $this->logger->error("Erreur lors de la suppression de l'instance");
            return false;
        }

        //construit l'URL pour créer une tâche de suppression de contact.
        $createDeleteTaskUrl = $this->getCreateDeleteTaskUrl($integration, $userId);
        $httpReturnCode      = 0;

        $this->makeRequest(
            'POST',
            $createDeleteTaskUrl,
            $authorizationHeader,
            $integration->getIntegrationName(),
            $httpReturnCode
        );

        return $httpReturnCode === 201;
    }

    private function isLastDeleteTaskDone(
        int $integrationId,
        string $integrationName,
        int $teamId,
        int $userId,
        string $authorizationHeader
    ): bool {
        $getDeleteTaskDetailsParams = [
            'integration_id' => $integrationId,
            'team_id'        => $teamId
        ];

        if ($userId != 0) {
            $getDeleteTaskDetailsParams['user_id'] = $userId;
        }

        $getDeleteTaskDetailsUrl = $this->getDeleteTaskDetailsUrl($getDeleteTaskDetailsParams);
        $httpReturnCode          = 0;

        $response = $this->makeRequest(
            'GET',
            $getDeleteTaskDetailsUrl,
            $authorizationHeader,
            $integrationName,
            $httpReturnCode
        );

        if ($httpReturnCode === 204 || $httpReturnCode === 200) {
            return true;
        }
        $lastDeleteTaskDetails = current($response);
        if ($lastDeleteTaskDetails->status !== 'DONE') {
            return false;
        }

        return true;
    }

    /**
     * Construit l'URL pour créer une tâche de suppression de contact.
     *
     * @param Integration $integration L'objet Integration contenant les informations nécessaires.
     * @param int $userId L'ID de l'utilisateur (0 si non défini).
     * @return string L'URL pour créer une tâche de suppression de contact.
     */
    private function getCreateDeleteTaskUrl(Integration $integration, int $userId): string
    {
        $contactV4ApiDomain = $this->getContactV4ApiDomain();

        $createDeleteTaskParams = [
            'integration_id' => $integration->getId(),
            'team_id'        => $integration->getTeamId()
        ];

        if ($userId != 0) {
            $createDeleteTaskParams['user_id'] = $integration->getUserId();
        }

        $createDeleteTaskUrl = $contactV4ApiDomain . '/private/contacts/delete_task';
        $createDeleteTaskUrl .= '?' . http_build_query($createDeleteTaskParams);

        return $createDeleteTaskUrl;
    }

    /**
     * Construit l'URL pour obtenir les détails de la tâche de suppression de contact.
     *
     * @param array $getDeleteTaskDetailsParams Les paramètres pour obtenir les détails de la tâche.
     * @return string L'URL pour obtenir les détails de la tâche de suppression de contact.
     */
    private function getDeleteTaskDetailsUrl(array $getDeleteTaskDetailsParams): string
    {
        $contactV4ApiDomain = $this->getContactV4ApiDomain();
        $getDeleteTaskDetailsUrl = $contactV4ApiDomain . '/private/contacts/delete_task';
        $getDeleteTaskDetailsUrl .= '?' . http_build_query($getDeleteTaskDetailsParams);

        return $getDeleteTaskDetailsUrl;
    }

    /**
     * Obtient le domaine de l'API Contact V4 en fonction du mode de débogage.
     *
     * @return string Le domaine de l'API Contact V4.
     */
    private function getContactV4ApiDomain(): string
    {
        $region = $this->settings['internals']['region'] ?? 'EU';

        if (!empty($this->settings['internals']['v4_api_domain'])) {
            $defaultURI = $this->settings['internals']['v4_api_domain'];
        } elseif (strtoupper($region) === 'US') {
            $defaultURI = 'https://api-us.ringover.com/v4';
        } else {
            $defaultURI = 'https://api-eu.ringover.com/v4';
        }

        return $this->debugMode ? 'https://contact.dev137.scw.ringover.net' : $defaultURI;
    }

    /**
     * Effectue une requête HTTP.
     *
     * @param string $method La méthode HTTP de la requête.
     * @param string $url L'URL de la requête.
     * @param string $authorizationHeader L'en-tête d'autorisation de la requête.
     * @param string $integrationName Le nom de l'intégration.
     * @param int $httpReturnCode Code retour HTTP
     * @return stdClass|false  Réponse de l'api distante
     */
    private function makeRequest(
        string $method,
        string $url,
        string $authorizationHeader,
        string $integrationName,
        int &$httpReturnCode
    ) {
        $headers = [
            'Authorization: ' . $authorizationHeader
        ];

        $httpReturnCode = 0;

        return $this->httpClient->request(
            $method,
            $url,
            null,
            $headers,
            $httpReturnCode,
            [],
            $integrationName
        );
    }

    abstract public function process(Integration $integration, string $authorizationHeader): bool;
}
