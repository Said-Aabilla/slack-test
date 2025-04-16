<?php

namespace App\Application\Actions;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\Integration;
use App\Integrations\Service\AbstractIntegration;
use App\Integrations\Service\AbstractProcess;
use App\Intrastructure\Persistence\IntegrationRepository;
use DI\Container;
use DI\NotFoundException;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

abstract class IntegrationsAction extends Action
{

    protected Container $container;
    protected IntegrationRepository $integrationRepository;

    /**
     * @var Integration[]
     */
    protected array $integrations;

    /**
     * @throws \Exception
     */
    public function __construct(
        IntegrationLoggerInterface $logger,
        Container $container,
        IntegrationRepository $integrationRepository
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

        $rawOrderByIntegration = trim($this->request->getQueryParams()['order_by_integration'] ?? '');
        if (!empty($rawOrderByIntegration)) {
            $orderByIntegration = explode(',', $rawOrderByIntegration);
        } else {
            $orderByIntegration = [];
        }

        $this->integrations = $this->getUserIntegrationsFromRequest($orderByIntegration);

        return $this->action();
    }

    /**
     * Get user/team integration service from given request
     * @return Integration[]
     * @throws Exception
     */
    public function getUserIntegrationsFromRequest(array $filterServiceByName = []): array
    {
        return $this->integrationRepository->getTeamIntegrationsData(
            $this->request->getAttribute('team_id'),
            $filterServiceByName,
            true
        );
    }

    public function getProcessIntegrationService(
        string $integrationName,
        string $integrationServiceName,
        string $defaultServiceNamespace = ''
    ): ?AbstractProcess {
        $integration = $this->integrations[$integrationName];

        $processIntegrationServiceNamespace = $integration->getNamespace() . "\Service\\" . $integrationServiceName;
        $integrationServiceNamespace = $integration->getServiceClassName();

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
            $integrationService = $this->container->get($integrationServiceNamespace);
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
            $this->logger->error("AbstractIntegration expected",
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
