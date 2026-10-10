<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;

/**
 * Absolute authorize URL; only the connector allows the base URL override (not OAuthConfig).
 */
class AuthorizeUrlOAuthConnectorWithConnectorAllowOnly extends Connector
{
    use AuthorizationCodeGrant;

    /**
     * Define the base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.app.com';
    }

    /**
     * Resolve whether requests may replace this connector's base URL.
     */
    public function allowsBaseUrlOverride(): bool
    {
        return true;
    }

    /**
     * Define default Oauth config.
     */
    protected function defaultOauthConfig(): OAuthConfig
    {
        return new OAuthConfig(
            clientId: 'id',
            clientSecret: 'secret',
            redirectUri: 'https://app.com/cb',
            authorizeEndpoint: 'https://login.provider.com/authorize',
        );
    }
}
