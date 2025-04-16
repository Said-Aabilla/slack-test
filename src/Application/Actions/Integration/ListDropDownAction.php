<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\IntegrationAction;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ListDropDownAction extends IntegrationAction
{

   public function __invoke(Request $request, Response $response, array $args): Response
   {
       $this->request = $request;
       $this->response = $response;
       $this->args = $args;

       return parent::__invoke($request, $response, $args);

   }

    protected function action(): Response
    {

        try {
            /** @var \App\Integrations\Service\AbstractGetConfiguration $listUsersService */
            $listService = $this->getProcessIntegrationService(
                'ListDropDown'
            );

            if (empty($listService)) {
                return $this->response->withStatus(404, 'Service ListDropDown not found');
            }

            /** @var array [$key => $value] */
            $listData = $listService->__process($this->integration,  $this->args['integration_action']);

        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                $exception->getMessage() ?? '',
                0 < $exception->getCode() ? $exception->getCode() : 500
            );
        }

        return $this->responseRawWithData($listData);
    }
}