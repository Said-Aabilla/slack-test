<?php

namespace App\Application\Actions\ContactSync;

use App\Application\Actions\Action;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\ContactSync\Service\ManageSync;
use Psr\Http\Message\ResponseInterface as Response;

class GetLastContactSyncTaskAction extends Action
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

        $lastContactSyncTasks = $this->manageSync->getLastSyncTasksForIntegrationId($integrationId);
        if (empty($lastContactSyncTasks)) {
            return $this->response->withStatus(204);
        }

        return $this->respondWithData($lastContactSyncTasks);
    }
}