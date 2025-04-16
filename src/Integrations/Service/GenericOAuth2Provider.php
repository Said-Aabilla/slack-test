<?php

namespace App\Integrations\Service;

use App\Domain\Integration\Integration;
use App\Intrastructure\Persistence\IntegrationRepository;
use DateTime;
use Exception;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

abstract class GenericOAuth2Provider
{

    protected IntegrationRepository $integrationRepository;

    /**
     * @var GenericProvider
     */
    protected AbstractProvider $genericProvider;

    private string $accessTokenClassName;

    protected AccessTokenInterface $accessToken;


    /**
     * Retourne true si le token a expiré où n'est pas chargé. False sinon.
     * @return bool
     */
    protected function isAccessTokenHasExpired(): bool
    {
        if (empty($this->accessToken)) {
            return true;
        }

        return $this->accessToken->hasExpired();
    }

    protected function getAccessTokenClassName(): string
    {
        return $this->accessTokenClassName;
    }

    abstract protected function rawAccessTokenDetailsToEntity(array $rawOAuth2AccessTokenDetails): AccessTokenInterface;

    public function __construct(
        AbstractProvider $genericProvider,
        IntegrationRepository $integrationRepository,
        string $accessTokenClassName = AccessToken::class
    ) {
        $this->integrationRepository = $integrationRepository;
        $this->genericProvider = $genericProvider;
        $this->accessTokenClassName = $accessTokenClassName;
    }

    public function getProvider(): AbstractProvider
    {
        return $this->genericProvider;
    }

    /**
     * @throws Exception
     */
    public function loadAccessTokenFromDatabase(Integration $integration)
    {
        $rawOAuth2AccessTokenDetails = $this->integrationRepository->getRawOAuth2AccessTokenDetailsFromId(
            $integration->getId()
        );

        $this->accessToken = $this->rawAccessTokenDetailsToEntity($rawOAuth2AccessTokenDetails);
    }

    /**
     * Save accessToken to an existing integration
     * @param Integration $integration
     * @return void
     */
    public function saveAccessTokenToDatabase(Integration $integration)
    {
        if (empty($integration->getId())) {
            return;
        }

        $this->integrationRepository->persistOAuth2AccessToken(
            $integration->getId(),
            $this->accessToken
        );
    }

    /**
     * Vérifie la validité d'un token si possible.
     * Le token sera rafraichie s'il est expiré où qu'une demande explicite de rafraichissement est faite.
     * Le nouveau access_token sera retourné en cas de succès.
     * @param bool $forceRefresh True si on veut rafraichir le token dans tous les cas, false sinon
     * @return string Un access token valide
     * @throws IdentityProviderException
     * @throws Exception
     */
    public function getValidAccessToken(Integration $integration, bool $forceRefresh = false): string
    {
        /*
         * En cas d'expiration du token, on va dans un premier temps récupérer le token en base si on est dans le cas
         * où le token expiré a déjà été rafraichie entre temps (requête en parallèle, synchro des contacts, etc)
         */
        if (empty($this->accessToken) || $this->isAccessTokenHasExpired()) {
            $this->loadAccessTokenFromDatabase($integration);
        }

        if ($forceRefresh || $this->isAccessTokenHasExpired()) {
            $this->refreshToken($integration);
        }

        return $integration->getAccessToken();
    }

    /**
     * @param Integration $integration
     * @param string $authCode
     * @return array Extra values returned with accessToken, like domain, location, region
     * @throws IdentityProviderException
     */
    public function generateToken(Integration $integration, string $authCode)
    {
        $this->accessToken = $this->genericProvider->getAccessToken(
            'authorization_code',
            [
                'code' => $authCode
            ]
        );
        $this->saveAccessTokenToDatabase($integration);
        $expirationDate = new DateTime('now', new \DateTimeZone('UTC'));
        $expirationDate->setTimestamp($this->accessToken->getExpires());

        $integration->setToken(
            $this->accessToken->getToken(),
            $this->accessToken->getRefreshToken() ?? $integration->getRefreshToken(),
            $expirationDate
        );

        return $this->accessToken->getValues();
    }

    /**
     * @throws IdentityProviderException
     * @throws Exception
     */
    public function refreshToken(Integration $integration)
    {
        $this->accessToken = $this->genericProvider->getAccessToken(
            'refresh_token',
            [
                'refresh_token' => $this->accessToken->getRefreshToken()
            ]
        );
        $this->saveAccessTokenToDatabase($integration);
        $expirationDate = new DateTime('now', new \DateTimeZone('UTC'));
        $expirationDate->setTimestamp($this->accessToken->getExpires());
        $integration->setToken(
            $this->accessToken->getToken(),
            $this->accessToken->getRefreshToken() ?? $integration->getRefreshToken(),
            $expirationDate
        );
    }

    /**
     * @throws Exception
     */
    public function getAccessToken(Integration $integration): AccessTokenInterface
    {
        if (empty($this->accessToken)) {
            $this->loadAccessTokenFromDatabase($integration);
        }

        return $this->accessToken;
    }

    public function setAccessToken(AccessTokenInterface $accessToken)
    {
        $this->accessToken = $accessToken;
    }
}