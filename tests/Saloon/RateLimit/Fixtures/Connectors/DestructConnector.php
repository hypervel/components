<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors;

use Hypervel\RateLimiter\Limit;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;

final class DestructConnector extends Connector
{
    use HasRateLimits;

    /**
     * Create a connector that reports its destruction.
     */
    public function __construct(public bool &$destructed = false)
    {
    }

    /**
     * Define the base url of the api.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://tests.saloon.dev/api';
    }

    /**
     * Resolve the limits.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return [
            Limit::perMinute(10),
        ];
    }

    /**
     * Record that the connector was destroyed.
     */
    public function __destruct()
    {
        // This tests that the rate-limited connector can still be destroyed properly.

        $this->destructed = true;
    }
}
