<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Concerns;

use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Backoff;
use Hypervel\RateLimiter\BackoffResult;
use Hypervel\RateLimiter\Exceptions\InvalidRateLimitException;
use Hypervel\RateLimiter\LeakyBucket;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\LimitResult;
use Hypervel\Support\CarbonImmutable;
use UnexpectedValueException;

trait CalculatesRateLimits
{
    /**
     * Calculate an admission decision and update accepted state values.
     */
    protected function calculateConsume(
        AdmissionPolicy $policy,
        int $now,
        int &$value,
        int &$availableAt,
        int &$expiresAt,
    ): LimitResult {
        return match (true) {
            $policy instanceof Limit => $this->calculateFixedWindow(
                $policy,
                $now,
                $value,
                $availableAt,
                $expiresAt,
                true,
            ),
            $policy instanceof LeakyBucket => $this->calculateLeakyBucket(
                $policy,
                $now,
                $value,
                $availableAt,
                $expiresAt,
                true,
            ),
            default => throw new InvalidRateLimitException(sprintf(
                'Admission policy [%s] is not supported.',
                $policy::class,
            )),
        };
    }

    /**
     * Calculate a non-mutating policy inspection.
     *
     * @return ($policy is Backoff ? BackoffResult : LimitResult)
     */
    protected function calculateInspection(
        AdmissionPolicy|Backoff $policy,
        int $now,
        int $value,
        int $availableAt,
        int $expiresAt,
    ): LimitResult|BackoffResult {
        return match (true) {
            $policy instanceof Limit => $this->calculateFixedWindow(
                $policy,
                $now,
                $value,
                $availableAt,
                $expiresAt,
                false,
            ),
            $policy instanceof LeakyBucket => $this->calculateLeakyBucket(
                $policy,
                $now,
                $value,
                $availableAt,
                $expiresAt,
                false,
            ),
            $policy instanceof Backoff => $this->calculateBackoffInspection(
                $now,
                $value,
                $availableAt,
                $expiresAt,
            ),
            default => throw new InvalidRateLimitException(sprintf(
                'Admission policy [%s] is not supported.',
                $policy::class,
            )),
        };
    }

    /**
     * Calculate a failure transition and update its state values.
     */
    protected function calculateFailure(
        Backoff $backoff,
        int $now,
        int &$value,
        int &$availableAt,
        int &$expiresAt,
    ): BackoffResult {
        $this->resetExpiredState($now, $value, $availableAt, $expiresAt);
        $this->validateBackoffState($value, $availableAt, $expiresAt);

        if ($value >= AdmissionPolicy::MAX_INTEGER) {
            throw new UnexpectedValueException('The stored backoff failure count cannot be incremented safely.');
        }

        ++$value;

        $delay = $value < $backoff->after
            ? 0
            : $this->backoffDelay($backoff, $value - $backoff->after);

        $availableAt = $delay === 0
            ? 0
            : $this->addExact($now, $this->secondsToMicroseconds($delay));
        $expiresAt = $this->addExact($now, $this->secondsToMicroseconds($backoff->resetAfter));

        return new BackoffResult(
            $delay === 0,
            $value,
            $delay === 0 ? 0 : $availableAt - $now,
        );
    }

    /**
     * Calculate a fixed-window decision.
     */
    private function calculateFixedWindow(
        Limit $policy,
        int $now,
        int &$value,
        int &$availableAt,
        int &$expiresAt,
        bool $consume,
    ): LimitResult {
        $this->resetExpiredState($now, $value, $availableAt, $expiresAt);
        $this->validateFixedWindowState($policy, $value, $availableAt, $expiresAt);

        if ($expiresAt === 0) {
            if (! $consume) {
                return new LimitResult(true, $policy->maxAttempts, $policy->maxAttempts, 0, 0);
            }

            $value = $policy->cost;
            $availableAt = $expiresAt = $this->addExact(
                $now,
                $this->secondsToMicroseconds($policy->decaySeconds),
            );

            return new LimitResult(
                true,
                $policy->maxAttempts,
                $policy->maxAttempts - $value,
                0,
                $expiresAt - $now,
            );
        }

        $resetAfter = $expiresAt - $now;
        $allowed = $value <= $policy->maxAttempts - $policy->cost;

        if (! $allowed) {
            return new LimitResult(
                false,
                $policy->maxAttempts,
                $policy->maxAttempts - $value,
                $resetAfter,
                $resetAfter,
            );
        }

        if ($consume) {
            $value += $policy->cost;
        }

        return new LimitResult(
            true,
            $policy->maxAttempts,
            $policy->maxAttempts - $value,
            0,
            $resetAfter,
        );
    }

    /**
     * Calculate a leaky-bucket decision.
     */
    private function calculateLeakyBucket(
        LeakyBucket $policy,
        int $now,
        int &$value,
        int &$availableAt,
        int &$expiresAt,
        bool $consume,
    ): LimitResult {
        $this->resetExpiredState($now, $value, $availableAt, $expiresAt);
        $this->validateLeakyBucketState($value, $availableAt, $expiresAt);

        $emission = intdiv($policy->periodMicroseconds, $policy->rate)
            + ($policy->periodMicroseconds % $policy->rate === 0 ? 0 : 1);
        $effectiveTat = max($value, $now);
        $candidateTat = $this->addExact(
            $effectiveTat,
            $this->multiplyExact($emission, $policy->cost),
        );
        $burstDuration = $this->multiplyExact($emission, $policy->burst);
        $allowedAt = $candidateTat - $burstDuration;
        $allowed = $now >= $allowedAt;

        if (! $allowed) {
            return new LimitResult(
                false,
                $policy->burst,
                $this->remainingCapacity($now, $effectiveTat, $emission, $policy->burst),
                $allowedAt - $now,
                max($effectiveTat - $now, 0),
            );
        }

        if (! $consume) {
            return new LimitResult(
                true,
                $policy->burst,
                $this->remainingCapacity($now, $effectiveTat, $emission, $policy->burst),
                0,
                max($effectiveTat - $now, 0),
            );
        }

        $value = $candidateTat;
        $availableAt = 0;
        $expiresAt = $candidateTat;

        return new LimitResult(
            true,
            $policy->burst,
            $this->remainingCapacity($now, $candidateTat, $emission, $policy->burst),
            0,
            $candidateTat - $now,
        );
    }

    /**
     * Calculate a non-mutating backoff inspection.
     */
    private function calculateBackoffInspection(
        int $now,
        int $value,
        int $availableAt,
        int $expiresAt,
    ): BackoffResult {
        $this->resetExpiredState($now, $value, $availableAt, $expiresAt);
        $this->validateBackoffState($value, $availableAt, $expiresAt);

        $retryAfter = max($availableAt - $now, 0);

        return new BackoffResult($retryAfter === 0, $value, $retryAfter);
    }

    /**
     * Calculate the current whole-token capacity.
     */
    private function remainingCapacity(
        int $now,
        int $effectiveTat,
        int $emission,
        int $burst,
    ): int {
        $fullTat = $this->addExact($now, $this->multiplyExact($emission, $burst));

        return min($burst, max(0, intdiv($fullTat - $effectiveTat, $emission)));
    }

    /**
     * Calculate a capped exponential delay without overflowing.
     */
    private function backoffDelay(Backoff $backoff, int $doublings): int
    {
        $delay = $backoff->initialDelay;

        while ($doublings > 0 && $delay < $backoff->maxDelay) {
            $delay = $delay > intdiv($backoff->maxDelay, 2)
                ? $backoff->maxDelay
                : min($delay * 2, $backoff->maxDelay);
            --$doublings;
        }

        return $delay;
    }

    /**
     * Reset state whose expiration has passed.
     */
    private function resetExpiredState(
        int $now,
        int &$value,
        int &$availableAt,
        int &$expiresAt,
    ): void {
        if ($expiresAt !== 0 && $expiresAt <= $now) {
            $value = 0;
            $availableAt = 0;
            $expiresAt = 0;
        }
    }

    /**
     * Validate fixed-window state loaded from a store.
     */
    private function validateFixedWindowState(
        Limit $policy,
        int $value,
        int $availableAt,
        int $expiresAt,
    ): void {
        if ($value < 0 || $value > $policy->maxAttempts
            || $availableAt < 0 || $expiresAt < 0
            || $availableAt !== $expiresAt
            || ($expiresAt === 0 && $value !== 0)) {
            throw new UnexpectedValueException('The stored fixed-window rate limiter state is invalid.');
        }
    }

    /**
     * Validate leaky-bucket state loaded from a store.
     */
    private function validateLeakyBucketState(int $value, int $availableAt, int $expiresAt): void
    {
        if ($value < 0 || $availableAt !== 0 || $expiresAt < 0 || $value !== $expiresAt) {
            throw new UnexpectedValueException('The stored leaky-bucket rate limiter state is invalid.');
        }
    }

    /**
     * Validate backoff state loaded from a store.
     */
    private function validateBackoffState(int $value, int $availableAt, int $expiresAt): void
    {
        if ($value < 0 || $availableAt < 0 || $expiresAt < 0
            || ($value === 0 && ($availableAt !== 0 || $expiresAt !== 0))
            || ($value !== 0 && $expiresAt === 0)
            || $availableAt > $expiresAt) {
            throw new UnexpectedValueException('The stored backoff rate limiter state is invalid.');
        }
    }

    /**
     * Get the current epoch time in microseconds.
     */
    protected function currentTimeInMicroseconds(): int
    {
        return CarbonImmutable::hasTestNow()
            ? (int) CarbonImmutable::now()->getPreciseTimestamp(6)
            : (int) (microtime(true) * 1_000_000);
    }

    /**
     * Convert seconds to exact microseconds.
     */
    private function secondsToMicroseconds(int $seconds): int
    {
        return $this->multiplyExact($seconds, 1_000_000);
    }

    /**
     * Add exact shared-store integers without overflowing their common range.
     */
    private function addExact(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > AdmissionPolicy::MAX_INTEGER - $right) {
            throw new InvalidRateLimitException('The rate limiter timestamp exceeds the supported integer range.');
        }

        return $left + $right;
    }

    /**
     * Multiply exact shared-store integers without overflowing their common range.
     */
    private function multiplyExact(int $left, int $right): int
    {
        if ($left < 0 || $right < 0
            || ($left !== 0 && $right > intdiv(AdmissionPolicy::MAX_INTEGER, $left))) {
            throw new InvalidRateLimitException('The rate limiter value exceeds the supported integer range.');
        }

        return $left * $right;
    }
}
