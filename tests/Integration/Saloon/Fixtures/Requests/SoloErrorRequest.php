<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\SoloRequest;

class SoloErrorRequest extends SoloRequest
{
    /**
     * Define the HTTP method.
     */
    protected Method $method = Method::GET;

    /**
     * Create a new request instance.
     *
     * The engine test server replaces upstream's public test API, so its URL is supplied by the test.
     */
    public function __construct(protected string $serverUrl)
    {
    }

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return $this->serverUrl . '/error';
    }
}
