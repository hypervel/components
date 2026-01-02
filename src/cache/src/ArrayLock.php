<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Carbon\Carbon;
use Hypervel\Cache\Contracts\RefreshableLock;

class ArrayLock extends Lock implements RefreshableLock
{
    /**
     * The parent array cache store.
     */
    protected ArrayStore $store;

    /**
     * Create a new lock instance.
     */
    public function __construct(ArrayStore $store, string $name, int $seconds, ?string $owner = null)
    {
        parent::__construct($name, $seconds, $owner);

        $this->store = $store;
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        $expiration = $this->store->locks[$this->name]['expiresAt'] ?? Carbon::now()->addSecond();

        if ($this->exists() && $expiration->isFuture()) {
            return false;
        }

        $this->store->locks[$this->name] = [
            'owner' => $this->owner,
            'expiresAt' => $this->seconds === 0 ? null : Carbon::now()->addSeconds($this->seconds),
        ];

        return true;
    }

    /**
     * Release the lock.
     */
    public function release(): bool
    {
        if (! $this->exists()) {
            return false;
        }

        if (! $this->isOwnedByCurrentProcess()) {
            return false;
        }

        $this->forceRelease();

        return true;
    }

    /**
     * Releases this lock in disregard of ownership.
     */
    public function forceRelease(): void
    {
        unset($this->store->locks[$this->name]);
    }

    /**
     * Determine if the current lock exists.
     */
    protected function exists(): bool
    {
        return isset($this->store->locks[$this->name]);
    }

    /**
     * Returns the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): string
    {
        return $this->store->locks[$this->name]['owner'];
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     */
    public function refresh(?int $seconds = null): bool
    {
        if (! $this->exists()) {
            return false;
        }

        if (! $this->isOwnedByCurrentProcess()) {
            return false;
        }

        $seconds ??= $this->seconds;

        $this->store->locks[$this->name]['expiresAt'] = $seconds === 0
            ? null
            : Carbon::now()->addSeconds($seconds);

        return true;
    }

    /**
     * Get the number of seconds until the lock expires.
     */
    public function getRemainingLifetime(): ?float
    {
        if (! $this->exists()) {
            return null;
        }

        $expiresAt = $this->store->locks[$this->name]['expiresAt'];

        if ($expiresAt === null) {
            return null;
        }

        if ($expiresAt->isPast()) {
            return null;
        }

        return (float) Carbon::now()->diffInSeconds($expiresAt);
    }
}
