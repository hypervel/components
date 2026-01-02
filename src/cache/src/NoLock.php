<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Contracts\RefreshableLock;

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
     */
    public function refresh(?int $seconds = null): bool
    {
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
