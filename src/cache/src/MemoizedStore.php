<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use BadMethodCallException;
use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Exceptions\NotSupportedException;
use Hypervel\Contracts\Cache\AuthoritativeRawReadable;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RawReadable;
use Hypervel\Contracts\Cache\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

class MemoizedStore extends TaggableStore implements AuthoritativeRawReadable, CanFlushLocks, LockProvider, RawReadable
{
    /**
     * The memoized cache values.
     *
     * @var array<string, mixed>
     */
    protected array $cache = [];

    /**
     * The memoized tagged cache instances.
     *
     * @var array<string, MemoizedTaggedCache>
     */
    protected array $taggedCaches = [];

    /**
     * Create a new memoized cache instance.
     */
    public function __construct(
        protected string $name,
        protected Repository $repository,
    ) {
    }

    /**
     * Get the underlying cache store.
     */
    public function getInnerStore(): Store
    {
        return $this->repository->getStore();
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * Cached null sentinels are unwrapped to null. The raw value is memoized,
     * so later getRaw() calls still see the sentinel.
     */
    public function get(string $key): mixed
    {
        return NullSentinel::unwrap($this->getRaw($key));
    }

    /**
     * Retrieve an item from the cache without unwrapping sentinels.
     */
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
     * Get a memoized raw value, resolving a miss with the given callback.
     *
     * @param Closure(): mixed $callback
     */
    public function memoize(string $key, Closure $callback): mixed
    {
        $prefixedKey = $this->prefix($key);

        if (array_key_exists($prefixedKey, $this->cache)) {
            return $this->cache[$prefixedKey];
        }

        return $this->cache[$prefixedKey] = $callback();
    }

    /**
     * Retrieve an item without serving it from the memoized layer.
     */
    public function getAuthoritativeRaw(UnitEnum|string $key): mixed
    {
        $stringKey = (string) (is_object($key) ? enum_value($key) : $key);

        return $this->repository->getAuthoritativeRaw($stringKey);
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
            $this->manyRaw(array_map(fn ($key) => (string) $key, $keys))
        );
    }

    /**
     * Retrieve multiple items from the cache without unwrapping sentinels.
     */
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
        if ($missing !== []) {
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
     * Store an item in the cache if the key does not exist.
     *
     * The underlying repository decides whether the key exists, because a
     * memoized value may be stale.
     */
    public function add(string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        unset($this->cache[$this->prefix($key)]);

        return $this->repository->add($key, $value, $ttl);
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
     * Determine if the store can currently flush locks.
     */
    public function supportsFlushingLocks(): bool
    {
        $store = $this->repository->getStore();

        return $store instanceof CanFlushLocks && $store->supportsFlushingLocks();
    }

    /**
     * Flush all locks managed by the store.
     *
     * @throws BadMethodCallException
     */
    public function flushLocks(): bool
    {
        $store = $this->repository->getStore();

        if (! $store instanceof CanFlushLocks || ! $store->supportsFlushingLocks()) {
            throw new BadMethodCallException(sprintf(
                'The memoized cache store\'s underlying store [%s] does not support flushing locks.',
                $store::class
            ));
        }

        return $store->flushLocks();
    }

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        $store = $this->repository->getStore();

        return $store instanceof CanFlushLocks && $store->hasSeparateLockStore();
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
     * Begin executing a new tags operation.
     *
     * @throws BadMethodCallException
     * @throws NotSupportedException
     */
    public function tags(mixed $names): MemoizedTaggedCache
    {
        $names = is_array($names) ? $names : func_get_args();

        // Tag namespaces ignore array keys, so filtered lists must share a wrapper.
        $key = serialize(array_values($names));

        if (isset($this->taggedCaches[$key])) {
            return $this->taggedCaches[$key];
        }

        return $this->taggedCaches[$key] = new MemoizedTaggedCache(
            $this->repository->tags($names),
            $this
        );
    }

    /**
     * Determine if the underlying store currently supports tags.
     */
    public function supportsTags(): bool
    {
        return $this->repository->supportsTags();
    }

    /**
     * Get the tag mode of the underlying store.
     *
     * @throws BadMethodCallException
     */
    public function getTagMode(): TagMode
    {
        $store = $this->getInnerStore();

        if (! $store instanceof TaggableStore) {
            throw new BadMethodCallException('This cache store does not support tagging.');
        }

        return $store->getTagMode();
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
     * Forget a memoized value without changing the underlying cache.
     */
    public function forgetMemoized(string $key): void
    {
        unset($this->cache[$this->prefix($key)]);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        // Forget memoized values first so a failed flush cannot leave stale ones.
        $this->flushMemoized();

        return $this->repository->flush();
    }

    /**
     * Remove all memoized items from the tagged caches.
     */
    public function flushTagged(): void
    {
        foreach ($this->taggedCaches as $taggedCache) {
            $taggedCache->flushMemoized();
        }
    }

    /**
     * Forget all memoized values without changing the underlying cache.
     */
    public function flushMemoized(): void
    {
        $this->cache = [];

        $this->flushTagged();
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
