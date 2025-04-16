<?php

namespace App\Application\Actions\Events;

use App\Application\Actions\Action;
use App\Domain\Integration\UserTokenInfos;
use App\Intrastructure\Persistence\CommandQueryPDO;
use DI\Container;
use App\Domain\Integration\Integration;
use App\Integrations\Service\AbstractIntegration;
use App\Integrations\Service\AbstractProcessSmsEvent;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\SMSEvent\Service\SmsCreation;

class ProcessSmsEventAction extends Action
{
    private string $processSmsEventServiceNamespace = '\Service\ProcessSmsEvent';

    /**
     * @var \DI\Container
     */
    private Container $container;

    /**
     * @var SmsCreation
     */
    private SmsCreation $smsCreation;

    /**
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function createLegacyContainer(): array
    {
        // Compatibilité avec l'existant
        return [
            'settings' => $this->container->get('settings'),
            'database' => $this->container->get(CommandQueryPDO::class),
            'logger'   => $this->container->get(IntegrationLoggerInterface::class)
        ];
    }

    private function createLegacyCommandRoute(ServerRequestInterface $request, int $offset): array
    {
        $uriParts = explode('/', $request->getUri()->getPath());
        $_COMMAND = [];

        $uriPartsNumber = count($uriParts);
        for (
            $uriPartsPosition = $offset;
            $uriPartsPosition < $uriPartsNumber;
            $uriPartsPosition = $uriPartsPosition + 2
        ) {
            if (!isset($uriParts[$uriPartsPosition])) {
                continue;
            }

            if (!isset($uriParts[$uriPartsPosition + 1])) {
                $uriParts[$uriPartsPosition + 1] = '';
            }

            $_COMMAND[$uriParts[$uriPartsPosition]] = $uriParts[$uriPartsPosition + 1];
        }

        return $_COMMAND;
    }

    public function __construct(
        IntegrationLoggerInterface $logger,
        Container $container,
        SmsCreation $smsCreation
    ) {
        parent::__construct($logger);
        $this->container = $container;
        $this->smsCreation = $smsCreation;
    }

    protected function action(): Response
    {
        $responsePayload = $this->processLegacyIntegrations();
        $this->processPOOIntegrations();

        $this->response->getBody()->write(json_encode($responsePayload ?? ''));
        return $this->response;
    }

    private function processLegacyIntegrations()
    {
        $logger = $this->logger;

        // Compatibilité avec l'existant
        $containerDI = $this->container;
        $container = $this->createLegacyContainer();
        $_COMMAND = $this->createLegacyCommandRoute(
            $this->request,
            intval($container['settings']['internals']['offset'])
        );

        $GLOBALS['logger'] = $logger;
        $GLOBALS['container'] = $container;
        $GLOBALS['containerDI'] = $containerDI;
        $GLOBALS['pdoHandler'] = $container['database'];

        $request = $this->request;
        return include_once dirname(__DIR__, 4) . '/include_sms_events.php';
    }

    private function processPOOIntegrations()
    {
        $requestAttributes = $this->request->getAttributes();
        $isTeamRequest = empty($requestAttributes['user_id']) && !empty($requestAttributes['team_id']);

        $smsEvent = $this->smsCreation->createSmsFromRawEvent(
            $requestAttributes['team_id'],
            $requestAttributes['user_id'] ?? 0,
            $this->request->getParsedBody(),
            $isTeamRequest
        );

        if (empty($smsEvent)) {
            $this->logger->integrationLog(
                'SMS_NOT_CREATED',
                "Impossible de créer l'objet qui représente le SMS"
            );
            return;
        }

        foreach ($smsEvent->integrations as $integration) {
            /** @var \App\Domain\Integration\Integration $integration */
            $processCallEventService = $this->getProcessSmsEventServiceForIntegration($integration);
            if ($processCallEventService === null) {
                continue;
            }

            $processCallEventService->__process($integration, $smsEvent);
        }

    }

    /**
     * Création de l'instance du service qui s'occupe de gérer l'évènement pour une intégration donnée
     * @param \App\Domain\Integration\Integration $integration
     * @return \App\Integrations\Service\AbstractProcessSmsEvent|null
     */
    private function getProcessSmsEventServiceForIntegration(Integration $integration): ?AbstractProcessSmsEvent
    {
        $processSmsEventServiceNamespace = $integration->getNamespace() . $this->processSmsEventServiceNamespace;
        $integrationServiceNamespace = $integration->getServiceClassName();
        try {
            $processSmsEventService = $this->container->get($processSmsEventServiceNamespace);
            $integrationService = $this->container->get($integrationServiceNamespace);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage());
            return null;
        }

        if (!($processSmsEventService instanceof AbstractProcessSmsEvent)) {
            $this->logger->error(
                "AbstractProcessSmsEvent expected",
                [
                    'class_name' => get_class($processSmsEventService)
                ]
            );
            return null;
        }

        if (!($integrationService instanceof AbstractIntegration)) {
            $this->logger->error(
                "AbstractIntegration expected",
                [
                    'class_name' => get_class($integrationService)
                ]
            );
            return null;
        }

        $processSmsEventService->setIntegrationService($integrationService);

        return $processSmsEventService;
    }
}
