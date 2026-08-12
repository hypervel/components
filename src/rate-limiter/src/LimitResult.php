<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Hypervel\RateLimiter\Contracts\Decision;
use InvalidArgumentException;

final readonly class LimitResult implements Decision
{
    /**
     * Create a new limit result.
     */
    public function __construct(
        private bool $allowed,
        private int $limit,
        private int $remaining,
        private int $retryAfterMicroseconds,
        private int $resetAfterMicroseconds,
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('The rate limit must be at least one.');
        }

        if ($remaining < 0 || $remaining > $limit) {
            throw new InvalidArgumentException('The remaining rate limit must be between zero and the limit.');
        }

        if ($retryAfterMicroseconds < 0 || $resetAfterMicroseconds < 0) {
            throw new InvalidArgumentException('Rate limit durations may not be negative.');
        }

        if ($allowed && $retryAfterMicroseconds !== 0) {
            throw new InvalidArgumentException('An allowed rate limit result may not have a retry delay.');
        }

        if (! $allowed && $retryAfterMicroseconds === 0) {
            throw new InvalidArgumentException('A denied rate limit result must have a retry delay.');
        }
    }

    /**
     * Determine if the operation was allowed.
     */
    public function allowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Determine if the operation was denied.
     */
    public function denied(): bool
    {
        return ! $this->allowed;
    }

    /**
     * Get the configured capacity.
     */
    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Get the immediately available capacity.
     */
    public function remaining(): int
    {
        return $this->remaining;
    }

    /**
     * Get the number of seconds until the operation may be retried.
     */
    public function retryAfter(): int
    {
        return $this->seconds($this->retryAfterMicroseconds);
    }

    /**
     * Get the number of seconds until the limiter is fully reset.
     */
    public function resetAfter(): int
    {
        return $this->seconds($this->resetAfterMicroseconds);
    }

    /**
     * Round microseconds up to whole seconds.
     */
    private function seconds(int $microseconds): int
    {
        return intdiv($microseconds, 1_000_000)
            + ($microseconds % 1_000_000 === 0 ? 0 : 1);
    }
}
