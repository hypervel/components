<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;

class CustomEndpointRequest extends Request
{
    /**
     * Endpoint.
     */
    protected string $endpoint = '';

    /**
     * Method.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Set an endpoint.
     */
    public function setEndpoint(string $endpoint): CustomEndpointRequest
    {
        $this->endpoint = $endpoint;

        return $this;
    }
}
