<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;
use Hypervel\Tests\Saloon\Fixtures\Exceptions\ConnectorRequestException;

class CustomExceptionConnector extends Connector
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
     * Customise the request exception handler.
     */
    public function getRequestException(Response $response): ?RequestException
    {
        return new ConnectorRequestException($response);
    }
}
