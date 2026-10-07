<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Fixtures\Requests;

class HeaderErrorRetryRequest extends RetryUserRequest
{
    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return '/header-error';
    }
}
