<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

class QueryParameterRequest extends Request
{
    /**
     * Define the method that the request will use.
     */
    protected Method $method = Method::GET;

    // Upstream's $connector property is removed: the request does not use HasConnector, so nothing reads it.

    /**
     * Create a new request instance.
     */
    public function __construct(public readonly string $endpoint = '/user')
    {
    }

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Define the default query parameters.
     */
    protected function defaultQuery(): array
    {
        return [
            'per_page' => 100,
        ];
    }
}
