<?php

namespace App\Application\Actions\IntegrationFields;

use App\Domain\Integration\IntegrationContactIdentity;
use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;

class GetIntegrationFieldsDataByIdAction extends IntegrationFieldsAction
{
    /**
     * @throws Exception
     */
    public function action(): Response
    {
        $id = $this->request->getAttribute('id');
        $object = $this->request->getAttribute('object');
        $callId = $this->request->getAttribute('call_id');
        $integrationFieldsService = $this->getIntegrationFieldsService($this->integration);
        if (!isset($this->integration->getConfiguration()['objectsFieldsMetadata'])) {
            return $this->respondWithError(
                'No fields configuration',
                'No fields configuration for this integration '. $this->integration->getIntegrationName()
            );
        }
        $contact = new IntegrationContactIdentity();
        $contact->id = $id;
        $contact->contactObjectType = $object;
        $contact->data = $this->request->getQueryParams();
        $objectFieldsData =  $integrationFieldsService->getObjectFieldsValue($contact);
        $this->response->getBody()->write(json_encode([
            "fields" => $objectFieldsData,
            "call_id" => $callId,
            "type" => "call-contact-data",
            "version" => 2.2
        ]));
        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }
}