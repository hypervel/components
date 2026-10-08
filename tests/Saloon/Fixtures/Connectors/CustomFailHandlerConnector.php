<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class CustomFailHandlerConnector extends Connector
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
     * Determine if the request has failed.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        return str_contains($response->body(), 'Error:');
    }
}
