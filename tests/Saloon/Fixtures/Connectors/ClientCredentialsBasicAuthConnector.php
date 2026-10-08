<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\OAuth2\ClientCredentialsBasicAuthGrant;

class ClientCredentialsBasicAuthConnector extends Connector
{
    use ClientCredentialsBasicAuthGrant;

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
        return new OAuthConfig(clientId: 'client-id', clientSecret: 'client-secret');
    }
}
