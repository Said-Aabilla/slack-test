<?php

namespace App\Integrations\SlackLight\V1\Service;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Domain\PhoneNumber\CustomerNumberDetails;
use App\Integrations\Slack\V2\Service\Slack;

class SlackLight extends Slack
{
    /**
     * @inheritDoc
     */
    public function getIntegrationName(): string
    {
        return 'SLACK_LIGHT';
    }

    public function getSynchronizedContact(
        CustomerNumberDetails $customerNumberDetails,
        int                   $teamId,
        int                   $userId
    ): ?IntegrationContactIdentity
    {
        $externalContact = $this->contactManager->getSynchronizedContacts(
            $teamId,
            $userId,
            $customerNumberDetails->e164,
            self::MAX_CONTACTS_TO_SEARCH,
            true,
            'SLACK_QUICKTALK'
        );
        if (empty($externalContact)) {
            return null;
        }
        $contact = new IntegrationContactIdentity();
        $contact->id = $externalContact['integration_id'];
        $contact->name = $externalContact['firstname'] . ' ' . $externalContact['lastname'];
        $contact->nameWithNumber = $contact->name . ' (' . $customerNumberDetails->e164 . ')';
        $contact->data['socialService'] = $externalContact['integration_name'] ?? '';
        $contact->data['socialProfileUrl'] = $externalContact['integration_url'] ?? '';

        return $contact;
    }
}
