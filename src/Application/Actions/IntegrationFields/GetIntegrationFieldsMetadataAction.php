<?php

namespace App\Application\Actions\IntegrationFields;

use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;

class GetIntegrationFieldsMetadataAction extends IntegrationFieldsAction
{
    /**
     * @throws Exception
     */
    public function action(): Response
    {
        $integrationFieldsService = $this->getIntegrationFieldsService($this->integration);
        $objectName = $this->request->getAttribute('object');
        if (!array_key_exists($objectName, $integrationFieldsService->getObjectList())) {
            return $this->respondWithError('OBJECT_TYPE_NOT_SUPPORTED', "$objectName not supported for this integration", StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
        }
        $integrationFieldsService->setIntegration($this->integration);
        $objectMetadataPayload = $integrationFieldsService->getObjectFieldsMetadata($objectName);
        if (isset($objectMetadataPayload['error_code'])) {
            return $this->respondWithError(
                $objectMetadataPayload['error_code'],
                $objectMetadataPayload['error'],
                $objectMetadataPayload['http_status_code']
            );
        }
        $this->response->getBody()->write(json_encode($objectMetadataPayload));
        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }
}