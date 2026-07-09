<?php

declare(strict_types=1);

namespace Hypervel\Cache\Limiters;

use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Contracts\Limiters\RefreshableLease;
use InvalidArgumentException;

class RefreshableConcurrencyLease implements RefreshableLease
{
    /**
     * Create a new lease instance.
     */
    public function __construct(
        protected RefreshableLock $lock,
    ) {
    }

    /**
     * Release the held slot if still owned by this lease.
     */
    public function release(): bool
    {
        return $this->lock->release();
    }

    /**
     * Get the owner identifier of this lease.
     */
    public function owner(): string
    {
        return $this->lock->owner();
    }

    /**
     * Refresh the lease's TTL if still owned by this lease.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        return $this->lock->refresh($seconds);
    }

    /**
     * Get the number of seconds until the lease expires.
     */
    public function getRemainingLifetime(): ?float
    {
        return $this->lock->getRemainingLifetime();
    }
}
