<?php

namespace App\Integrations\Service;

use App\Domain\Integration\Integration;
use App\Domain\PhoneNumber\CustomerNumberDetails;
use App\Domain\PhoneNumber\Service\PhoneNumberHelper;
use Exception;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

abstract class AbstractGetContactOwner extends AbstractProcess
{
    /**
     * @param Integration $integration
     * @param $e164PhoneNumber
     * @return mixed
     */
    public function __process(
        Integration $integration,
                    $e164PhoneNumber
    ): int {
        $smartRoutingEnabled = $this->hasSmartRoutingEnabled($integration);
        if (!$smartRoutingEnabled) {
            $this->logger->integrationLog(
                "SMART_ROUTING_NOT_ENABLED"
            );
            return false;
        }

        $phoneNumberInE164 = PhoneNumberHelper::getInstance()->parseToCustomerNumberDetails($e164PhoneNumber);
        if ($e164PhoneNumber === null) {
            $this->logger->integrationLog(
                "BAD_PHONE_NUMBER",
                "Impossible d'analyser le numéro du contact",
                [
                    "phone_number" => $e164PhoneNumber
                ]
            );
            return false;
        }

        $this->integration = $integration;
        return $this->process($phoneNumberInE164);
    }

    public function hasSmartRoutingEnabled(Integration $integration): bool
    {
        return $integration->getConfiguration()['smart_routing_enabled'] ?? true;
    }


    /**
     * Code métier de récupération du propriétaire d'un contact
     * @return mixed
     */
    abstract public function process(CustomerNumberDetails $customerNumberDetails);
}
