<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Contracts\RefreshableLock;
use InvalidArgumentException;

class NoLock extends Lock implements RefreshableLock
{
    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        return true;
    }

    /**
     * Release the lock.
     */
    public function release(): bool
    {
        return true;
    }

    /**
     * Releases this lock in disregard of ownership.
     */
    public function forceRelease(): void
    {
    }

    /**
     * Returns the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): string
    {
        return $this->owner;
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        // Permanent lock with no explicit TTL requested - nothing to refresh
        if ($seconds === null && $this->seconds <= 0) {
            return true;
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                'Refresh requires a positive TTL. For a permanent lock, acquire it with seconds=0.'
            );
        }

        return true;
    }

    /**
     * Get the number of seconds until the lock expires.
     */
    public function getRemainingLifetime(): ?float
    {
        return null;
    }
}
