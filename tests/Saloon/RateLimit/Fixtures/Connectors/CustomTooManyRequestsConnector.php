<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors;

use Hypervel\Saloon\Http\Response;

// The cooldown resolver replaces upstream's handleTooManyAttempts().
final class CustomTooManyRequestsConnector extends TestConnector
{
    /**
     * Resolve the cooldown imposed by a response.
     */
    protected function resolveRateLimitCooldown(Response $response): ?int
    {
        return $response->json('error') === 'Too Many Attempts' ? 60 : null;
    }
}
