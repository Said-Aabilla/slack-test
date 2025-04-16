<?php

namespace App\Application\Logger;

use Monolog\Logger;

class IntegrationLogger extends Logger implements IntegrationLoggerInterface
{

    public function integrationLog(string $code, string $message = '', array $payload = []): bool
    {
        $payload = array_merge(
            [
                'message' => $message
            ],
            $payload
        );

        $this->debug($code, $payload);
        return true;
    }


    public function contactSearchLog(string $contactNumber, int $contactCount = 0, array $details = []): bool
    {
        if ($contactCount === 0) {
            $message = 'Contact NOT found';
        } else {
            $message = 'Contact found';
        }

        return $this->integrationLog(
            'CONTACT_SEARCH',
            $message,
            [
                'search_contact_number' => $contactNumber,
                'found_contact_count'   => $contactCount,
                'details'               => $details
            ]
        );
    }

    public function userSearchLog($userMail, ?string $userExternalId = null, array $details = []): bool
    {
        if (empty($userExternalId)) {
            $message = 'User NOT found in the business tool ';
        } else {
            $message = 'User found in the business tool ';
        }

        return $this->integrationLog(
            'USER_SEARCH',
            $message,
            [
                'ringover_user_mail' => $userMail,
                'is_found'           => !empty($userExternalId),
                'user_external_id'   => $userExternalId,
                'details'            => $details
            ]
        );
    }

    public function createCallObjectLog(?string $objectId, array $details = []): bool
    {
        if (empty($objectId)) {
            $message = 'NO log created ';
        } else {
            $message = 'Log created successfully';
        }

        return $this->integrationLog(
            'OBJECT_CALL_CREATION',
            $message,
            [
                'is_created' => !empty($objectId),
                'object_id'  => $objectId,
                'details'    => $details
            ]
        );
    }

    public function updateCallObjectLog(?string $objectId, bool $isUpdated = false, array $details = []): bool
    {
        if (empty($isUpdated)) {
            $message = 'NO log updated ';
        } else {
            $message = 'Log updated successfully';
        }

        return $this->integrationLog(
            'OBJECT_CALL_UPDATE',
            $message,
            [
                'is_updated' => $isUpdated,
                'object_id'  => $objectId,
                'details'    => $details
            ]
        );
    }

    public function tokenErrorLog(string $message, array $details = []): bool
    {
        return $this->integrationLog(
            'TOKEN_ERROR',
            $message,
            [
                'details' => $details
            ]
        );
    }

    public function failFilterLog(string $message = '', array $details = []): bool
    {
        return $this->integrationLog(
            'FILTER_FAIL',
            $message,
            $details
        );
    }

    public function createContactLog(?string $contactId = null, array $details = []): bool
    {
        if (empty($contactId)) {
            $message = 'Failed to create contact';
        } else {
            $message = 'Contact created successfully';
        }

        return $this->integrationLog(
            'CONTACT_CREATION',
            $message,
            [
                'is_created' => !empty($contactId),
                'contact_id' => $contactId,
                'details'    => $details
            ]
        );
    }
}
