<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Plugins\HasTimeout;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

class TimeoutRequest extends Request
{
    use HasTimeout;

    protected int $connectTimeout = 1;

    protected int $requestTimeout = 2;

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
}
