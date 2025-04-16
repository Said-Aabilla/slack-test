<?php

namespace App\Integrations\Service;

use App\Domain\Integration\Integration;
use App\Domain\PresenceEvent\PresenceEvent;

abstract class AbstractProcessPresenceEvent extends AbstractProcess
{
    /**
     * @var PresenceEvent
     */
    protected PresenceEvent $presenceEvent;

    /**
     * @param Integration $integration
     * @param PresenceEvent $presenceEvent
     * @return void
     */
    public function __process(
        Integration $integration,
        PresenceEvent $presenceEvent
    ) {
        $this->integration   = $integration;
        $this->presenceEvent = $presenceEvent;

        $data = $this->process();
        if ($data && is_array($data)) {
            //
        }
    }

    /**
     * Code métier de gestion de l'évènement de présence
     * @return mixed
     */
    abstract public function process();
}
