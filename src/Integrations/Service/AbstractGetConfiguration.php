<?php

namespace App\Integrations\Service;

use App\Domain\Integration\Integration;

abstract class AbstractGetConfiguration extends AbstractProcess
{
    /**
     * @param \App\Domain\Integration\Integration $integration
     * @return array
     */
    public function __process(
        Integration $integration
    ): array {
        $this->integration = $integration;
        $result =  [
            'team_id' => $this->integration->getTeamId(),
            'team_token_id'  => $this->integration->getId(),
            'team_service_data' => $this->process(),
            'team_service_name' => $this->integration->getIntegrationName()
        ];

        if(!isset($result['team_service_data']['callType']['invalidcall']['hangup']) ) {
            $result['team_service_data']['callType']['invalidcall']['hangup'] = true;
        }
        return $result;
    }

    /**
     * @return array
     */
    abstract public function process(): array;
}
