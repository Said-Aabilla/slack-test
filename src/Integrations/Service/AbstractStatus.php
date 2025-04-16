<?php

namespace App\Integrations\Service;

use App\Domain\Exception\IntegrationException;
use App\Domain\Exception\TokenException;
use Exception;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

abstract class AbstractStatus extends AbstractProcess
{

    public const OK_STATUS = "OK";

    public const NOK_STATUS = "UNKNOWN_ERROR";

    public const TOKEN_EXCEPTION_STATUS = "TOKEN_ERROR";

    public const INTEGRATION_EXCEPTION_STATUS = "INTEGRATION_ERROR";

    public const UNKNOWN_EXCEPTION_STATUS = "UNKNOWN_ERROR";

    public const UNKNOWN_ERROR_MESSAGE = "Unknown error";


    /**
     * @throws TokenException
     */
    public function validOauth2RefreshToken(GenericOAuth2Provider $oAuth2Provider): void
    {
        try {
            $oAuth2Provider->loadAccessTokenFromDatabase($this->integration);
            $oAuth2Provider->refreshToken($this->integration);
        } catch (IdentityProviderException $identityProviderException) {
            throw new TokenException($identityProviderException->getMessage());
        } catch (Exception $exception) {
            throw new TokenException('Refresh token failed');
        }
    }

    /**
     * Appelle une route de l'api de l'outil métier.
     * Retourne true si tout est ok, false ou une exception est lancé sinon
     * @return bool
     * @throws TokenException
     * @throws IntegrationException
     */
    abstract public function isAlive(): bool;
}
