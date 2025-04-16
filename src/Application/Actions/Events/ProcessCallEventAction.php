<?php

namespace App\Application\Actions\Events;

use App\Application\Actions\Action;
use App\Domain\CallEvent\Call;
use App\Domain\CallEvent\Service\CallCreation;
use App\Domain\Integration\Integration;
use App\Integrations\Babel\V1\Service\Babel;
use App\Integrations\Babel\V1\Service\ProcessCallEvent;
use App\Integrations\Empower\V1\Service\Empower;
use App\Integrations\Service\AbstractIntegration;
use App\Integrations\Service\AbstractProcessCallEvent;
use App\Intrastructure\Persistence\CommandQueryPDO;
use DI\Container;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;
use App\Application\Logger\IntegrationLoggerInterface;

class ProcessCallEventAction extends Action
{

    private string $processCallEventServiceNamespace = '\Service\ProcessCallEvent';

    /**
     * @var \DI\Container
     */
    private Container $container;
    /**
     * @var \App\Domain\CallEvent\Service\CallCreation
     */
    private CallCreation $callCreation;

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

    /**
     * Appel de la transcription interne (Babel)
     * @param \App\Domain\CallEvent\Call $callEvent
     * @return void
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     */
    private function loadBabel(Call $callEvent)
    {
        if (
            !$callEvent->afterCall ||
            in_array($callEvent->eventName, ['record_available', 'voicemail_available'])
        ) {
            return;
        }

        $babelProcessCallEvent = $this->container->get(ProcessCallEvent::class);
        $babelProcessCallEvent->setIntegrationService($this->container->get(Babel::class));
        $integration = new Integration('BABEL', 0);
        $babelProcessCallEvent->__process($integration, $callEvent);
    }

    private function loadEmpower(Call $callEvent)
    {
        if (
            !$callEvent->afterCall ||
            $callEvent->afterCallEventName !== 'transcription_available' ||
            $callEvent->isSentBack
        ) {
            return;
        }

        $babelProcessCallEvent = $this->container->get(\App\Integrations\Empower\V1\Service\ProcessCallEvent::class);
        $babelProcessCallEvent->setIntegrationService($this->container->get(Empower::class));
        $integration = new Integration('EMPOWER', 0);
        $babelProcessCallEvent->__process($integration, $callEvent);
    }


    private function loadSMS(Call $callEvent)
    {
        if (
            !$callEvent->afterCall ||
            !in_array($callEvent->afterCallEventName, ['transcription_available', 'voicemail_available']) ||
            $callEvent->isSentBack
        ) {
            return;
        }

        $smsProcessCallEvent = $this->container->get(\App\Integrations\Sms\V1\Service\ProcessCallEvent::class);
        $smsProcessCallEvent->setIntegrationService($this->container->get(Empower::class));
        $integration = new Integration('SMS', 0);
        $smsProcessCallEvent->__process($integration, $callEvent);
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

    /**
     * Prise en charge des intégrations qui ont une implémentation itérative. (ancienne méthode)
     * @return mixed
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */

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
        $GLOBALS['containerDI'] = $containerDI;
        $GLOBALS['container'] = $container;
        $GLOBALS['pdoHandler'] = $container['database'];

        $request = $this->request;
        return include_once dirname(__DIR__, 4) . '/include_call_events.php';
    }

    /**
     * Création de l'instance du service qui s'occupe de gérer l'évènement pour une intégration donnée
     * @param Integration $integration
     * @return \App\Integrations\Service\AbstractProcessCallEvent|null
     */
    private function getProcessCallEventServiceForIntegration(Integration $integration): ?AbstractProcessCallEvent
    {
        $processCallEventServiceNamespace = $integration->getNamespace() . $this->processCallEventServiceNamespace;
        $integrationServiceNamespace = $integration->getServiceClassName();

        $processCallEventService = $this->container->get($processCallEventServiceNamespace);
        /** @var AbstractIntegration $integrationService */
        $integrationService = $this->container->get($integrationServiceNamespace);
        if (!empty($integration->getIntegrationAlias())) {
            $integrationService->setAlias($integration->getIntegrationAlias());
        }

        if (!($processCallEventService instanceof AbstractProcessCallEvent)) {
            $this->logger->error(
                "AbstractProcessCallEvent expected",
                [
                    'class_name' => get_class($processCallEventService)
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

        $processCallEventService->setIntegrationService($integrationService);

        return $processCallEventService;
    }


    /**
     * Prise en charge des intégrations qui ont une implémentation orienté objet (POO)
     * @return void
     */
    private function processPOOIntegrations()
    {
        $requestAttributes = $this->request->getAttributes();

        if (!empty($this->request->getParsedBody()['aftercall'])) {
            $callEvent = $this->callCreation->createCallFromAfterCallRawEvent(
                $this->request->getParsedBody(),
                $requestAttributes['team_id'] ?? 0,
                $requestAttributes['user_id'] ?? 0
            );
        } else {
            $callEvent = $this->callCreation->createCallFromRawEvent(
                $this->request->getParsedBody(),
                $requestAttributes['team_id'] ?? 0,
                $requestAttributes['user_id'] ?? 0
            );
        }

        if (empty($callEvent)) {
            $this->logger->integrationLog(
                'CALL_NOT_CREATED',
                "Impossible de créer l'objet qui représente l'appel"
            );
            return;
        }

        try {
            $this->loadBabel($callEvent);
            $this->loadEmpower($callEvent);
            $this->loadSMS($callEvent);
        } catch (Exception $exception) {
            $this->logger->integrationLog(
                'BABEL_ERROR',
                $exception->getMessage()
            );
        }

        foreach ($callEvent->integrations as $integration) {
            /** @var Integration $integration */
            try {
                $processCallEventService = $this->getProcessCallEventServiceForIntegration($integration);
            } catch (Exception $exception) {
                $this->logger->error(
                    "LOAD_INTEGRATION_ERROR",
                    [
                        'error_message'    => $exception->getMessage(),
                        'integration_name' => $integration->getIntegrationName()
                    ]
                );
                continue;
            }

            if ($processCallEventService === null) {
                continue;
            }

            try {
                $processCallEventService->__process($integration, $callEvent);
            } catch (Exception $exception) {
                $this->logger->error(
                    "PROCESS_INTEGRATION_ERROR",
                    [
                        'error_message'    => $exception->getMessage(),
                        'integration_name' => $integration->getIntegrationName()
                    ]
                );
            }
        }
    }

    public function __construct(
        IntegrationLoggerInterface $logger,
        CallCreation               $callCreation,
        Container                  $container
    ) {
        parent::__construct($logger);
        $this->container = $container;
        $this->callCreation = $callCreation;
    }

    /**
     * @return bool
     */
    public function doSkipWavRecordAftercall(): bool
    {
        if (
            empty($this->request->getParsedBody()['aftercall']) ||
            empty($this->request->getParsedBody()['record']['path'])
        ) {
            return false;
        }

        $audioPathInfo = pathinfo($this->request->getParsedBody()['record']['path']);
        if ($audioPathInfo['extension'] !== 'wav') {
            return false;
        }

        return true;
    }

    /**
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function action(): Response
    {
        if ($this->doSkipWavRecordAftercall()) {
            return $this->response->withStatus(204);
        }

        $responsePayload = $this->processLegacyIntegrations();
        $this->processPOOIntegrations();

        $this->response->getBody()->write(json_encode($responsePayload ?? ''));
        return $this->response;
    }
}
