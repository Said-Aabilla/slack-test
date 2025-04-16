<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\IntegrationsAction;
use App\Integrations\Service\AbstractGetContactOwner;
use Psr\Http\Message\ResponseInterface as Response;

class GetContactOwnerAction extends IntegrationsAction
{

    private function getValidIntegrations(): Response
    {
        $integrationWithSmartRoutingEnabled = [];
        foreach ($this->integrations as $integration) {
            /** @var AbstractGetContactOwner $getContactOwnerService */
            $getContactOwnerService = $this->getProcessIntegrationService(
                $integration->getIntegrationAliasOrName(),
                'GetContactOwner'
            );

            if (empty($getContactOwnerService)) {
                continue;
            }

            if (!$getContactOwnerService->hasSmartRoutingEnabled($integration)) {
                continue;
            }


            $integrationWithSmartRoutingEnabled[$integration->getId()] = [
                'name'       => $integration->getIntegrationName(),
                'alias_name' => $integration->getIntegrationAliasOrName()
            ];
        }

        return $this->respondWithData($integrationWithSmartRoutingEnabled);
    }

    private function getRingoverOwnerId(): Response
    {
        $e164PhoneNumber = $this->request->getQueryParams()['e164_phone_number'] ?? '';
        if (empty($e164PhoneNumber)) {
            return $this->respondWithError(
                'BAD_PARAMETER',
                'Le paramètre <e164_phone_number> est obligatoire.'
            );
        }

        if (is_numeric($e164PhoneNumber)) {
            $e164PhoneNumber = '+' . trim($e164PhoneNumber);
        }

        if (empty($this->integrations)) {
            return $this->response->withStatus(204);
        }

        /**
         * On arrête de circuler à traver les intégrations dès qu'on trouve un id utilisateur
         */
        foreach ($this->integrations as $integration) {
            /** @var AbstractGetContactOwner $getContactOwnerService */
            $getContactOwnerService = $this->getProcessIntegrationService(
                $integration->getIntegrationAliasOrName(),
                'GetContactOwner'
            );

            if (empty($getContactOwnerService)) {
                continue;
            }

            $ringoverUserId = $getContactOwnerService->__process($integration, trim($e164PhoneNumber));
            if (!empty($ringoverUserId)) {
                break;
            }
        }

        if (empty($ringoverUserId)) {
            $response = $this->response->withStatus(204);
        } else {
            $response = $this->respondWithData(['user_id' => $ringoverUserId]);
        }

        return $response;
    }

    protected function action(): Response
    {
        if (empty($this->request->getQueryParams())) {
            $response = $this->getValidIntegrations();
        } else {
            $response = $this->getRingoverOwnerId();
        }

        return $response;
    }
}
