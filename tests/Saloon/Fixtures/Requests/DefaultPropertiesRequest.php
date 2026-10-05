<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

class DefaultPropertiesRequest extends Request
{
    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    /**
     * The connector.
     */
    protected string $connector = TestConnector::class;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }

    /**
     * Define the default headers.
     */
    protected function defaultHeaders(): array
    {
        return [
            'X-Favourite-Artist' => 'Luke Combs',
        ];
    }

    /**
     * Define the default query parameters.
     */
    protected function defaultQuery(): array
    {
        return [
            'format' => 'json',
        ];
    }

    /**
     * Define the default data.
     */
    protected function defaultData(): mixed
    {
        return [
            'song' => 'Call Me',
        ];
    }

    /**
     * Define the default request options.
     */
    protected function defaultOptions(): array
    {
        return [
            'debug' => true,
        ];
    }
}
