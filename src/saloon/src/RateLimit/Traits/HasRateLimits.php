<?php

declare(strict_types=1);

namespace Hypervel\Saloon\RateLimit\Traits;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Contracts\Decision;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\Http\RetryAfter;
use UnitEnum;

trait HasRateLimits
{
    /**
     * Determine if this resource's rate limits apply to an operation.
     *
     * @internal
     */
    final public function usesRateLimits(PendingRequest $pendingRequest): bool
    {
        return $this->rateLimitingEnabled($pendingRequest);
    }

    /**
     * Resolve the admission policies for an operation.
     *
     * @return array<array-key, AdmissionPolicy>
     * @internal
     */
    final public function resolveRateLimitPolicies(PendingRequest $pendingRequest): array
    {
        return $this->resolveRateLimits($pendingRequest);
    }

    /**
     * Resolve the selected rate limiter store.
     *
     * @internal
     */
    final public function resolveRateLimitStoreName(): UnitEnum|string|null
    {
        return $this->resolveRateLimitStore();
    }

    /**
     * Resolve the identity whose limits and cooldowns this resource uses.
     *
     * @internal
     */
    final public function rateLimiterName(): string
    {
        return $this->resolveRateLimiterName();
    }

    /**
     * Determine if a denied rate limit should be awaited.
     *
     * @internal
     */
    final public function shouldWaitForRateLimits(AdmissionPolicy|Cooldown $policy, Decision $result): bool
    {
        return $this->waitForRateLimits($policy, $result);
    }

    /**
     * Resolve the stable cooldown key for an operation.
     *
     * @internal
     */
    final public function resolveRateLimitCooldownKeyFor(PendingRequest $pendingRequest): string
    {
        return $this->resolveRateLimitCooldownKey($pendingRequest);
    }

    /**
     * Resolve the cooldown imposed by a response.
     *
     * @internal
     */
    final public function resolveRateLimitCooldownFor(Response $response): ?int
    {
        return $this->resolveRateLimitCooldown($response);
    }

    /**
     * Determine if this resource's rate limits apply to an operation.
     */
    protected function rateLimitingEnabled(PendingRequest $pendingRequest): bool
    {
        return true;
    }

    /**
     * Resolve the admission policies for an operation.
     *
     * @return array<array-key, AdmissionPolicy>
     */
    abstract protected function resolveRateLimits(PendingRequest $pendingRequest): array;

    /**
     * Resolve the selected rate limiter store.
     */
    protected function resolveRateLimitStore(): UnitEnum|string|null
    {
        return null;
    }

    /**
     * Resolve the identity whose limits and cooldowns this resource uses.
     *
     * Resources that return the same name share limits and cooldowns within
     * the same store, key scope and policy identity.
     */
    protected function resolveRateLimiterName(): string
    {
        return static::class;
    }

    /**
     * Determine if a denied rate limit should be awaited.
     */
    protected function waitForRateLimits(AdmissionPolicy|Cooldown $policy, Decision $result): bool
    {
        return false;
    }

    /**
     * Resolve the stable cooldown key for an operation.
     */
    protected function resolveRateLimitCooldownKey(PendingRequest $pendingRequest): string
    {
        return '';
    }

    /**
     * Resolve the cooldown imposed by a response.
     *
     * Override this method to support a provider-specific response format or
     * enforce a maximum duration defined by the provider.
     */
    protected function resolveRateLimitCooldown(Response $response): ?int
    {
        if ($response->status() !== 429) {
            return null;
        }

        // A 429 without a usable delay still blocks for upstream's 60-second default. A valid delay that has
        // already passed blocks nothing.
        $seconds = RetryAfter::seconds($response->header('Retry-After'));
        $seconds = $seconds === null || $seconds > intdiv(AdmissionPolicy::MAX_INTEGER, 1_000_000) ? 60 : $seconds;

        return $seconds > 0 ? $seconds : null;
    }
}
