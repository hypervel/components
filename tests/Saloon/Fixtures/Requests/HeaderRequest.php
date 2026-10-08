<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

class HeaderRequest extends Request
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
            'X-Custom-Header' => 'Howdy',
        ];
    }

    /**
     * Define the default request options.
     */
    protected function defaultOptions(): array
    {
        return [
            'timeout' => 5,
        ];
    }
}
