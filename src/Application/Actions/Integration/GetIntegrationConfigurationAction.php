<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\IntegrationAction;
use App\Integrations\Service\GenericGetConfiguration;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Psr7\Stream;

class GetIntegrationConfigurationAction extends IntegrationAction
{
    protected function action(): Response
    {
        try {
            /** @var \App\Integrations\Service\AbstractGetConfiguration $getConfigurationService */
            $getConfigurationService = $this->getProcessIntegrationService(
                'GetConfiguration',
                GenericGetConfiguration::class
            );

            if (empty($getConfigurationService)) {
                return $this->response->withStatus(404);
            }

            $configuration = $getConfigurationService->__process($this->integration);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                '',
                500
            );
        }

        if (empty($configuration)) {
            return $this->response->withStatus(404);
        }

        $responsePayload = new Stream(fopen('php://temp', 'r+'));
        $responsePayload->write(json_encode($configuration));
        return $this->response->withBody($responsePayload);

    }
}