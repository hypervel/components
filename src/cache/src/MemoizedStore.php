<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use BadMethodCallException;
use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;

class MemoizedStore implements LockProvider, Store
{
    /**
     * The memoized cache values.
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    /**
     * Create a new memoized cache instance.
     */
    public function __construct(
        protected string $name,
        protected Repository $repository,
    ) {
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        $prefixedKey = $this->prefix($key);

        if (array_key_exists($prefixedKey, $this->cache)) {
            return $this->cache[$prefixedKey];
        }

        return $this->cache[$prefixedKey] = $this->repository->get($key);
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        [$memoized, $retrieved, $missing] = [[], [], []];

        foreach ($keys as $key) {
            $prefixedKey = $this->prefix($key);

            if (array_key_exists($prefixedKey, $this->cache)) {
                $memoized[$key] = $this->cache[$prefixedKey];
            } else {
                $missing[] = $key;
            }
        }

        if (count($missing) > 0) {
            $retrieved = tap($this->repository->many($missing), function ($values) {
                foreach ($values as $key => $value) {
                    $this->cache[$this->prefix($key)] = $value;
                }
            });
        }

        $result = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $memoized)) {
                $result[$key] = $memoized[$key];
            } else {
                $result[$key] = $retrieved[$key];
            }
        }

        return $result;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        unset($this->cache[$this->prefix($key)]);

        return $this->repository->put($key, $value, $seconds);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, int $seconds): bool
    {
        foreach ($values as $key => $value) {
            unset($this->cache[$this->prefix($key)]);
        }

        return $this->repository->putMany($values, $seconds);
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): bool|int
    {
        unset($this->cache[$this->prefix($key)]);

        return $this->repository->increment($key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): bool|int
    {
        unset($this->cache[$this->prefix($key)]);

        return $this->repository->decrement($key, $value);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        unset($this->cache[$this->prefix($key)]);

        return $this->repository->forever($key, $value);
    }

    /**
     * Get a lock instance.
     *
     * @throws BadMethodCallException
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): LockContract
    {
        if (! $this->repository->getStore() instanceof LockProvider) {
            throw new BadMethodCallException('This cache store does not support locks.');
        }

        return $this->repository->getStore()->lock(...func_get_args());
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @throws BadMethodCallException
     */
    public function restoreLock(string $name, string $owner): LockContract
    {
        if (! $this->repository->getStore() instanceof LockProvider) {
            throw new BadMethodCallException('This cache store does not support locks.');
        }

        return $this->repository->getStore()->restoreLock(...func_get_args());
    }

    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        unset($this->cache[$this->prefix($key)]);

        return $this->repository->touch($key, $seconds);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        unset($this->cache[$this->prefix($key)]);

        return $this->repository->forget($key);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->cache = [];

        return $this->repository->flush();
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return $this->repository->getPrefix();
    }

    /**
     * Prefix the given key.
     */
    protected function prefix(string $key): string
    {
        return $this->getPrefix() . $key;
    }
}
