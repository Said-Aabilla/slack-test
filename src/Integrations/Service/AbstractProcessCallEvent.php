<?php

namespace App\Integrations\Service;

use App\Domain\CallEvent\Call;
use App\Domain\Integration\Integration;

abstract class AbstractProcessCallEvent extends AbstractProcess
{
    /**
     * @var Call
     */
    protected Call $callEvent;

    /**
     * @param Integration $integration
     * @param Call $callEvent
     * @return void
     */
    public function __process(
        Integration $integration,
        Call $callEvent
    ) {
        $this->integration = $integration;
        $this->callEvent = $callEvent;

        $data = $this->process();
        if ($data && is_array($data)) {
            $this->logIntegrationCallObject($data);
        }
    }

    /**
     * @param array|bool|null $data
     *
     * Insert event into integrations_call_object_history
     */
    private function logIntegrationCallObject($data)
    {
        $callId = $this->callEvent->callId;
        $channelId = $this->callEvent->channelId;
        $teamId = $this->integration->getTeamId();
        $integrationName = $this->integration->getIntegrationName();
        $objectList = $this->integrationRepository->getIntegrationCallObjectByCallId(
            $callId,
            $channelId,
            $teamId,
            $integrationName
        );
        if ($objectList && isset($objectList[$integrationName][0]["id"])) {
            $id = $objectList[$integrationName][0]["id"];
            $this->integrationRepository->updateIntegrationCallObject(
                $integrationName,
                $callId,
                $channelId,
                $teamId,
                $data,
                $id
            );
        } else {
            $this->integrationRepository->saveIntegrationCallObject(
                $integrationName,
                $callId,
                $channelId,
                $teamId,
                $data
            );
        }
    }


    /**
     * @param int $ringoverUserId
     * @return string|null
     */
    protected function getUserFromUserMapping(int $ringoverUserId): ?string
    {
        $mappedInveniasUser = $this->integration->getConfiguration(
        )['ringover_user_to_external']['users'][$ringoverUserId] ?? null;

        if (isset($mappedInveniasUser['enabled']) && $mappedInveniasUser['enabled']) {
            $mappedInveniasUserId =  $mappedInveniasUser['externalId'];
        } else {
            $mappedInveniasUserId = null;
        }

        $this->logger->userSearchLog('-', $mappedInveniasUserId, ['source' => 'user mapping', 'id' => $ringoverUserId]);
        return $mappedInveniasUserId;
    }


    /**
     * Code métier de gestion de l'évènement téléphonique
     * @return mixed
     */
    abstract public function process();

}
