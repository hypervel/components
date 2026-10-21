<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Tests\Saloon\Fixtures\Responses\CustomResponse;

class CustomResponseConnector extends Connector
{
    protected ?string $response = CustomResponse::class;

    /**
     * Define the base url of the api.
     */
    public function resolveBaseUrl(): string
    {
        return TestConnector::API_URL;
    }
}
