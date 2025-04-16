<?php

namespace App\Integrations\Service;

class GenericSetConfiguration extends AbstractSetConfiguration
{

    public function process(array $newConfiguration, ?int $userId): array
    {
        return $newConfiguration;
    }
}