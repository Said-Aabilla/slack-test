<?php

namespace App\Application\Actions\ContactSync;

use App\Application\Actions\Action;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\ContactSync\Service\ManageSync;
use Psr\Http\Message\ResponseInterface as Response;

class RestartContactSyncAction extends Action
{

    /**
     * @var \App\Domain\ContactSync\Service\ManageSync
     */
    private ManageSync $manageSync;

    public function __construct(IntegrationLoggerInterface $logger, ManageSync $manageSync)
    {
        parent::__construct($logger);
        $this->manageSync = $manageSync;
    }


    /**
     * @throws \Exception
     */
    protected function action(): Response
    {
        $integrationId = intval($this->request->getAttribute('integration_id'));
        if (empty($integrationId)) {
            return $this->respondWithError(
                "BAD_PARAMETER",
                "L'id de l'intégration doit être un entier positif"
            );
        }

        $restartReport = $this->manageSync->restartSyncAfterError(
            $this->request->getAttribute('team_id'),
            $integrationId
        );
        if (in_array($restartReport['details']['code'], ['NO_SYNC_TASK', 'NO_SYNC_ERROR_TASK'])) {
            return $this->response->withStatus(204);
        }

        if ($restartReport['details']['code'] === 'SYNC_TASK_UPDATE_ERROR') {
            return $this->respondWithError(
                "SYNC_TASK_UPDATE_ERROR",
                $restartReport['details']['message']
            );
        }

        return $this->response->withStatus(204);
    }
}