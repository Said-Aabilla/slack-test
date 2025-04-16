<?php

namespace App\Application\Actions\Events;

use App\Application\Actions\Action;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Exception\IntegrationException;
use App\Domain\Integration\Integration;
use App\Domain\PresenceEvent\Service\PresenceEventCreation;
use App\Integrations\Service\AbstractIntegration;
use App\Integrations\Service\AbstractProcessPresenceEvent;
use App\Intrastructure\Persistence\IntegrationRepository;
use DI\Container;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;

class ProcessPresenceEventAction extends Action
{
    private const PRESENCE_SERVICE_NAMESPACE = '\Service\ProcessPresenceEvent';

    /**
     * @var \DI\Container
     */
    private Container $container;
    private IntegrationRepository $integrationRepository;

    /**
     * @var PresenceEventCreation
     */
    private PresenceEventCreation $presenceEventCreation;

    public function __construct(
        IntegrationLoggerInterface $logger,
        Container $container,
        PresenceEventCreation $presenceEventCreation,
        IntegrationRepository $integrationRepository
    ) {
        parent::__construct($logger);
        $this->container             = $container;
        $this->presenceEventCreation = $presenceEventCreation;
        $this->integrationRepository = $integrationRepository;
    }

    protected function action(): Response
    {
        $this->logger->debug(
            'PRESENCE_EVENT', [
                'class' => ProcessPresenceEventAction::class,
                'payload' => $this->request->getParsedBody()
            ]
        );

        $this->processPOOIntegrations();

        $this->response->getBody()->write(json_encode(''));
        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }

    private function processPOOIntegrations(): void
    {
        $requestAttributes = $this->request->getAttributes();

        $presenceEvent = $this->presenceEventCreation->createPresenceFromRawEvent(
            $requestAttributes['team_id'],
            $requestAttributes['user_id'] ?? 0,
            $this->request->getParsedBody()
        );

        if (empty($presenceEvent)) {
            $this->logger->integrationLog(
                'PRESENCE_SERVICE_ENTITY',
                "Impossible de créer l'objet qui représente la PRESENCE"
            );
            return;
        }

        $integrations = $this->integrationRepository->getTeamIntegrationsData(
            $requestAttributes['team_id'],
            [],
            true
        );
        if (empty($integrations)) {
            $this->logger->integrationLog('PRESENCE_ENTITY', "Aucune intégration trouvée");
            return;
        }

        foreach ($integrations as $integration) {
            /** @var \App\Domain\Integration\Integration $integration */
            try {
                $processPresenceEventService = $this->getProcessPresenceEventServiceForIntegration($integration);
            } catch (IntegrationException $e) {
                $processPresenceEventService = null;
            }
            if (is_null($processPresenceEventService)) {
                continue;
            }

            $processPresenceEventService->__process($integration, $presenceEvent);
        }
    }

    /**
     * Création de l'instance du service qui s'occupe de gérer l'évènement pour une intégration donnée
     * @param Integration $integration
     * @return AbstractProcessPresenceEvent|null
     */
    private function getProcessPresenceEventServiceForIntegration(
        Integration $integration
    ): ?AbstractProcessPresenceEvent {
        $processPresenceEventServiceNamespace = $integration->getNamespace() . self::PRESENCE_SERVICE_NAMESPACE;
        $integrationServiceNamespace          = $integration->getServiceClassName();
        try {
            $processPresenceEventService = $this->container->get($processPresenceEventServiceNamespace);
            $integrationService          = $this->container->get($integrationServiceNamespace);
        } catch (\Exception $exception) {
            $this->logger->integrationLog(
                'PRESENCE_SERVICE_EXCEPTION',
                "Failed to get classes",
                [
                    'message' => $exception->getMessage()
                ]
            );
            throw new IntegrationException('Failed to get service');
        }

        if (!($processPresenceEventService instanceof AbstractProcessPresenceEvent)) {
            $this->logger->integrationLog(
                'PRESENCE_SERVICE_ERROR',
                "AbstractProcessPresenceEvent expected",
                [
                    'class_name' => get_class($processPresenceEventService)
                ]
            );
            throw new IntegrationException('Wrong process Presence event service');
        }

        if (!($integrationService instanceof AbstractIntegration)) {
            $this->logger->integrationLog(
                'PRESENCE_SERVICE_ERROR',
                "AbstractIntegration expected",
                [
                    'class_name' => get_class($integrationService)
                ]
            );
            throw new IntegrationException('Wrong integration service');
        }

        $processPresenceEventService->setIntegrationService($integrationService);

        return $processPresenceEventService;
    }
}
