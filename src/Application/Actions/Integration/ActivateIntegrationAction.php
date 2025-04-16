<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\Action;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\AliasMapper;
use App\Domain\Integration\Integration;
use App\Integrations\Service\AbstractActivateIntegration;
use App\Integrations\Service\AbstractIntegration;
use App\Integrations\Service\AbstractProcess;
use DI\Container;
use DI\NotFoundException;
use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;

class ActivateIntegrationAction extends Action
{
    protected Container $container;

    protected Integration $integration;
    private AliasMapper $aliasMapper;

    /**
     * @throws Exception
     */
    public function __construct(
        IntegrationLoggerInterface $logger,
        Container                  $container,
        AliasMapper                $aliasMapper
    ) {
        parent::__construct($logger);
        $this->container = $container;
        $this->logger = $logger;
        $this->aliasMapper = $aliasMapper;
    }

    /**
     * @throws Exception
     */
    protected function action(): Response
    {
        if (empty($this->request->getParsedBody())) {
            return $this->respondWithError(
                'Empty body',
                'Le body ne peut être vide'
            );
        }

        $integrationName = $this->request->getAttribute('integration_name');
        $this->integration = new Integration(
            $this->aliasMapper->getRealIntegrationName(strtoupper($integrationName)),
            0,
            $this->aliasMapper->getIntegrationAlias(strtoupper($integrationName))
        );
        $this->integration->attachToRingoverClient($this->request->getAttribute('team_id'));

        // Activation
        try {
            /** @var AbstractActivateIntegration $activateIntegrationService */
            $activateIntegrationService = $this->getProcessIntegrationService('ActivateIntegration');
            $activateIntegrationService->setIntegration($this->integration);
            $this->integration = $activateIntegrationService->__process(
                $this->request->getParsedBody(),
                $this->request->getQueryParams(),
                $this->request->getAttribute('team_id'),
                $this->request->getAttribute('user_id')
            );
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                'Failed to activate integration.' . $exception->getMessage(),
                $exception->getCode() ?? 500
            );
        }

        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }

    private function getProcessIntegrationService(
        string $integrationServiceName,
        string $defaultServiceNamespace = ''
    ): ?AbstractProcess {
        $processIntegrationServiceNamespace = $this->integration->getNamespace()
            . "\Service\\" . $integrationServiceName;
        $integrationServiceNamespace = $this->integration->getServiceClassName();
        try {
            $processIntegrationService = $this->container->get($processIntegrationServiceNamespace);
        } catch (NotFoundException $exception) {
            if (empty($defaultServiceNamespace)) {
                return null;
            }

            $processIntegrationService = $this->container->get($defaultServiceNamespace);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return null;
        }

        try {
            /** @var AbstractIntegration $integrationService */
            $integrationService = $this->container->get($integrationServiceNamespace);
            if (!empty($this->integration->getIntegrationAlias())) {
                $integrationService->setAlias($this->integration->getIntegrationAlias());
            }
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return null;
        }

        if (!($processIntegrationService instanceof AbstractProcess)) {
            $this->logger->error(
                "AbstractProcess expected",
                [
                    'class_name' => get_class($processIntegrationService)
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

        $processIntegrationService->setIntegrationService($integrationService);

        return $processIntegrationService;
    }
}
