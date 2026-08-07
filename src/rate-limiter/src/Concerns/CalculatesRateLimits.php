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
use Hypervel\RateLimiter\SlidingWindow;
use Hypervel\Support\CarbonImmutable;
use UnexpectedValueException;

trait CalculatesRateLimits
{
    private const int WEIGHT_SCALE = 1_000_000;

    /**
     * Calculate an admission decision and update accepted state values.
     */
    protected function calculateConsume(
        AdmissionPolicy $policy,
        int $now,
        int &$value,
        int &$secondaryValue,
        int &$expiresAt,
    ): LimitResult {
        return match (true) {
            $policy instanceof Limit => $this->calculateFixedWindow(
                $policy,
                $now,
                $value,
                $secondaryValue,
                $expiresAt,
                true,
            ),
            $policy instanceof SlidingWindow => $this->calculateSlidingWindow(
                $policy,
                $now,
                $value,
                $secondaryValue,
                $expiresAt,
                true,
            ),
            $policy instanceof LeakyBucket => $this->calculateLeakyBucket(
                $policy,
                $now,
                $value,
                $secondaryValue,
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
        int $secondaryValue,
        int $expiresAt,
    ): LimitResult|BackoffResult {
        return match (true) {
            $policy instanceof Limit => $this->calculateFixedWindow(
                $policy,
                $now,
                $value,
                $secondaryValue,
                $expiresAt,
                false,
            ),
            $policy instanceof SlidingWindow => $this->calculateSlidingWindow(
                $policy,
                $now,
                $value,
                $secondaryValue,
                $expiresAt,
                false,
            ),
            $policy instanceof LeakyBucket => $this->calculateLeakyBucket(
                $policy,
                $now,
                $value,
                $secondaryValue,
                $expiresAt,
                false,
            ),
            $policy instanceof Backoff => $this->calculateBackoffInspection(
                $now,
                $value,
                $secondaryValue,
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
        int &$secondaryValue,
        int &$expiresAt,
    ): BackoffResult {
        $this->resetExpiredState($now, $value, $secondaryValue, $expiresAt);
        $this->validateBackoffState($value, $secondaryValue, $expiresAt);

        if ($value >= AdmissionPolicy::MAX_INTEGER) {
            throw new UnexpectedValueException('The stored backoff failure count cannot be incremented safely.');
        }

        ++$value;

        $delay = $value < $backoff->after
            ? 0
            : $this->backoffDelay($backoff, $value - $backoff->after);

        $secondaryValue = $delay === 0
            ? 0
            : $this->addExact($now, $this->secondsToMicroseconds($delay));
        $expiresAt = $this->addExact($now, $this->secondsToMicroseconds($backoff->resetAfter));

        return new BackoffResult(
            $delay === 0,
            $value,
            $delay === 0 ? 0 : $secondaryValue - $now,
        );
    }

    /**
     * Calculate a fixed-window decision.
     */
    private function calculateFixedWindow(
        Limit $policy,
        int $now,
        int &$value,
        int &$secondaryValue,
        int &$expiresAt,
        bool $consume,
    ): LimitResult {
        $this->resetExpiredState($now, $value, $secondaryValue, $expiresAt);
        $this->validateFixedWindowState($policy, $value, $secondaryValue, $expiresAt);

        if ($expiresAt === 0) {
            if (! $consume) {
                return new LimitResult(true, $policy->maxAttempts, $policy->maxAttempts, 0, 0);
            }

            $value = $policy->cost;
            $secondaryValue = 0;
            $expiresAt = $this->addExact(
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
     * Calculate a sliding-window decision.
     */
    private function calculateSlidingWindow(
        SlidingWindow $policy,
        int $now,
        int &$value,
        int &$secondaryValue,
        int &$expiresAt,
        bool $consume,
    ): LimitResult {
        $now -= $now % 1000;
        $this->resetExpiredState($now, $value, $secondaryValue, $expiresAt);
        $this->validateSlidingWindowState($policy, $value, $secondaryValue, $expiresAt);

        $window = $this->secondsToMicroseconds($policy->windowSeconds);

        if ($expiresAt === 0) {
            if (! $consume) {
                return new LimitResult(true, $policy->maxAttempts, $policy->maxAttempts, 0, 0);
            }

            $value = $policy->cost;
            $secondaryValue = 0;
            $expiresAt = $this->addExact($now, $this->multiplyExact($window, 2));

            return new LimitResult(
                true,
                $policy->maxAttempts,
                $policy->maxAttempts - $value,
                0,
                $expiresAt - $now,
            );
        }

        $current = $value;
        $previous = $secondaryValue;
        $windowEnd = $expiresAt - $window;
        $rotated = $now >= $windowEnd;

        if ($rotated) {
            $previous = $current;
            $current = 0;
            $remaining = $expiresAt - $now;
        } else {
            $remaining = $windowEnd - $now;
        }

        $weight = min(self::WEIGHT_SCALE, intdiv($remaining, $policy->windowSeconds));
        $weightedPrevious = intdiv(
            $this->multiplyExact($previous, $weight),
            self::WEIGHT_SCALE,
        );
        $estimated = $current + $weightedPrevious;
        $allowed = $estimated <= $policy->maxAttempts - $policy->cost;
        $resetAfter = $expiresAt - $now;

        if (! $allowed) {
            return new LimitResult(
                false,
                $policy->maxAttempts,
                max(0, $policy->maxAttempts - $estimated),
                $this->slidingWindowRetryAfter($policy, $current, $previous, $remaining),
                $resetAfter,
            );
        }

        if (! $consume) {
            return new LimitResult(
                true,
                $policy->maxAttempts,
                $policy->maxAttempts - $estimated,
                0,
                $resetAfter,
            );
        }

        if ($rotated) {
            $value = $policy->cost;
            $secondaryValue = $previous;
            $expiresAt = $this->addExact($expiresAt, $window);
            $resetAfter = $expiresAt - $now;
        } else {
            $value = $current + $policy->cost;
        }

        return new LimitResult(
            true,
            $policy->maxAttempts,
            $policy->maxAttempts - $estimated - $policy->cost,
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
        int &$secondaryValue,
        int &$expiresAt,
        bool $consume,
    ): LimitResult {
        $this->resetExpiredState($now, $value, $secondaryValue, $expiresAt);
        $this->validateLeakyBucketState($value, $secondaryValue, $expiresAt);

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
        $secondaryValue = 0;
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
        int $secondaryValue,
        int $expiresAt,
    ): BackoffResult {
        $this->resetExpiredState($now, $value, $secondaryValue, $expiresAt);
        $this->validateBackoffState($value, $secondaryValue, $expiresAt);

        $retryAfter = max($secondaryValue - $now, 0);

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
     * Calculate the first millisecond at which a sliding-window cost fits.
     */
    private function slidingWindowRetryAfter(
        SlidingWindow $policy,
        int $current,
        int $previous,
        int $remaining,
    ): int {
        $available = $policy->maxAttempts - $current - $policy->cost;

        if ($available >= 0) {
            return $this->weightedSlidingWindowRetryAfter(
                $previous,
                $available,
                $remaining,
                $policy->windowSeconds,
            );
        }

        return $remaining + $this->weightedSlidingWindowRetryAfter(
            $current,
            $policy->maxAttempts - $policy->cost,
            $this->secondsToMicroseconds($policy->windowSeconds),
            $policy->windowSeconds,
        );
    }

    /**
     * Invert the weighted counter's integer floors.
     */
    private function weightedSlidingWindowRetryAfter(
        int $previous,
        int $available,
        int $remaining,
        int $windowSeconds,
    ): int {
        $maximumWeight = intdiv(
            $this->multiplyExact($available + 1, self::WEIGHT_SCALE) - 1,
            $previous,
        );
        $maximumRemainingMilliseconds = intdiv(
            $this->multiplyExact($maximumWeight + 1, $windowSeconds) - 1,
            1000,
        );

        return (intdiv($remaining, 1000) - $maximumRemainingMilliseconds) * 1000;
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
        int &$secondaryValue,
        int &$expiresAt,
    ): void {
        if ($expiresAt !== 0 && $expiresAt <= $now) {
            $value = 0;
            $secondaryValue = 0;
            $expiresAt = 0;
        }
    }

    /**
     * Validate fixed-window state loaded from a store.
     */
    private function validateFixedWindowState(
        Limit $policy,
        int $value,
        int $secondaryValue,
        int $expiresAt,
    ): void {
        if ($value < 0 || $value > $policy->maxAttempts
            || $secondaryValue !== 0 || $expiresAt < 0
            || ($expiresAt === 0 && $value !== 0)) {
            throw new UnexpectedValueException('The stored fixed-window rate limiter state is invalid.');
        }
    }

    /**
     * Validate sliding-window state loaded from a store.
     */
    private function validateSlidingWindowState(
        SlidingWindow $policy,
        int $value,
        int $secondaryValue,
        int $expiresAt,
    ): void {
        if ($value === 0 && $secondaryValue === 0 && $expiresAt === 0) {
            return;
        }

        if ($value < 1 || $value > $policy->maxAttempts
            || $secondaryValue < 0 || $secondaryValue > $policy->maxAttempts
            || $expiresAt < 1 || $expiresAt > AdmissionPolicy::MAX_INTEGER) {
            throw new UnexpectedValueException('The stored sliding-window rate limiter state is invalid.');
        }
    }

    /**
     * Validate leaky-bucket state loaded from a store.
     */
    private function validateLeakyBucketState(int $value, int $secondaryValue, int $expiresAt): void
    {
        if ($value < 0 || $secondaryValue !== 0 || $expiresAt < 0 || $value !== $expiresAt) {
            throw new UnexpectedValueException('The stored leaky-bucket rate limiter state is invalid.');
        }
    }

    /**
     * Validate backoff state loaded from a store.
     */
    private function validateBackoffState(int $value, int $secondaryValue, int $expiresAt): void
    {
        if ($value < 0 || $secondaryValue < 0 || $expiresAt < 0
            || ($value === 0 && ($secondaryValue !== 0 || $expiresAt !== 0))
            || ($value !== 0 && $expiresAt === 0)
            || $secondaryValue > $expiresAt) {
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
