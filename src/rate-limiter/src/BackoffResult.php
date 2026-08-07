<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Hypervel\RateLimiter\Contracts\Decision;
use InvalidArgumentException;

final readonly class BackoffResult implements Decision
{
    /**
     * Create a new backoff result.
     */
    public function __construct(
        private bool $allowed,
        private int $failures,
        private int $retryAfterMicroseconds,
    ) {
        if ($failures < 0) {
            throw new InvalidArgumentException('The failure count may not be negative.');
        }

        if ($retryAfterMicroseconds < 0) {
            throw new InvalidArgumentException('The retry delay may not be negative.');
        }

        if ($allowed && $retryAfterMicroseconds !== 0) {
            throw new InvalidArgumentException('An allowed backoff result may not have a retry delay.');
        }

        if (! $allowed && $retryAfterMicroseconds === 0) {
            throw new InvalidArgumentException('A denied backoff result must have a retry delay.');
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
     * Get the recorded number of consecutive failures.
     */
    public function failures(): int
    {
        return $this->failures;
    }

    /**
     * Get the number of seconds until the operation may be retried.
     */
    public function retryAfter(): int
    {
        return intdiv($this->retryAfterMicroseconds, 1_000_000)
            + ($this->retryAfterMicroseconds % 1_000_000 === 0 ? 0 : 1);
    }
}
