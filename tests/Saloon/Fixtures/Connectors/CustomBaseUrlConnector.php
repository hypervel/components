<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;

class CustomBaseUrlConnector extends Connector
{
    /**
     * Create a new connector instance.
     *
     * Connectors are read-only, so the base URL is supplied when the connector is created instead of upstream's setter.
     */
    public function __construct(protected string $baseUrl = '')
    {
    }

    /**
     * Define the base URL of the API.
     */
    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
