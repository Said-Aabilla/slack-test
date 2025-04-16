<?php

namespace App\Integrations\SlackLight\V1\Service;

use App\Integrations\Service\AbstractGetConfiguration;

class GetConfiguration extends AbstractGetConfiguration
{
    public function process(): array
    {
        return $this->integration->getConfiguration();
    }
}