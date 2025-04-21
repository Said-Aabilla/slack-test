<?php

namespace App\Integrations\Slack\V2\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\ExternalUserMapping\Service\ExternalUserMappingHelper;
use App\Domain\Integration\Integration;
use App\Domain\Integration\IntegrationContactIdentity;
use App\Domain\PhoneNumber\CustomerNumberDetails;
use App\Integrations\Service\AbstractIntegration;
use App\Intrastructure\Persistence\UserRepository;
use App\Intrastructure\Service\HttpClient;
use App\Settings\SettingsInterface;
use Exception;

class Slack extends AbstractIntegration
{

    protected ContactManager $contactManager;
    public SlackOAuth2Provider $oauthProvider;
    protected UserRepository $userRepository;
    public const API_URL = 'https://slack.com/api';
    public const MAX_CONTACTS_TO_SEARCH = 3;

    public function __construct(
        HttpClient                 $httpClient,
        SettingsInterface          $settings,
        IntegrationLoggerInterface $logger,
        ContactManager             $contactManager,
        UserRepository $userRepository

    )
    {
        parent::__construct($httpClient, $settings, $logger);
        $this->contactManager = $contactManager;
        $this->userRepository = $userRepository;
        $this->setSlackOAuth2Provider($httpClient, $logger, $settings);
    }

    /**
     * @inheritDoc
     */
    public function getIntegrationName(): string
    {
        return 'SLACK';
    }

    private function setSlackOAuth2Provider(HttpClient $httpClient, IntegrationLoggerInterface $logger, SettingsInterface $settings)
    {
        $this->oauthProvider = new SlackOAuth2Provider($httpClient, $logger, $settings);
    }


    public function request(
        Integration $integration,
        string      $method,
        string      $endPoint,
        array       $dataToSend = [],
        array       $anonymizedPrams = []
    ): array
    {
        $httpReturnCode = 1;
        $headers = [
            'Content-Type:application/json; charset=utf-8',
            'Authorization: Bearer ' . $integration->getAccessToken()
        ];
        $url = self::API_URL . '/' . $endPoint;
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
            'error' => $response
        ];
    }

    private function post(
        Integration $integration,
        string      $endPoint,
        array       $dataToPost = [],
        array       $anomynizedKey = []
    ): array
    {
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
        int                   $teamId,
        int                   $userId
    ): ?IntegrationContactIdentity
    {
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

        return $this->mapExternalToIntegrationContactIdentity($externalContact, $customerNumberDetails->e164);

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
    public function updateSlackMessage(Integration $integration, string $channel,string $ts, array $formattedMessage): array
    {
        return $this->post(
            $integration,
            'chat.update',
            array_merge(
                $formattedMessage,
                [
                    'channel' => $channel,
                    'ts'      => $ts
                ],
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
     * Lists Slack channels by page.
     * For listing only public channels use type = public_channel
     *
     * @param Integration $integration The integration object containing access token
     * @param string $cursor Pagination cursor
     * @param string|null $type Optional channel type filter (e.g., 'public_channel')
     * @return array [channels[{id: name}], next_cursor]
     * @throws Exception
     */
    public function listSlackChannels(Integration $integration, string $cursor = '', ?string $type = null): array
    {
        $params = [
            'exclude_archived' => true,
            'limit' => 999
        ];

        if (!empty($cursor)) {
            $params['cursor'] = $cursor;
        }

        if (!empty($type)) {
            $params['types'] = $type;
        }

        $response = $this->get($integration, 'conversations.list', $params);

        // Check for errors in the response
        if (!isset($response['ok']) || $response['ok'] === false) {
            throw new Exception('SLACK: ' . ($response['error'] ?? 'Error listing channels'),
                $response['http_return_code'] ?? 500);
        }

        $result = [
            'channels' => [],
            'next_cursor' => $response['response_metadata']['next_cursor'] ?? ''
        ];

        foreach ($response['channels'] as $channel) {
            $result['channels'][$channel['id']] = ($channel['is_private'] ? '' : '# ') . $channel['name'];
        }

        return $result;
    }


    /**
     * Synchronize ringover and slack users, complete user map.
     * And save new user mapping list into serviceData
     *
     * @param int $teamId
     * @param string $accessToken
     * @param array $userMapList
     * @return array Updated user mapping list
     * @throws Exception
     */
    public function autoMapRingoverSlackUsers(
        int         $teamId,
        Integration $integration,
        array       $userMapList
    ): array
    {
        // Get Ringover users from team
        $ringoverUsers = $this->userRepository->getUsersByTeamId($teamId);

        // Get Slack users with pagination
        $externalUsers = $this->getSlackUsers($integration);

        // Extract mapped user IDs, for Ringover and external
        $mappedRingoverUsers = [];
        $mappedExternalUsers = [];

        if (!empty($userMapList)) {
            foreach ($userMapList as $ringoverUserId => $externalInfo) {
                $externalUserId = $externalInfo['externalId'] ?? '';
                if (empty($externalUserId)) {
                    continue;
                }
                $mappedRingoverUsers[] = $ringoverUserId;
                $mappedExternalUsers[] = $externalUserId;
            }
        }

        // Prepare available users for Ringover and external
        $availableRingoverUsers = [];
        $availableExternalUsers = [];

        // Skip already mapped users and form arrays with email and id
        foreach ($ringoverUsers as $rUser) {
            if (in_array($rUser['id'], $mappedRingoverUsers)) {
                continue;
            }
            $availableRingoverUsers[$rUser['email']] = $rUser['id'];
        }

        foreach ($externalUsers as $eUser) {
            if (in_array($eUser['id'], $mappedExternalUsers)) {
                continue;
            }
            $availableExternalUsers[$eUser['email']] = $eUser['id'];
        }

        // Return early if no available Ringover users to match
        if (empty($availableRingoverUsers)) {
            return $userMapList;
        }

        // Loop available ringover users and map with same email addresses
        foreach ($availableRingoverUsers as $email => $id) {
            // If external user has the same email address
            if (isset($availableExternalUsers[$email])) {
                // Insert new user mapping pairs
                $userMapList[$id] = [
                    'externalId' => $availableExternalUsers[$email],
                    'enabled' => true
                ];
            }
        }

        return $userMapList;
    }


    public function getSlackUsers(Integration $integration): array
    {
        $externalUsers = [];
        $cursor = '';
        do {
            $rawSlackUsers = $this->getUserList($integration, $cursor);
            $cursor = $rawSlackUsers['response_metadata']['next_cursor'] ?? '';

            // Extract only needed members data
            foreach ($rawSlackUsers['members'] ?? [] as $member) {
                if ($member['deleted'] || $member['is_bot'] || !isset($member['profile']['email'])) {
                    continue;
                }

                $externalUsers[] = [
                    'id' => $member['id'],
                    'email' => $member['profile']['email'],
                    'fullName' => $member['profile']['real_name'],
                    'photo' => $member['profile']['image_32']
                ];
            }
        } while (!empty($cursor));


        return $externalUsers;
    }

    public function mapExternalToIntegrationContactIdentity(array $externalContact, string $e164CustomerNumber): IntegrationContactIdentity
    {

        $contact = new IntegrationContactIdentity();
        $contact->id = $externalContact['integration_id'];
        $contact->name = $externalContact['firstname'] . ' ' . $externalContact['lastname'];
        $contact->nameWithNumber = $contact->name . ' (' . $e164CustomerNumber . ')';
        $contact->data['socialService'] = $externalContact['integration_name'] ?? '';
        $contact->data['socialProfileUrl'] = $externalContact['integration_url'] ?? '';

        return $contact;
    }

    public function emptyIntegrationContactIdentity(string $e164CustomerNumber): IntegrationContactIdentity
    {
        $contact = new IntegrationContactIdentity();
        $contact->nameWithNumber = $e164CustomerNumber;
        $contact->data =
            [
                'socialService'    => '',
                'socialProfileUrl' => ''
            ];
        return $contact;
    }


}
