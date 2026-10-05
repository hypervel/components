<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Plugins\HasTimeout;

class TimeoutRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 1;

    protected int $requestTimeout = 2;

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
}
