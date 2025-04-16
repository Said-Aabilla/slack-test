<?php

namespace App\Integrations\SlackLight\V1\Service;

use App\Integrations\Service\AbstractSetConfiguration;

class SetConfiguration extends AbstractSetConfiguration
{
    public function process(array $newConfiguration, ?int $userId): array
    {
        return $newConfiguration;
    }
}