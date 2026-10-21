<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;
use Hypervel\Tests\Saloon\Fixtures\Requests\OAuth\CustomAccessTokenRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\OAuth\CustomOAuthUserRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\OAuth\CustomRefreshTokenRequest;

class CustomRequestOAuth2Connector extends Connector
{
    use AuthorizationCodeGrant;

    /**
     * Define the base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://oauth.saloon.dev';
    }

    /**
     * Define default Oauth config.
     */
    protected function defaultOauthConfig(): OAuthConfig
    {
        return new OAuthConfig(
            clientId: 'client-id',
            clientSecret: 'client-secret',
            redirectUri: 'https://my-app.saloon.dev/auth/callback',
        );
    }

    /**
     * Resolve the access token request.
     */
    protected function resolveAccessTokenRequest(string $code, OAuthConfig $oauthConfig): Request
    {
        return new CustomAccessTokenRequest($code, $oauthConfig);
    }

    /**
     * Resolve the refresh token request.
     */
    protected function resolveRefreshTokenRequest(OAuthConfig $oauthConfig, string $refreshToken): Request
    {
        return new CustomRefreshTokenRequest($oauthConfig, $refreshToken);
    }

    /**
     * Resolve the user request.
     */
    protected function resolveUserRequest(OAuthConfig $oauthConfig): Request
    {
        return new CustomOAuthUserRequest($oauthConfig);
    }
}
