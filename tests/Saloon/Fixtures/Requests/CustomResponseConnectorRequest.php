<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

// Upstream's HasTestHandler plugin adds Guzzle handler middleware through the connector's sender, which Hypervel
// connectors do not have, and no test using this request reads its header.
class CustomResponseConnectorRequest extends Request
{
    /**
     * Define the method that the request will use.
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
