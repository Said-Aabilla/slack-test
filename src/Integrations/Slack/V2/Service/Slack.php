<?php

namespace App\Integrations\Slack\V2\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\ExternalUserMapping\Service\ExternalUserMappingHelper;
use App\Domain\Integration\Integration;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Domain\PhoneNumber\CustomerNumberDetails;
use App\Integrations\Service\AbstractIntegration;
use App\Intrastructure\Service\HttpClient;
use App\Settings\SettingsInterface;
use Exception;

class Slack extends AbstractIntegration
{
    const API_URL = 'https://slack.com/api';
    private ContactManager $contactManager;
    public SlackOAuth2Provider $oauthProvider;

    private const MAX_CONTACTS_TO_SEARCH = 3;

    public function __construct(
        HttpClient $httpClient,
        SettingsInterface $settings,
        IntegrationLoggerInterface $logger,
        ContactManager $contactManager
    ) {
        parent::__construct($httpClient, $settings, $logger);
        $this->contactManager = $contactManager;
        $this->setSlackOAuth2Provider($httpClient, $logger, $settings);
    }

    /**
     * @inheritDoc
     */
    public function getIntegrationName(): string
    {
        return 'SLACK';
    }

    public function request(
        Integration $integration,
        string $method,
        string $endPoint,
        array $dataToSend = [],
        array $anonymizedPrams = []
    ): array {
        $httpReturnCode = 1;
        $headers        = [
            'Content-Type:application/json; charset=utf-8',
            'Authorization: Bearer ' . $integration->getAccessToken()
        ];
        $url            = self::API_URL . '/' . $endPoint;
        if ($method === 'GET') {
            $response = $this->httpClient->get(
                $url,
                $dataToSend,
                $headers,
                $httpReturnCode,
                $anonymizedPrams,
                $this->getIntegrationName(),
                true
            );
        } else {
            $response = $this->httpClient->request(
                $method,
                $url,
                $dataToSend,
                $headers,
                $httpReturnCode,
                $anonymizedPrams,
                $this->getIntegrationName(),
                true
            );
        }
        if ($httpReturnCode >= 200 && $httpReturnCode <= 299) {
            return $response;
        }
        return [
            'http_return_code' => $httpReturnCode,
            'error'            => $response
        ];
    }

    private function post(
        Integration $integration,
        string $endPoint,
        array $dataToPost = [],
        array $anomynizedKey = []
    ): array {
        return $this->request($integration, 'POST', $endPoint, $dataToPost, $anomynizedKey);
    }

    /**
     * @param Integration $integration
     * @param string $endPoint
     * @param array $queryParams
     * @return array
     */
    public function get(Integration $integration, string $endPoint, array $queryParams = []): array
    {
        return $this->request($integration, 'GET', $endPoint, $queryParams);
    }

    public function getSynchronizedContact(
        CustomerNumberDetails $customerNumberDetails,
        int $teamId,
        int $userId
    ): ?IntegrationContactIdentity {
        $externalContact = $this->contactManager->getSynchronizedContacts(
            $teamId,
            $userId,
            $customerNumberDetails->e164,
            self::MAX_CONTACTS_TO_SEARCH,
            true
        );
        if (empty($externalContact)) {
            return null;
        }
        $contact                           = new IntegrationContactIdentity();
        $contact->id                       = $externalContact['integration_id'];
        $contact->name                     = $externalContact['firstname'] . ' ' . $externalContact['lastname'];
        $contact->nameWithNumber           = $contact->name . ' (' . $customerNumberDetails->e164 . ')';
        $contact->data['socialService']    = $externalContact['integration_name'] ?? '';
        $contact->data['socialProfileUrl'] = $externalContact['integration_url'] ?? '';

        return $contact;
    }

    /**
     * Get external user I'd from configuration, for DM of normal call/sms event
     *
     * @param Integration $integration
     * @param int $userId
     * @param string $userEmail
     * @return string|null
     */
    public function getSlackUserId(Integration $integration, int $userId, string $userEmail): ?string
    {
        $mappedUserId = ExternalUserMappingHelper::getUserFromUserMapping($integration->getConfiguration(), $userId);
        if ($mappedUserId) {
            return $mappedUserId;
        }
        $slackUserId = $this->getUserIdByEmail($integration, $userEmail);
        if (
            !is_null($slackUserId) &&
            ExternalUserMappingHelper::isExternalUserEmailIsDisabled(
                $integration->getConfiguration(),
                $userId,
                $slackUserId
        )) {
            return null;
        }
        return $slackUserId;
    }

    public function postSlackMessage(Integration $integration, string $channel, array $formattedMessage): array
    {
        return $this->post(
            $integration,
            'chat.postMessage',
            array_merge(
                $formattedMessage,
                [
                    'channel' => $channel
                ]
            ),
            ['attachments.0.blocks']
        );
    }

    /**
     * @param Integration $integration
     * @param string $userEmail
     * @return string|null
     */
    private function getUserIdByEmail(Integration $integration, string $userEmail): ?string
    {
        $user = $this->get($integration, 'users.lookupByEmail', ['email' => $userEmail]);
        return $user['user']['id'] ?? null;
    }

    /**
     * @param Integration $integration
     * @param string|null $cursor
     * @return array
     */
    public function getUserList(Integration $integration, ?string $cursor): array
    {
        $queryParams = [];
        if (!empty($cursor)) {
            $queryParams['cursor'] = $cursor;
        }
        return $this->get($integration, 'users.list', $queryParams);
    }

    /**
     * Map users Ringover - Slack, on same email address
     *
     * @param int $teamId
     * @param string $accessToken
     * @param array $userMapList value of: ringover_user_to_external['users']
     * @return array user mapping list
     * @throws Exception
     */
    public function autoMapRingoverSlackUsers(
        int $teamId,
        string $accessToken,
        array $userMapList
    ): array {

        // TO DO
        return [];
    }

    private function setSlackOAuth2Provider(HttpClient $httpClient, IntegrationLoggerInterface $logger, SettingsInterface $settings)
    {
        $this->oauthProvider = new SlackOAuth2Provider($httpClient, $logger, $settings);
    }
}
