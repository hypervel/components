<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\OAuth2\ClientCredentialsGrant;

class AbsoluteTokenOAuthConnectorWithoutAllow extends Connector
{
    use ClientCredentialsGrant;

    /**
     * Define the base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.service.com';
    }

    /**
     * Define default Oauth config.
     */
    protected function defaultOauthConfig(): OAuthConfig
    {
        return new OAuthConfig(
            clientId: 'id',
            clientSecret: 'secret',
            tokenEndpoint: 'https://auth.external.com/oauth/token',
        );
    }
}
