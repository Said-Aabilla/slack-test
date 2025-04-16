<?php

namespace App\Application\Actions\Integration;

use App\Application\Actions\IntegrationAction;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;

class ListUsersAction extends IntegrationAction
{
    protected function action(): Response
    {
        try {
            /** @var \App\Integrations\Service\AbstractGetConfiguration $listUsersService */
            $listUsersService = $this->getProcessIntegrationService(
                'ListUsers'
            );

            if (empty($listUsersService)) {
                return $this->response->withStatus(404, 'Service ListUsers not found');
            }

            /** @var array [id, email, fullName] */
            $userList = $listUsersService->__process($this->integration);
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            return $this->respondWithError(
                'INTERNAL_ERROR',
                $exception->getMessage() ?? '',
                0 < $exception->getCode() ? $exception->getCode() : 500
            );
        }

        return $this->responseRawWithData($userList);
    }
}