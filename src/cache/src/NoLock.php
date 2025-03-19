<?php

declare(strict_types=1);

namespace Hypervel\Cache;

class NoLock extends Lock
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
}
