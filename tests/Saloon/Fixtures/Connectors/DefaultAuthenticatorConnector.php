<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Contracts\Authenticator;
use Hypervel\Saloon\Http\Auth\TokenAuthenticator;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class DefaultAuthenticatorConnector extends Connector
{
    use AcceptsJson;

    /**
     * Define the base url of the api.
     */
    public function resolveBaseUrl(): string
    {
        return TestConnector::API_URL;
    }

    /**
     * Define the base headers that will be applied in every request.
     *
     * @return string[]
     */
    protected function defaultHeaders(): array
    {
        return [];
    }

    /**
     * Provide default authentication.
     */
    protected function defaultAuth(): ?Authenticator
    {
        return new TokenAuthenticator('yee-haw-connector');
    }
}
