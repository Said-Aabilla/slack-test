<?php

namespace App\Integrations\Slack\V2\Service;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Integration\Integration;
use App\Intrastructure\Service\HttpClient;
use App\Settings\SettingsInterface;
use DateTime;
use Fig\Http\Message\StatusCodeInterface;

class SlackOAuth2Provider
{
    private const SLACK_OAUTH_URL = 'https://slack.com/api/oauth.v2.access';
    private const SLACK_AUTHORIZE_URL = 'https://slack.com/oauth/v2/authorize';
    private const SCOPE = 'users:read,channels:read,users:read.email,chat:write,channels:history';

    private IntegrationLoggerInterface $logger;
    private HttpClient $httpClient;
    private SettingsInterface $settings;

    public function __construct(
        HttpClient                 $httpClient,
        IntegrationLoggerInterface $logger,
        SettingsInterface          $settings
    )
    {
        $this->settings = $settings;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }


    public function generateToken(Integration $integration, string $authCode, string $settings_access_key = 'slack')
    {

        $clientId = $this->settings['integrations'][$settings_access_key]['client_id'];
        $clientSecret = $this->settings['integrations'][$settings_access_key]['client_secret'];
        $redirectUri = $this->settings['integrations'][$settings_access_key]['redirect_url'];


        $data = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $authCode,
            'redirect_uri' => $redirectUri
        ];

        // Encode data as form-urlencoded
        $encodedData = http_build_query($data);

        return $this->handleTokenRequest($integration, $encodedData, ['client_secret']);
    }

    private function handleTokenRequest(Integration $integration, string $encodedData, array $sensitiveFields): ?array
    {
        try {
            $httpCode = 0;
            $response = $this->httpClient->request(
                'POST',
                self::SLACK_OAUTH_URL,
                $encodedData,
                ['Content-Type: application/x-www-form-urlencoded'],
                $httpCode,
                $sensitiveFields,
                $integration->getIntegrationName(),
                true
            );

            if (!$this->isValidResponse($httpCode, $response)) {
                $this->logger->debug($integration->getIntegrationName() . ': ' . 'Invalid token response', [
                    'response' => $response,
                    'integration' => $integration->getIntegrationName()
                ]);

                return null;
            }

            $integration->setToken($response['access_token']);

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Token request failed', [
                'error' => $e->getMessage(),
                'integration' => $integration->getIntegrationName()
            ]);
            return null;
        }
    }

    private function isValidResponse(int $statusCode, array $response): bool
    {
        return $statusCode >= StatusCodeInterface::STATUS_OK &&
            $statusCode <= StatusCodeInterface::STATUS_IM_USED &&
            !empty($response['access_token']);
    }

}