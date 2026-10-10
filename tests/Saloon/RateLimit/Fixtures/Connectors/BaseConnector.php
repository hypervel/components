<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;

class BaseConnector extends Connector
{
    /**
     * Define the base url of the api.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://tests.saloon.dev/api';
    }
}
