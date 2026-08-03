<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\RefreshableLock;
use InvalidArgumentException;

class SwooleLock extends Lock implements RefreshableLock
{
    /**
     * Create a new lock instance.
     */
    public function __construct(
        protected SwooleStore $store,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        return $this->store->acquireLock($this->name, $this->owner, $this->seconds);
    }

    /**
     * Release the lock.
     */
    public function release(): bool
    {
        return $this->store->releaseLock($this->name, $this->owner);
    }

    /**
     * Release this lock in disregard of ownership.
     */
    public function forceRelease(): void
    {
        $this->store->forceReleaseLock($this->name);
    }

    /**
     * Return the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): ?string
    {
        return $this->store->getLockOwner($this->name);
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        if ($seconds === null && $this->seconds <= 0) {
            return $this->isOwnedByCurrentProcess();
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                'Refresh requires a positive TTL. For a permanent lock, acquire it with seconds=0.'
            );
        }

        return $this->store->refreshLock($this->name, $this->owner, $seconds);
    }

    /**
     * Get the number of seconds until the lock expires.
     */
    public function getRemainingLifetime(): ?float
    {
        return $this->store->getLockRemainingLifetime($this->name);
    }
}
