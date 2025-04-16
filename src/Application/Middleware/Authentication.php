<?php

namespace App\Application\Middleware;

use App\Intrastructure\Persistence\TeamRepository;
use App\Intrastructure\Persistence\UserRepository;
use App\Settings\SettingsInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use App\Application\Logger\IntegrationLoggerInterface;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Routing\RouteContext;

class Authentication implements MiddlewareInterface
{
    /** @var string|mixed Url du serveur d'authentification qui permet de faire l'introspect */
    private string $authServerUrl;

    /**
     * @var IntegrationLoggerInterface
     */
    private IntegrationLoggerInterface $logger;

    private UserRepository $userRepository;
    private SettingsInterface $settings;
    /**
     * @var TeamRepository
     */
    private TeamRepository $teamRepository;


    private function callIntrospect(string $accessToken)
    {
        $curlHandler = curl_init();
        curl_setopt_array(
            $curlHandler,
            [
                CURLOPT_URL => $this->authServerUrl . '/oauth2/introspect',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => [
                    'token' => $accessToken
                ],
            ]
        );

        $introspectionResponse = curl_exec($curlHandler);
        $curlError = curl_errno($curlHandler);
        $httpResponseCode = curl_getinfo($curlHandler, CURLINFO_HTTP_CODE);

        curl_close($curlHandler);

        $accessTokenDetails = json_decode($introspectionResponse, true);

        if (!isset($accessTokenDetails['active']) || boolval($accessTokenDetails['active']) !== true) {
            $this->logger->debug(
                'Token non valide',
                [
                    'raw_generation_response' => $introspectionResponse,
                    'http_response_code' => $httpResponseCode,
                    'curl_error' => $curlError
                ]
            );
            return false;
        }

        return $accessTokenDetails;
    }

    private function addLoggerGlobalInformations(array $globalInformations)
    {
        $this->logger->pushProcessor(
            function ($records) use ($globalInformations) {
                $records['extra'] = array_merge($records['extra'], $globalInformations);
                return $records;
            }
        );
    }

    private function authenticateByTeamId(ServerRequestInterface $request, int $teamId): ServerRequestInterface
    {
        $teamDetails = $this->teamRepository->getTeamDetailsById($teamId);
        if ($teamDetails === false) {
            throw new HttpUnauthorizedException($request, 'Invalid team id');
        }

        $this->addLoggerGlobalInformations([
            'team_id' => $teamId,
            'auth_method' => 'team_id'
        ]);

        return $request->withAttribute('team_id', $teamId);
    }

    private function authenticateByUserId(ServerRequestInterface $request, int $userId): ServerRequestInterface
    {
        $userDetails = $this->userRepository->getUserDetailsById($userId);
        if ($userDetails === false) {
            throw new HttpUnauthorizedException($request, 'Invalid user id');
        }

        $this->addLoggerGlobalInformations([
            'team_id' => $userDetails['team_id'],
            'user_id' => $userId,
            'auth_method' => 'user_id'
        ]);

        return $request->withAttribute('user_id', $userId)
            ->withAttribute('team_id', $userDetails['team_id']);
    }

    private function authenticateByUserToken(ServerRequestInterface $request, string $userToken): ServerRequestInterface
    {
        $userIds = $this->userRepository->getUserIdsByToken($userToken);
        if ($userIds === false) {
            throw new HttpUnauthorizedException($request, 'Invalid user token');
        }

        $this->addLoggerGlobalInformations([
            'team_id' => $userIds['team_id'],
            'user_id' => $userIds['id'],
            'auth_method' => 'user_token'
        ]);

        return $request->withAttribute('user_id', $userIds['id'])
            ->withAttribute('team_id', $userIds['team_id']);
    }

    private function authenticateByTeamToken(ServerRequestInterface $request, string $teamToken): ServerRequestInterface
    {
        $teamId = $this->teamRepository->getTeamIdByToken($teamToken);
        if ($teamId === false) {
            throw new HttpUnauthorizedException($request, 'Invalid team token');
        }

        $this->addLoggerGlobalInformations([
            'team_id' => $teamId,
            'auth_method' => 'team_token'
        ]);

        return $request->withAttribute('team_id', $teamId);
    }

    private function authenticateByAccessToken(
        ServerRequestInterface $request,
        string $accessToken
    ): ServerRequestInterface {
        $decodedAccessToken = $this->callIntrospect($accessToken);

        if ($decodedAccessToken === false) {
            throw new HttpUnauthorizedException($request, 'Invalid access token');
        }

        $accessTokenRegion = $decodedAccessToken['region'] ?? '';
        $currentServerRegion = $this->settings->get('internals')['region'];
        if (!empty($accessTokenRegion) && $accessTokenRegion != $currentServerRegion) {
            throw new HttpUnauthorizedException($request, "Bad region <$accessTokenRegion> != <{}>");
        }

        $userId = intval($decodedAccessToken['sub'] ?? 0);
        if (!empty($userId) && !$this->userRepository->isValidUserId($userId)) {
            throw new HttpUnauthorizedException($request, "Invalid user <$userId>");
        }

        if (empty($userId)) {
            return $request;
        }

        $this->addLoggerGlobalInformations([
            'team_id' => $decodedAccessToken['team_id'] ?? 0,
            'user_id' => $userId,
            'auth_method' => 'access_token'
        ]);

        return $request
            ->withAttribute('decoded_access_token', $decodedAccessToken)
            ->withAttribute('user_id', $userId)
            ->withAttribute('team_id', $decodedAccessToken['team_id'] ?? 0);
    }

    public function __construct(
        SettingsInterface $settings,
        UserRepository $userRepository,
        TeamRepository $teamRepository,
        IntegrationLoggerInterface $logger
    ) {
        $this->authServerUrl = $settings['internals']['auth_server_url'] ?? "https://auth.ringover.com";
        $this->logger = $logger;

        $this->userRepository = $userRepository;
        $this->settings = $settings;
        $this->teamRepository = $teamRepository;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $authorizationHeader = current($request->getHeader('Authorization'));
        $GLOBALS['authorizationToken'] = $authorizationHeader;

        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $authorizationUserToken = $route->getArgument('userToken');
        $authorizationTeamToken = $route->getArgument('teamToken');

        $authorizationUserId = $route->getArgument('userId');
        $authorizationTeamId = $route->getArgument('teamId');

        $stateQueryParams = $request->getQueryParams()['state'] ?? null;
        $codeQueryParams = $request->getQueryParams()['code'] ?? null;
        if (!empty($stateQueryParams) && !empty($codeQueryParams)) {
            $decodedState = json_decode(html_entity_decode($stateQueryParams), true);
            $authorizationUserToken = $decodedState['token'] ?? null;
        }

        /*
         * Les requêtes non authentifiées sont autorisées pour certaines intégrations
         * À terme : Prévoir de faire des exceptions dans le routeur directement
         */
        if (empty($authorizationHeader) &&
            empty($authorizationUserToken) &&
            empty($authorizationTeamToken) &&
            empty($authorizationUserId) &&
            empty($authorizationTeamId)
        ) {
            $responseHandler = $handler->handle($request);
        } elseif (!empty($authorizationTeamId)) {
            $responseHandler = $handler->handle($this->authenticateByTeamId($request, $authorizationTeamId));
        } elseif (!empty($authorizationUserId)) {
            $responseHandler = $handler->handle($this->authenticateByUserId($request, $authorizationTeamId));
        } elseif (empty($authorizationHeader) && !empty($authorizationUserToken)) {
            $responseHandler = $handler->handle($this->authenticateByUserToken($request, $authorizationUserToken));
        } elseif (empty($authorizationHeader) && !empty($authorizationTeamToken)) {
            $responseHandler = $handler->handle($this->authenticateByTeamToken($request, $authorizationTeamToken));
        } elseif (strpos($authorizationHeader, 'Bearer') === false) {
            $responseHandler = $handler->handle($this->authenticateByUserToken($request, $authorizationHeader));
        } else {
            $accessToken = substr($authorizationHeader, 7);
            $responseHandler = $handler->handle($this->authenticateByAccessToken($request, $accessToken));
        }

        return $responseHandler;
    }

}
