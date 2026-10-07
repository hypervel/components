<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Contracts\Decision;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\Saloon\Data\RetryPolicy;
use Hypervel\Saloon\Exceptions\Request\FatalRequestException;
use Hypervel\Saloon\Exceptions\Request\RequestException;
use Hypervel\Saloon\Http\Response;

// Waiting for the cooldown with a bounded retry replaces upstream's sleep() on the "too many attempts" limit,
// which sent the request again from response middleware.
class SleepTooManyRequestsConnector extends TestConnector
{
    /**
     * Determine if a denied rate limit should be awaited.
     */
    protected function waitForRateLimits(AdmissionPolicy|Cooldown $policy, Decision $result): bool
    {
        return $policy instanceof Cooldown;
    }

    /**
     * Resolve the default retry policy.
     */
    protected function defaultRetryPolicy(): ?RetryPolicy
    {
        return new RetryPolicy(
            times: 2,
            when: static fn (FatalRequestException|RequestException $exception): bool => $exception instanceof RequestException
                && $exception->status() === 429,
        );
    }

    /**
     * Resolve the cooldown imposed by a response.
     */
    protected function resolveRateLimitCooldown(Response $response): ?int
    {
        return $response->status() === 429 ? 5 : null;
    }
}
