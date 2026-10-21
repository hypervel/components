<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

class DefaultPropertiesRequest extends Request
{
    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    // Upstream's $connector property is removed: the request does not use HasConnector, so nothing reads it.

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

    // Upstream's defaultData() is removed: nothing calls it, so the request has no default body.

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
