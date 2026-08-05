<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;
use Hypervel\RateLimiter\Contracts\Store;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\Support\CarbonImmutable;
use UnitEnum;

use function Hypervel\Support\enum_value;

class Limiter
{
    /**
     * Create a new limiter wrapper.
     */
    public function __construct(
        protected Store $store,
        protected KeyResolver $keyResolver,
    ) {
    }

    /**
     * Get the underlying rate limiter store.
     */
    public function getStore(): Store
    {
        return $this->store;
    }

    // One-call decisions replace Laravel's split tooManyAttempts(), hit(),
    // increment(), attempts(), resetAttempts(), retriesLeft(), availableIn(),
    // cleanRateLimiterKey(), and Limit::fallbackKey() APIs.

    /**
     * Atomically consume capacity from an admission policy.
     */
    public function consume(
        AdmissionPolicy $policy,
        UnitEnum|string|null $limiterName = null,
    ): LimitResult {
        if ($policy instanceof Unlimited) {
            return $this->unlimitedResult();
        }

        $this->validateAdmission($policy);

        return $this->store->consume(
            $this->resolveKey($policy, $limiterName),
            $policy,
        );
    }

    /**
     * Inspect a policy without mutating its state.
     *
     * @return ($policy is Backoff ? BackoffResult : LimitResult)
     */
    public function inspect(
        AdmissionPolicy|Backoff $policy,
        UnitEnum|string|null $limiterName = null,
    ): LimitResult|BackoffResult {
        if ($policy instanceof Unlimited) {
            return $this->unlimitedResult();
        }

        if ($policy instanceof AdmissionPolicy) {
            $this->validateAdmission($policy);
        } else {
            $this->validateTimeRange($policy->resetAfter * 1_000_000);
        }

        return $this->store->inspect(
            $this->resolveKey($policy, $limiterName),
            $policy,
        );
    }

    // Laravel invokes the callback before recording a hit. Hypervel consumes
    // atomically first, so concurrent calls cannot all enter the callback.

    /**
     * Execute a callback when the policy allows it.
     */
    public function attempt(
        AdmissionPolicy $policy,
        Closure $callback,
        UnitEnum|string|null $limiterName = null,
    ): mixed {
        if ($this->consume($policy, $limiterName)->denied()) {
            return false;
        }

        $result = $callback();

        return $result ?? true;
    }

    /**
     * Record a failure against a backoff policy.
     */
    public function recordFailure(
        Backoff $backoff,
        UnitEnum|string|null $limiterName = null,
    ): BackoffResult {
        $this->validateTimeRange($backoff->resetAfter * 1_000_000);

        return $this->store->recordFailure(
            $this->resolveKey($backoff, $limiterName),
            $backoff,
        );
    }

    /**
     * Clear the state for an identically parameterized policy.
     */
    public function clear(
        AdmissionPolicy|Backoff $policy,
        UnitEnum|string|null $limiterName = null,
    ): bool {
        if ($policy instanceof Unlimited) {
            return true;
        }

        return $this->store->clear($this->resolveKey($policy, $limiterName));
    }

    /**
     * Validate admission constraints shared by every store.
     */
    protected function validateAdmission(AdmissionPolicy $policy): void
    {
        $duration = match (true) {
            $policy instanceof Limit => $this->validateFixedWindow($policy),
            $policy instanceof LeakyBucket => $this->validateLeakyBucket($policy),
            default => throw new InvalidRateLimitException(sprintf(
                'Admission policy [%s] is not supported.',
                $policy::class,
            )),
        };

        $this->validateTimeRange($duration);
    }

    /**
     * Validate a fixed-window policy and return its maximum state duration.
     */
    protected function validateFixedWindow(Limit $policy): int
    {
        if ($policy->cost > $policy->maxAttempts) {
            throw new InvalidRateLimitException(
                'The rate limit cost may not exceed the fixed-window capacity.'
            );
        }

        return $policy->decaySeconds * 1_000_000;
    }

    /**
     * Validate a leaky-bucket policy and return its maximum state duration.
     */
    protected function validateLeakyBucket(LeakyBucket $policy): int
    {
        if ($policy->cost > $policy->burst) {
            throw new InvalidRateLimitException(
                'The rate limit cost may not exceed the leaky-bucket burst capacity.'
            );
        }

        $emission = intdiv($policy->periodMicroseconds, $policy->rate)
            + ($policy->periodMicroseconds % $policy->rate === 0 ? 0 : 1);

        return $emission * $policy->burst;
    }

    /**
     * Ensure current-time arithmetic fits every first-party store.
     */
    protected function validateTimeRange(int $durationMicroseconds): void
    {
        $now = CarbonImmutable::hasTestNow()
            ? (int) CarbonImmutable::now()->getPreciseTimestamp(6)
            : (int) (microtime(true) * 1_000_000);

        if ($now < 0 || $durationMicroseconds < 0
            || $now > AdmissionPolicy::MAX_INTEGER - $durationMicroseconds) {
            throw new InvalidRateLimitException(
                'The rate limiter timestamp exceeds the supported integer range.'
            );
        }
    }

    /**
     * Resolve the physical state key for a policy.
     */
    protected function resolveKey(
        AdmissionPolicy|Backoff $policy,
        UnitEnum|string|null $limiterName,
    ): string {
        if ($limiterName instanceof UnitEnum) {
            $limiterName = (string) enum_value($limiterName);
        }

        return $this->keyResolver->resolve($policy, $limiterName);
    }

    /**
     * Create an unlimited admission result.
     */
    protected function unlimitedResult(): LimitResult
    {
        return new LimitResult(
            true,
            AdmissionPolicy::MAX_INTEGER,
            AdmissionPolicy::MAX_INTEGER,
            0,
            0,
        );
    }
}
