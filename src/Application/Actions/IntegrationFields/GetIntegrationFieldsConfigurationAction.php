<?php

namespace App\Application\Actions\IntegrationFields;

use App\Application\Actions\IntegrationAction;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface as Response;

class GetIntegrationFieldsConfigurationAction extends IntegrationAction
{

    /**
     * @throws \Exception
     */
    protected function action(): Response
    {
        $this->response->getBody()->write(json_encode($this->integration->getConfiguration()['objectsFieldsMetadata'] ?? []));
        return $this->response->withStatus(StatusCodeInterface::STATUS_OK);
    }
}