<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use DateTimeImmutable;
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;
use Hypervel\Tests\Saloon\Fixtures\Authenticators\CustomOAuthAuthenticator;

class CustomResponseOAuth2Connector extends Connector
{
    use AuthorizationCodeGrant;

    /**
     * Create the connector with the greeting its authenticators carry.
     */
    public function __construct(protected string $greeting)
    {
    }

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
            redirectUri: 'https://my-app.saloon.dev/oauth/redirect',
        );
    }

    /**
     * Create the OAuth authenticator.
     */
    protected function createOAuthAuthenticator(string $accessToken, ?string $refreshToken = null, ?DateTimeImmutable $expiresAt = null): OAuthAuthenticator
    {
        return new CustomOAuthAuthenticator($accessToken, $this->greeting, $refreshToken, $expiresAt);
    }
}
