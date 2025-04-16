<?php

namespace App\Application\Actions\ContactSync;

use App\Application\Actions\Action;
use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\ContactSync\Service\ManageSync;
use Psr\Http\Message\ResponseInterface as Response;

class SoftRestartContactSyncAction extends Action
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

    protected function action(): Response
    {
        $integrationId = intval($this->request->getAttribute('integration_id'));
        if (empty($integrationId)) {
            return $this->respondWithError(
                "BAD_PARAMETER",
                "L'id de l'intégration doit être un entier positif"
            );
        }

        $restartReport = $this->manageSync->softRestartSync($integrationId);

        if ($restartReport['details']['code'] === 'SOFT_RESTART_ERROR') {
            return $this->respondWithError(
                "SOFT_RESTART_ERROR",
                $restartReport['details']['message']
            );
        }

        return $this->response->withStatus(204);
    }
}