<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use BadMethodCallException;
use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RawReadable;
use Hypervel\Contracts\Cache\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

class MemoizedStore implements LockProvider, RawReadable, Store
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
     *
     * Store contract method — returns the value with sentinels unwrapped to null,
     * matching the pre-refactor behavior (which returned whatever the inner
     * Repository's get() returned, i.e., unwrapped). Memoizes the raw value so
     * subsequent getRaw() calls see the sentinel.
     */
    public function get(string $key): mixed
    {
        return NullSentinel::unwrap($this->getRaw($key));
    }

    public function getRaw(UnitEnum|string $key): mixed
    {
        $stringKey = (string) (is_object($key) ? enum_value($key) : $key);
        $prefixedKey = $this->prefix($stringKey);

        if (array_key_exists($prefixedKey, $this->cache)) {
            return $this->cache[$prefixedKey];
        }

        return $this->cache[$prefixedKey] = $this->repository->getRaw($stringKey);
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        return array_map(
            NullSentinel::unwrap(...),
            $this->manyRaw(array_map(fn ($k) => (string) $k, $keys))
        );
    }

    public function manyRaw(array $keys): array
    {
        [$memoized, $missing] = [[], []];

        foreach ($keys as $key) {
            $stringKey = (string) $key;
            $prefixedKey = $this->prefix($stringKey);

            if (array_key_exists($prefixedKey, $this->cache)) {
                $memoized[$stringKey] = $this->cache[$prefixedKey];
            } else {
                $missing[] = $stringKey;
            }
        }

        $retrieved = [];
        if (count($missing) > 0) {
            $retrieved = $this->repository->manyRaw($missing);
            foreach ($retrieved as $key => $value) {
                $this->cache[$this->prefix((string) $key)] = $value;
            }
        }

        $result = [];
        foreach ($keys as $key) {
            $stringKey = (string) $key;
            $result[$stringKey] = $memoized[$stringKey] ?? $retrieved[$stringKey] ?? null;
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
            unset($this->cache[$this->prefix((string) $key)]);
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
