<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\RateLimit\Fixtures\Connectors;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Contracts\Decision;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\RateLimit\Traits\HasRateLimits;

// The store comes from the saloon.rate_limiter.store configuration instead of the constructor, and the limits to
// wait for replace upstream's sleep() on each limit.
class TestConnector extends BaseConnector
{
    use HasRateLimits;

    /**
     * Create a connector with the given limits.
     *
     * @param list<AdmissionPolicy> $limits
     * @param list<AdmissionPolicy> $waitFor
     */
    public function __construct(
        protected array $limits,
        protected array $waitFor = [],
    ) {
    }

    /**
     * Resolve the limits.
     */
    protected function resolveRateLimits(PendingRequest $pendingRequest): array
    {
        return $this->limits;
    }

    /**
     * Determine if a denied rate limit should be awaited.
     */
    protected function waitForRateLimits(AdmissionPolicy|Cooldown $policy, Decision $result): bool
    {
        return in_array($policy, $this->waitFor, true);
    }
}
