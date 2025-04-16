<?php

namespace App\Application\Actions;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\AliasMapper;
use App\Domain\Integration\Integration;
use App\Integrations\Service\AbstractIntegration;
use App\Integrations\Service\AbstractProcess;
use App\Intrastructure\Persistence\IntegrationRepository;
use DI\Container;
use DI\NotFoundException;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

abstract class IntegrationAction extends Action
{

    protected Container $container;
    protected IntegrationRepository $integrationRepository;
    protected Integration $integration;

    /**
     * @throws Exception
     */
    public function __construct(
        AliasMapper                $fakeIntegrationNameMapper,
        IntegrationLoggerInterface $logger,
        Container                  $container,
        IntegrationRepository      $integrationRepository
    ) {
        parent::__construct($logger);
        $this->integrationRepository = $integrationRepository;
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * @throws \Exception
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $this->request = $request;
        $this->response = $response;
        $this->args = $args;
        header('Content-Type: application/json');

        $this->integration = $this->getUserIntegrationFromRequest();

        return $this->action();
    }

    /**
     * Get user/team integration service from given request
     * @return Integration
     * @throws Exception
     */
    public function getUserIntegrationFromRequest(): Integration
    {
        if (
            empty($this->request->getAttribute('integration_name')) &&
            empty($this->request->getAttribute('integration_id'))
        ) {
            throw new HttpBadRequestException($this->request, 'Empty integration name or id given');
        }

        if (!empty($this->request->getAttribute('integration_id'))) {
            $integration = $this->integrationRepository->getIntegrationById(
                $this->request->getAttribute('integration_id')
            );

            if (!$integration) {
                throw new HttpNotFoundException($this->request, 'No integration found for this id');
            }

            if ($integration->getTeamId() !== (int)$this->request->getAttribute('team_id')) {
                throw new HttpNotFoundException($this->request, 'No integration found for this team');
            }
            return $integration;
        } else {
            $serviceName = strtoupper($this->request->getAttribute('integration_name'));
            $integrations = $this->integrationRepository->getTeamIntegrationsData(
                $this->request->getAttribute('team_id'),
                [$serviceName],
                true
            );
            if (!isset($integrations[$serviceName])) {
                throw new HttpNotFoundException($this->request, 'No integration found for this user');
            }
            return $integrations[$serviceName];
        }
    }

    public function getDeleteIntegrationService(): ?AbstractProcess
    {
        $deleteIntegrationServiceNamespace = $this->integration->getNamespace() . "\Service\DeleteIntegration";

        try {
            $deleteIntegrationService = $this->container->get($deleteIntegrationServiceNamespace);
        } catch (Exception $exception) {
            try {
                $deleteIntegrationServiceNamespace = "App\Integrations\Service\GenericDeleteIntegration";
                $deleteIntegrationService = $this->container->get($deleteIntegrationServiceNamespace);
            } catch (Exception $exception) {
                $this->logger->error($exception->getMessage());
                return null;
            }
        }
        return $deleteIntegrationService;
    }


    public function getProcessIntegrationService(
        string  $integrationServiceName,
        string  $defaultServiceNamespace = ''
    ): ?AbstractProcess {
        $processIntegrationServiceNamespace = $this->integration->getNamespace() .
                                              "\Service\\" .
                                              $integrationServiceName;
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
