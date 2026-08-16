<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Hypervel\RateLimiter\Contracts\Decision;
use InvalidArgumentException;

final readonly class CooldownResult implements Decision
{
    /**
     * Create a new cooldown result.
     */
    public function __construct(
        private bool $allowed,
        private int $retryAfterMicroseconds,
    ) {
        if ($retryAfterMicroseconds < 0) {
            throw new InvalidArgumentException('The cooldown retry delay may not be negative.');
        }

        if ($allowed && $retryAfterMicroseconds !== 0) {
            throw new InvalidArgumentException('An allowed cooldown result may not have a retry delay.');
        }

        if (! $allowed && $retryAfterMicroseconds === 0) {
            throw new InvalidArgumentException('A denied cooldown result must have a retry delay.');
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
     * Get the number of seconds until the operation may be retried.
     */
    public function retryAfter(): int
    {
        return intdiv($this->retryAfterMicroseconds, 1_000_000)
            + ($this->retryAfterMicroseconds % 1_000_000 === 0 ? 0 : 1);
    }
}
