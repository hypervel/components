<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Data\OAuthConfig;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\OAuth2\AuthorizationCodeGrant;

class NoConfigAuthCodeConnector extends Connector
{
    use AuthorizationCodeGrant;

    /**
     * Create the connector with the given OAuth configuration arguments.
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
     * Define an OAuth configuration that is empty unless arguments are given.
     */
    protected function defaultOAuthConfig(): OAuthConfig
    {
        return new OAuthConfig(...['clientId' => '', 'clientSecret' => '', ...$this->config]);
    }
}
