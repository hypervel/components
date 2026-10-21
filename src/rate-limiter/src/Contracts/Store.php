<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Contracts;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\BackoffResult;
use Hypervel\RateLimiter\Cooldown;
use Hypervel\RateLimiter\CooldownResult;
use Hypervel\RateLimiter\LimitResult;

interface Store
{
    /**
     * Atomically consume capacity from an admission policy.
     */
    public function consume(string $key, AdmissionPolicy $policy): LimitResult;

    /**
     * Consume policies together, returning decisions through the first denial.
     *
     * Repeated keys accumulate their costs. Atomic stores charge nothing on denial.
     * Redis Cluster checks first, then consumes: a preflight denial charges nothing,
     * but a consumption denial can leave earlier charges.
     *
     * @param list<array{key: string, policy: AdmissionPolicy}> $policies
     * @return list<LimitResult>
     */
    public function consumeMany(array $policies): array;

    /**
     * Atomically extend a cooldown block.
     */
    public function block(string $key, int $durationMicroseconds): CooldownResult;

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : ($policy is Cooldown ? CooldownResult : LimitResult))
     */
    public function inspect(string $key, AdmissionPolicy|Backoff|Cooldown $policy): LimitResult|BackoffResult|CooldownResult;

    /**
     * Record a failure against a backoff policy.
     */
    public function recordFailure(string $key, Backoff $backoff): BackoffResult;

    /**
     * Clear the state for a physical limiter key.
     */
    public function clear(string $key): bool;
}
