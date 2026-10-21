<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class NotFoundFailedRequest extends Request
{
    use AlwaysThrowOnErrors;

    /**
     * Define the HTTP method.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/not-found';
    }

    /**
     * Determine if the request has failed.
     */
    public function hasRequestFailed(Response $response): ?bool
    {
        if ($response->status() === 404) {
            return false;
        }

        return $response->serverError() || $response->clientError();
    }
}
