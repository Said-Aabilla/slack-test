<?php

namespace App\Integrations\Slack\V2\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\CallEvent\CallStatus;
use App\Domain\CallEvent\Service\CallHelper;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Integrations\Service\AbstractProcessCallEvent;
use App\Integrations\Slack\V2\Service\ContactManager;
use App\Integrations\SlackLight\V1\Service\SlackLight;
use App\Intrastructure\Persistence\IntegrationRepository;
use App\Intrastructure\Service\ContactV4Workers;

/** @property SlackLight $integrationService */
class ProcessCallEvent extends AbstractProcessCallEvent
{
    private ContactManager $contactManager;
    private CallHelper $callHelper;

    public function __construct(
        ContactManager             $contactManager,
        CallHelper                 $callHelper,
        IntegrationRepository      $integrationRepository,
        IntegrationLoggerInterface $logger
    ) {
        parent::__construct($integrationRepository, $logger);
        $this->contactManager = $contactManager;
        $this->callHelper = $callHelper;
    }

    public function process(): ?bool
    {
        if (
            !$this->callHelper->callFilterV2(
                $this->callEvent,
                $this->integration->getConfiguration(),
                [CallStatus::INCOMING, CallStatus::INCALL, CallStatus::DIALED, CallStatus::MISSED, CallStatus::HANGUP]
            )
        ) {
            return false;
        }

        return $this->processCallEvent();
    }



    private function processCallEvent(): bool
    {
        $inCall = $this->callHelper->getCalculatedStatusOfCall($this->callEvent) === CallStatus::INCALL;
        $incomingOrDialingOut = $this->callHelper->isIncomingOrDialingOut($this->callEvent);
        $hangupMissed = in_array($this->callEvent->status, [CallStatus::HANGUP, CallStatus::MISSED]);

        return true;
    }
}