<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\IntegrationsAction;
use App\Domain\Exception\IntegrationException;
use App\Domain\Exception\TokenException;
use App\Integrations\Service\AbstractStatus;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;

class GetStatusAction extends IntegrationsAction
{


    protected function action(): Response
    {
        $integrationsAliveResponse = [];

        foreach ($this->integrations as $integration) {
            /** @var AbstractStatus $statusService */
            $statusService = $this->getProcessIntegrationService(
                $integration->getIntegrationAliasOrName(),
                'Status'
            );

            if (empty($statusService)) {
                continue;
            }

            $statusService->setIntegration($integration);

            try {
                $isAlive = $statusService->isAlive();
                $status = $isAlive ? AbstractStatus::OK_STATUS : AbstractStatus::NOK_STATUS;
                $message = $isAlive ? null : AbstractStatus::UNKNOWN_ERROR_MESSAGE;
            } catch (TokenException $tokenException) {
                $status = AbstractStatus::TOKEN_EXCEPTION_STATUS;
                $message = $tokenException->getMessage();
            } catch (IntegrationException $integrationException) {
                $status = AbstractStatus::INTEGRATION_EXCEPTION_STATUS;
                $message = $integrationException->getMessage();
            } catch (Exception $exception) {
                $status = AbstractStatus::UNKNOWN_EXCEPTION_STATUS;
                $message = AbstractStatus::UNKNOWN_ERROR_MESSAGE;
            }

            $aliveReport = [
                'name'       => $integration->getIntegrationName(),
                'alias_name' => $integration->getIntegrationAliasOrName(),
                'status'     => $status
            ];

            if (!empty($message)) {
                $aliveReport['message'] = $message;
            }

            $integrationsAliveResponse[$integration->getId()] = $aliveReport;
        }

        return $this->respondWithData($integrationsAliveResponse);
    }
}
