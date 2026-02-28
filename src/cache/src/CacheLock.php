<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

class CacheLock extends Lock
{
    /**
     * The cache store implementation.
     */
    protected Store $store;

    /**
     * Create a new lock instance.
     */
    public function __construct(Store $store, string $name, int $seconds, ?string $owner = null)
    {
        parent::__construct($name, $seconds, $owner);

        $this->store = $store;
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        if (method_exists($this->store, 'add') && $this->seconds > 0) {
            return $this->store->add(
                $this->name,
                $this->owner,
                $this->seconds
            );
        }

        if (! is_null($this->store->get($this->name))) {
            return false;
        }

        return ($this->seconds > 0)
                ? $this->store->put($this->name, $this->owner, $this->seconds)
                : $this->store->forever($this->name, $this->owner);
    }

    /**
     * Release the lock.
     */
    public function release(): bool
    {
        if ($this->isOwnedByCurrentProcess()) {
            return $this->store->forget($this->name);
        }

        return false;
    }

    /**
     * Releases this lock regardless of ownership.
     */
    public function forceRelease(): void
    {
        $this->store->forget($this->name);
    }

    /**
     * Returns the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): string
    {
        return $this->store->get($this->name);
    }
}
