<?php

namespace App\Integrations\Service;
use App\Domain\Integration\Integration;

class GenericDeleteIntegration extends AbstractDeleteIntegration
{
    public function process(Integration $integration, string $authorizationHeader): bool
    {
        return true;
    }
}