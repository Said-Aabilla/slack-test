<?php

namespace App\Application\Actions\IntegrationFields;

use App\Application\Actions\IntegrationAction;
use App\Domain\Integration\Integration;
use App\Domain\IntegrationFields\DTO\IntegrationFieldPropertyDTO;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpUnauthorizedException;

class SaveIntegrationFieldsAction extends IntegrationFieldsAction
{

    /**
     * @throws \Exception
     */
    public function action(): Response
    {
        $configuration = $this->generateIntegrationFieldPropertiesFromBodyRequest($this->integration);
        $this->integrationRepository->saveIntegrationConfigurationAsJson(
            $this->integration->getId(),
            $configuration,
            'objectsFieldsMetadata'
        );
        return $this->response->withStatus(StatusCodeInterface::STATUS_ACCEPTED);
    }

    /**
     * @param Integration $integration
     * @return IntegrationFieldPropertyDTO[]
     */
    private function generateIntegrationFieldPropertiesFromBodyRequest(Integration $integration): array
    {
        $body = $this->request->getParsedBody();
        if (is_null($body)) {
            throw new HttpBadRequestException($this->request, 'Invalid body request given');
        }
        $this->checkRequiredProperties($body, ['configuration']);
        $fields = [];
        $objectMetadata = [];
        $integrationFieldsService = $this->getIntegrationFieldsService($integration);
        $objectList = $integrationFieldsService->getObjectList();
        foreach ($body['configuration'] as $configuration) {
            $this->checkRequiredProperties($configuration, ['object', 'property']);
            $objectName = $configuration['object'];
            if (!array_key_exists($objectName, $objectList)) {
                throw new HttpBadRequestException($this->request, "$objectName not supported for this integration");
            }
            if (!isset($objectMetadata[$objectName])) {
                $objectMetadata[$objectName] = $integrationFieldsService->getObjectFieldsMetadata($objectName);
            }
            $objectProperties = $objectMetadata[$objectName];
            $property = $configuration['property'];
            if (!array_key_exists($property, $objectProperties)) {
                throw new HttpUnauthorizedException($this->request, "$property not supported for this object ($objectName)");
            }

            $fields[] = new IntegrationFieldPropertyDTO(
                $configuration['label'] ?? $objectProperties[$property],
                $property,
                $objectName,
                $body['description'] ?? null
            );
        }
        return $fields;
    }
}