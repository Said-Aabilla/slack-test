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
        $externalUserList = [];
        $cursor = null;
        do {
            $rawResult = $this->integrationService->getUserList($this->integration, $cursor);
            $members   = $rawResult['members'] ?? [];
            if (empty($members)) {
                return $externalUserList;
            }
            // Cursor can be ""
            $cursor = $rawResult['response_metadata']['next_cursor'] ?? null;
            foreach ($members as $member) {
                if (!$member['deleted'] && !$member['is_bot'] && isset($member['profile']['email'])) {
                    $externalUserList[] = new ExternalUserDTO(
                        $member['id'],
                        $member['profile']['email'],
                        $member['profile']['real_name'],
                        $member['profile']['image_32']
                    );
                }
            }
        } while (!empty($cursor));
        return $externalUserList;
    }
}