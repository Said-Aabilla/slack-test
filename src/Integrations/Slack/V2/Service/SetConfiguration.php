<?php

namespace App\Integrations\Slack\V2\Service;

use App\Integrations\Service\AbstractSetConfiguration;

class SetConfiguration extends AbstractSetConfiguration
{
    public function process(array $newConfiguration, ?int $userId): array
    {
        return $newConfiguration;
    }
}