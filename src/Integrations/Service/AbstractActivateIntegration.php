<?php

namespace App\Integrations\Service;

use App\Domain\Exception\IntegrationException;
use App\Domain\Integration\Integration;
use Exception;

abstract class AbstractActivateIntegration extends AbstractProcess
{
    /**
     * Summary of __process
     * @param array $requestBody POST body
     * @param array $queryParams GET params
     * @param int $teamId
     * @param mixed $userId
     * @return Integration
     * @throws Exception
     */
    public function __process(array $requestBody, array $queryParams, int $teamId, ?int $userId = null): Integration
    {
        $newIntegration = $this->process($requestBody, $queryParams, $teamId, $userId);
        if ($newIntegration->getId() > 0) {
            $creationResult = $this->integrationRepository->reconnectUpdateIntegration($newIntegration);
            // Log
            $this->logger->debug(
                'INTEGRATION_UPDATED',
                [
                    'name' => $newIntegration->getIntegrationName(),
                    'id'   => $newIntegration->getId()
                ]
            );
        } else {
            $creationResult = $this->integrationRepository->createNewIntegration($newIntegration);
            if ($creationResult) {
                // Assigne new ID to integration
                $newIntegration =
                    $this->integrationRepository->getIntegrationById($creationResult)
                    ?? $newIntegration;
                // Log
                $this->logger->debug(
                    'INTEGRATION_CREATED',
                    [
                        'name' => $newIntegration->getIntegrationName(),
                        'id'   => $creationResult
                    ]
                );
            }
        }

        if (!$creationResult) {
            throw new IntegrationException('Failed to create integration');
        }

        return $newIntegration;
    }

    /**
     * Summary of process
     * @param array $requestBody
     * @param array $queryParams
     * @param int $teamId
     * @param mixed $userId
     * @return Integration
     */
    abstract public function process(
        array $requestBody,
        array $queryParams,
        int   $teamId,
        ?int  $userId = null
    ): Integration;
}
