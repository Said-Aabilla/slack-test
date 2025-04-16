<?php

namespace App\Integrations\Service;

use League\OAuth2\Client\Token\AccessTokenInterface;

trait GenericAccessTokenEntityTrait
{
    abstract protected function getAccessTokenClassName(): string;

    protected function rawAccessTokenDetailsToEntity(array $rawOAuth2AccessTokenDetails): AccessTokenInterface
    {
        $accessTokenClassName = $this->getAccessTokenClassName();
        return new $accessTokenClassName([
            'access_token'      => $rawOAuth2AccessTokenDetails['access_token'],
            'refresh_token'     => $rawOAuth2AccessTokenDetails['refresh_token'],
            'expires'           => $rawOAuth2AccessTokenDetails['expiration_date'],
            'instance_url'      => $rawOAuth2AccessTokenDetails['instance_url'],
            'resource_owner_id' => $rawOAuth2AccessTokenDetails['service_user']
        ]);
    }
}