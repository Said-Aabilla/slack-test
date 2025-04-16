<?php

namespace App\Application\Actions\IntegrationFields;

use App\Application\Actions\IntegrationAction;
use App\Domain\Integration\Integration;
use App\Integrations\Service\AbstractIntegrationFields;
use Exception;
use Slim\Exception\HttpInternalServerErrorException;
use Slim\Exception\HttpUnauthorizedException;

abstract class IntegrationFieldsAction extends IntegrationAction
{
    /**
     * Get integration service from given integration and request
     * @param Integration $integration
     * @return AbstractIntegrationFields
     */
    protected function getIntegrationFieldsService(Integration $integration): AbstractIntegrationFields
    {
        try {
            /** @var AbstractIntegrationFields $integrationFieldsService */
            $integrationFieldsService = $this->container->get($integration->getNamespace(). '\Service\IntegrationFields');
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            throw new HttpInternalServerErrorException($this->request, 'No service provided for this integration');
        }
        if (!($integrationFieldsService instanceof AbstractIntegrationFields)) {
            throw new HttpUnauthorizedException($this->request, 'Integration not supported yet');
        }
        try {
            $integrationService = $this->container->get($integration->getServiceClassName());
        } catch (Exception $exception) {
            throw new HttpUnauthorizedException($this->request, 'Integration service not supported yet');

        }
        $integrationFieldsService->setIntegration($integration);
        $integrationFieldsService->setIntegrationService($integrationService);
        return $integrationFieldsService;
    }
}