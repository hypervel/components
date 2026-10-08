<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors;

use Hypervel\Saloon\Http\Response;

// Returning no cooldown replaces upstream's $detectTooManyAttempts property.
final class DisabledTooManyRequestsConnector extends TestConnector
{
    /**
     * Resolve the cooldown imposed by a response.
     */
    protected function resolveRateLimitCooldown(Response $response): ?int
    {
        return null;
    }
}
