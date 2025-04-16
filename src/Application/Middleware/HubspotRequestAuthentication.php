<?php

namespace App\Application\Middleware;

use App\Application\Logger\IntegrationLoggerInterface;
use App\Domain\Exception\BadConfigurationException;
use App\Settings\SettingsInterface;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpUnauthorizedException;

class HubspotRequestAuthentication implements MiddlewareInterface
{

    /**
     * @var SettingsInterface
     */
    private SettingsInterface $settings;

    private string $hubspotClientSecret = '';

    /**
     * @throws \Exception
     */
    public function __construct(
        SettingsInterface $settings,
        IntegrationLoggerInterface $logger
    ) {
        $this->settings = $settings;

        if (empty($this->settings['integrations']['hubspot']['client_secret'])) {
            $logger->integrationLog(
                'BAD_CONFIGURATION',
                'Client secret hubspot manquant dans le fichier de configuration'
            );
            throw new BadConfigurationException('Missed configuration');
        }

        $this->hubspotClientSecret = $this->settings['integrations']['hubspot']['client_secret'] ?? '';
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isValidHubspotRequest($request)) {
            throw new HttpUnauthorizedException($request, 'Invalid hubspot request');
        }

        return $handler->handle($request);
    }

    /**
     * Valide une requête faite par Hubspot vers l'api RingOver
     * @param \Psr\Http\Message\ServerRequestInterface $request Objet qui représente la requête faite par Hubspot à
     * l'api RingOver
     * @return bool True si la requête envoyée par Hubspot est considérée comme valide, false sinon
     */
    public function isValidHubspotRequest(ServerRequestInterface $request): bool
    {
        // V3 des signatures hubspot
        if (isset($request->getHeader('X-HubSpot-Signature-v3')[0])) {
            return $this->isValidHubspotRequestV3($request);
        }

        return $this->isValidHubspotRequestV1($request);
    }

    public function isValidHubspotRequestV1(ServerRequestInterface $request): bool
    {
        $httpMethod = $request->getMethod();
        $requestUri = $request->getUri();
        $requestBody = $request->getBody()->getContents() ?? '';

        // V1 des signatures hubspot
        $hubspotSignature = $request->getHeader('X-HubSpot-Signature')[0] ?? '';

        $completeStringToHash = $this->hubspotClientSecret . $httpMethod . $requestUri . $requestBody;
        $sha256Hash = hash('sha256', $completeStringToHash);

        return $sha256Hash === $hubspotSignature;
    }

    public function isValidHubspotRequestV3(ServerRequestInterface $request): bool
    {
        $httpMethod = $request->getMethod();
        $requestUri = $request->getUri();
        $requestBody = $request->getBody()->getContents() ?? '';

        $hubspotRequestTimestampHeader = $request->getHeader('X-HubSpot-Request-Timestamp')[0];
        $stringToHash = $httpMethod . $requestUri . $requestBody . $hubspotRequestTimestampHeader;
        $sha256Hash = hash_hmac('sha256', $stringToHash, $this->hubspotClientSecret, true);
        $encodedSha256Hash = base64_encode($sha256Hash);

        return $encodedSha256Hash === $request->getHeader('X-HubSpot-Signature-v3')[0];
    }
}
