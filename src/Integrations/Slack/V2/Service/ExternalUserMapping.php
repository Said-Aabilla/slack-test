<?php

namespace App\Integrations\Slack\V2\Service;

use App\Domain\ExternalUserMapping\DTO\ExternalUserDTO;
use App\Integrations\Service\AbstractExternalUserMapping;
use Exception;

/**
 * @property Slack $integrationService
 */
class ExternalUserMapping extends AbstractExternalUserMapping
{

    /**
     * @inheritDoc
     * @return ExternalUserDTO[]
     * @throws Exception
     */
    public function getExternalUserList(): array
    {
        return $this->integrationService->getSlackUsers($this->integration);
    }
}