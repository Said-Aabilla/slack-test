<?php

namespace App\Application\Actions\IntegrationFields;

use Exception;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;

class GetIntegrationFieldsObjectsAction extends IntegrationFieldsAction
{
    /**
     * @throws Exception
     */
    public function action(): Response
    {
        $integrationFieldsService = $this->getIntegrationFieldsService($this->integration);
        $this->response->getBody()->write(json_encode($integrationFieldsService->getObjectList()));
        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }
}