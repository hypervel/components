<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\Request\HasConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;

class HasConnectorUserRequest extends Request
{
    use HasConnector;

    /**
     * Define connector.
     */
    protected string $connector = TestConnector::class;

    /**
     * Define the HTTP method.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/user';
    }
}
