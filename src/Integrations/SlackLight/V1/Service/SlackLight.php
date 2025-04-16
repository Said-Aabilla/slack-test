<?php

namespace App\Integrations\SlackLight\V1\Service;
use App\Integrations\Slack\V2\Service\Slack;

class SlackLight extends Slack
{
    /**
     * @inheritDoc
     */
    public function getIntegrationName(): string
    {
        return 'SLACK_LIGHT';
    }


}
