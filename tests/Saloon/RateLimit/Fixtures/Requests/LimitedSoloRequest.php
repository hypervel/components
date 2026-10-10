<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Requests;

use Hypervel\RateLimiter\Limit;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\SoloRequest;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;

final class LimitedSoloRequest extends SoloRequest
{
    use HasRateLimits;

    /**
     * Define the HTTP method.
     */
    protected Method $method = Method::GET;

    /**
     * Define the endpoint for the request.
     */
    public function resolveEndpoint(): string
    {
        return 'https://tests.saloon.dev/api/user';
    }

    /**
     * Resolve the limits.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [
            Limit::perMinute(60),
        ];
    }
}
