<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\IntegrationAction;
use App\Integrations\Service\GenericSetConfiguration;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;

class SetIntegrationConfigurationAction extends IntegrationAction
{
    protected function action(): Response
    {
        try {
            /** @var \App\Integrations\Service\AbstractSetConfiguration $setConfigurationService */
            $setConfigurationService = $this->getProcessIntegrationService(
                'SetConfiguration',
                GenericSetConfiguration::class
            );

            if (empty($setConfigurationService)) {
                return $this->response->withStatus(404);
            }

            if (empty($this->request->getParsedBody())) {
                return $this->respondWithError(
                    'Empty body',
                    'Le body ne peut être vide'
                );
            }
            $user_id = $this->request->getAttribute("user_id") ?? null;
            $setConfigurationService->__process($this->integration, $this->request->getParsedBody(), $user_id);
        } catch (Exception $exception) {
            $this->logger->error('SET_CONFIGURATION '.$exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                $exception->getMessage() ?? '',
                $exception->getCode() ?? 500
            );
        }

        return $this->response->withStatus(204);

    }
}