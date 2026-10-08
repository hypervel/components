<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;

class OAuth2Connector extends Connector
{
    use AuthorizationCodeGrant;

    /**
     * Create the connector, replacing any of its OAuth configuration arguments.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config = [])
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
        return new OAuthConfig(...[
            'clientId' => 'client-id',
            'clientSecret' => 'client-secret',
            'redirectUri' => 'https://my-app.saloon.dev/auth/callback',
            ...$this->config,
        ]);
    }
}
