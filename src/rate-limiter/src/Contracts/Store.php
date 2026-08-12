<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Contracts;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\BackoffResult;
use Hypervel\RateLimiter\LimitResult;

interface Store
{
    /**
     * Atomically consume capacity from an admission policy.
     */
    public function consume(string $key, AdmissionPolicy $policy): LimitResult;

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : LimitResult)
     */
    public function inspect(string $key, AdmissionPolicy|Backoff $policy): LimitResult|BackoffResult;

    /**
     * Record a failure against a backoff policy.
     */
    public function recordFailure(string $key, Backoff $backoff): BackoffResult;

    /**
     * Clear the state for a physical limiter key.
     */
    public function clear(string $key): bool;
}
