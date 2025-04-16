<?php

namespace App\Application\Actions\ExternalUserMapping;

use App\Application\Actions\IntegrationAction;
use App\Integrations\Service\AbstractExternalUserMapping;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpNotImplementedException;

class GetExternalUserMappingAction extends IntegrationAction
{

    /**
     * @inheritDoc
     */
    protected function action(): Response
    {
        /** @var AbstractExternalUserMapping $externalUserMappingService */
        $externalUserMappingService = $this->getProcessIntegrationService(
            'ExternalUserMapping'
        );
        if (is_null($externalUserMappingService)) {
            throw new HttpNotImplementedException($this->request, 'Integration not implanted yet');
        }
        $externalUserMappingService->setIntegration($this->integration);
        $this->response->getBody()->write(json_encode($externalUserMappingService->getExternalUserConfiguration()));
        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }
}