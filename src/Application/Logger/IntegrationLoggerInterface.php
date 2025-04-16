<?php

namespace App\Application\Logger;

use Psr\Log\LoggerInterface;

interface IntegrationLoggerInterface extends LoggerInterface
{
    public function integrationLog(string $code, string $message = '', array $payload = []): bool;

    public function contactSearchLog(string $contactNumber, int $contactCount = 0, array $details = []): bool;

    public function createContactLog(?string $contactId = null, array $details = []): bool;

    public function failFilterLog(string $message = '', array $details = []): bool;

    public function tokenErrorLog(string $message, array $details = []): bool;

    public function createCallObjectLog(?string $objectId, array $details = []): bool;

    public function updateCallObjectLog(?string $objectId, bool $isUpdated = false, array $details = []): bool;

    public function userSearchLog($userMail, ?string $userExternalId = null, array $details = []): bool;

}
