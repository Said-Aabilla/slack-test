<?php

namespace App\Application\Actions\Sms;

use App\Application\Actions\IntegrationAction;
use Psr\Http\Message\ResponseInterface;
use App\Domain\Exception\IntegrationException;

class PushSmsAction extends IntegrationAction
{
    public function action(): ResponseInterface
    {
        try {
            /** @var \App\Integrations\Service\AbstractProcessSmsPush $smsPushService */
            $smsPushService = $this->getProcessIntegrationService(
                'ProcessSmsPush'
            );

            if (empty($smsPushService)) {
                return $this->response->withStatus(404, 'Service ProcessSmsPush not found');
            }
            $payload = $this->request->getParsedBody();
            $response = $smsPushService->__process($this->integration, $payload);
        } catch (IntegrationException $exception) {
            $this->logger->error($exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                $exception->getMessage() ?? '',
                0 < $exception->getCode() ? $exception->getCode() : 500
            );
        }

        return $this->responseRawWithData($response);
    }
}

