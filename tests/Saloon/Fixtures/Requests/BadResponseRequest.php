<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;

class BadResponseRequest extends Request
{
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

    /**
     * Check if we should throw an exception.
     */
    public function shouldThrowRequestException(Response $response): bool
    {
        return str_contains($response->body(), 'Yee-naw:');
    }
}
