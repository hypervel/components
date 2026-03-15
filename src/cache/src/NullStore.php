<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\LockProvider;

class NullStore extends TaggableStore implements LockProvider
{
    use RetrievesMultipleKeys;

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        return null;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return false;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): bool
    {
        return false;
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, mixed $value = 1): bool
    {
        return false;
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        return false;
    }

    /**
     * Get a lock instance.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): NoLock
    {
        return new NoLock($name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     */
    public function restoreLock(string $name, string $owner): NoLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        return true;
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        return true;
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return '';
    }
}
