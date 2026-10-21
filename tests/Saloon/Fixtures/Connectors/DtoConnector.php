<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Connectors;

use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Plugins\AcceptsJson;
use Hypervel\Tests\Saloon\Fixtures\Data\ApiResponse;

class DtoConnector extends Connector
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
    public function defaultHeaders(): array
    {
        return [];
    }

    /**
     * Create DTO from Response.
     */
    public function createDtoFromResponse(Response $response): mixed
    {
        return ApiResponse::fromSaloon($response);
    }
}
