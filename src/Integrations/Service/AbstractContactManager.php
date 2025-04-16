<?php

namespace App\Integrations\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\CallEvent\Call;
use App\Domain\Integration\Integration;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Intrastructure\Service\WebsocketClient;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

abstract class AbstractContactManager
{
    /**
     * @var WebsocketClient
     */
    private WebsocketClient $websocketClient;
    /**
     * @var IntegrationLoggerInterface
     */
    private IntegrationLoggerInterface $logger;

    public function __construct(
        WebsocketClient            $websocketClient,
        IntegrationLoggerInterface $logger
    ) {
        $this->websocketClient = $websocketClient;
        $this->logger = $logger;
    }

    public function sendContactToRingoverDialer(
        string                     $integrationName,
        Call                       $call,
        IntegrationContactIdentity $contactIdentity
    ) {
        $contactObjectForDialer = $this->createRingoverCallContactObjectFromEntity(
            $integrationName,
            $call,
            $contactIdentity,
            $call->integrations[$integrationName]
        );

        if (empty($contactObjectForDialer)) {
            return;
        }

        $userToken = $call->firstRingoverUser['token'];

        $this->websocketClient->sendMessageToUserByUserToken(
            $userToken,
            'call-contact',
            $contactObjectForDialer
        );
    }

    public function createRingoverContactPhoneFromCall(Call $call): ?array
    {
        try {
            $phoneUtil = PhoneNumberUtil::getInstance();
            $phoneNumberProto = $phoneUtil->parse($call->e164CustomerNumber);

            return $this->createRingoverContactPhone(
                $phoneUtil->getRegionCodeForCountryCode($phoneNumberProto->getCountryCode()),
                $call->customerNumberDetails['e164'],
                $call->customerNumberDetails['national'],
                $call->customerNumberDetails['international'],
                $phoneUtil->getNumberType($phoneNumberProto) === PhoneNumberType::MOBILE ? 'mobile' : 'other'
            );
        } catch (NumberParseException $numberParseException) {
            $this->logger->integrationLog(
                'NUMBER_PARSE_EXCEPTION',
                $numberParseException->getMessage(),
                [
                    'e164_phone_number' => $call->e164CustomerNumber
                ]
            );

            return null;
        }
    }


    public function createRingoverCallContactObjectFromEntity(
        string                     $integrationName,
        Call                       $call,
        IntegrationContactIdentity $contactIdentity,
        Integration                $integration
    ): array {
        $formattedPhoneNumber = $this->createRingoverContactPhoneFromCall($call);
        if (empty($formattedPhoneNumber)) {
            return [];
        }

        $familyName = '';
        $givenName = $contactIdentity->name;
        $companyName = $contactIdentity->companyName ?? '';
        $contactExternalId = $contactIdentity->id;
        $contactUrl = $this->getContactURL($call, $contactIdentity, $integration);

        return $this->createRingoverCallContactObject(
            $integrationName,
            $call->callId,
            $call->firstRingoverUser['id'],
            $givenName,
            $familyName,
            $companyName,
            $formattedPhoneNumber,
            $contactExternalId,
            $contactUrl
        );
    }

    public function createRingoverContactPhone(
        $countryCode,
        $e164PhoneNumber,
        $nationalPhoneNumber,
        $internationalPhoneNumber,
        $type
    ): array {
        return [
            'id'           => substr($e164PhoneNumber, 1),
            'country'      => $countryCode,
            'flag_picture' => '',
            'label'        => null,
            'color'        => null,
            'format'       =>
                [
                    'e164'              => $e164PhoneNumber,
                    'international'     => $internationalPhoneNumber,
                    'international_alt' => substr($e164PhoneNumber, 1),
                    'national'          => $nationalPhoneNumber,
                    'national_alt'      => str_replace(' ', '', $nationalPhoneNumber),
                ],
            'type'         => $type,
        ];
    }

    public function createRingoverCallContactObject(
        $integrationName,
        $callId,
        $ringoverUserId,
        $givenName,
        $familyName,
        $companyName,
        $formattedPhoneNumber,
        $contactExternalId,
        $contactUrl
    ): array {
        return [
            'id'                => 1, // Identifiant obligatoire mais fictif
            'name'              =>
                [
                    'given_name'        => $givenName,
                    'family_name'       => $familyName,
                    'formatted'         => $givenName . ' ' . $familyName,
                    'formatted_company' => $givenName . ' ' . $familyName
                ],
            'company'           => $companyName,
            'profile_picture'   => '',
            'is_shared'         => false,
            'is_ringover'       => false,
            'owner_id'          => $ringoverUserId,
            'phone_numbers'     =>
                [
                    0 => $formattedPhoneNumber
                ],
            'alias'             => null,
            'social_service_id' => $contactExternalId,
            'social_service'    => strtoupper($integrationName),
            'social_profile'    => $contactUrl,
            'social_img'        => strtolower($integrationName) . '.svg',
            'color'             => '',
            'presence'          => null,
            'call_id'           => $callId,
        ];
    }

    abstract public function getContactURL(
        Call                       $call,
        IntegrationContactIdentity $contactIdentity,
        Integration                $integration
    ): string;
}
