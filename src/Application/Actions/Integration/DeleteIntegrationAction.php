<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\IntegrationAction;
use App\Integrations\Service\GenericDeleteIntegration;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;

class DeleteIntegrationAction extends IntegrationAction
{
    protected function action(): Response
    {
        try {
            /** @var \App\Integrations\Service\AbstractDeleteIntegration $deleteIntegrationService */
            $deleteIntegrationService = $this->getProcessIntegrationService(
                'DeleteIntegration',
                GenericDeleteIntegration::class
            );

            if (empty($deleteIntegrationService)) {
                return $this->response->withStatus(404);
            }
            $authorizationHeader = current($this->request->getHeader('Authorization'));
            $deleteResult = $deleteIntegrationService->__process($this->integration, $authorizationHeader);

            if ($deleteResult) {
                return $this->response->withStatus(204,"Suppression de l'intégration ".$this->integration->getIntegrationName()." effectuée avec succès.");
            } else {
                return $this->response->withStatus(500);
            }

        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                '',
                500
            );
        }
    }
}