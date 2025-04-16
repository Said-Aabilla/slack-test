<?php

namespace App\Integrations\Service;

class GenericGetConfiguration extends AbstractGetConfiguration
{

    public function process(): array
    {
        return $this->integration->getConfiguration();
    }
}