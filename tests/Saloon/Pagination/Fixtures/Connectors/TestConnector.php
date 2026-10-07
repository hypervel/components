<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Pagination\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use Hypervel\Tests\Saloon\Pagination\Fixtures\SuperheroApi;

abstract class TestConnector extends Connector
{
    use AlwaysThrowOnErrors;

    /**
     * Define the base URL of the API.
     */
    public function resolveBaseUrl(): string
    {
        return SuperheroApi::BASE_URL;
    }
}
