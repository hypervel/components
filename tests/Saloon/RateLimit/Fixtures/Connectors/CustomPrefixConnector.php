<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors;

// The limiter name replaces upstream's getLimiterPrefix().
class CustomPrefixConnector extends TestConnector
{
    /**
     * Resolve the limiter name.
     */
    protected function resolveRateLimiterName(): string
    {
        return 'custom';
    }
}
