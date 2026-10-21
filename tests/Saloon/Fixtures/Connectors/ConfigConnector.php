<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;

class ConfigConnector extends Connector
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
     * Define the default request options.
     */
    protected function defaultOptions(): array
    {
        return [
            'debug' => false,
        ];
    }
}
