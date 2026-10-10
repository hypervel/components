<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class BadResponseConnector extends Connector
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
     * Check if we should throw an exception.
     */
    public function shouldThrowRequestException(Response $response): bool
    {
        return str_contains($response->body(), 'Error:');
    }
}
