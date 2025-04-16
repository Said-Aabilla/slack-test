<?php

namespace App\Integrations\Service;

use App\Domain\Integration\Integration;

/**
 * @property Integration $integration
 * @property string $integrationAction
 */
abstract class AbstractListDropDown extends AbstractProcess
{
    /**
     * @param Integration $integration
     * @return array
     */
    public function __process(Integration $integration, string $integrationAction): array {
        $this->integration = $integration;
        return $this->process($integration, $integrationAction);
    }

    /**
     * @return array
     */
    abstract public function process(Integration $integration, string $integrationAction): array;
}
