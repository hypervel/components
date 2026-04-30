<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Events\CacheFailedOver;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Lock as LockContract;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RawReadable;
use Hypervel\Contracts\Cache\Repository as RepositoryContract;
use Hypervel\Contracts\Events\Dispatcher;
use RuntimeException;
use Throwable;
use UnitEnum;

class FailoverStore extends TaggableStore implements LockProvider, RawReadable
{
    /**
     * Context key prefix for the caches which failed on the last action.
     *
     * Scoped per instance via spl_object_id() so multiple failover stores
     * in the same coroutine don't share failure history.
     *
     * Stored in coroutine Context instead of an instance property because this
     * store is a worker-lifetime singleton (cached in CacheManager::$stores).
     * Instance state would leak across concurrent requests.
     */
    protected const string FAILING_CACHES_CONTEXT_PREFIX = '__cache.failover.failing_caches.';

    /**
     * Create a new failover store.
     *
     * @param array<int, string> $stores
     */
    public function __construct(
        protected CacheManager $cache,
        protected Dispatcher $events,
        protected array $stores
    ) {
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * Store contract method — unwraps sentinels to null, matching the
     * pre-refactor behavior (inner Repository's get() also unwrapped).
     */
    public function get(string $key): mixed
    {
        return NullSentinel::unwrap($this->getRaw($key));
    }

    public function getRaw(UnitEnum|string $key): mixed
    {
        return $this->attemptOnAllStores('getRaw', [$key]);
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
        return $this->attemptOnAllStores('manyRaw', [$keys]);
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, int $seconds): bool
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int|false
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): int|false
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Get a lock instance.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): LockContract
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Restore a lock instance using the owner identifier.
     */
    public function restoreLock(string $name, string $owner): LockContract
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Remove all expired tag set entries.
     */
    /**
     * @return null|array<string, int>
     */
    public function flushStaleTags(): ?array
    {
        foreach ($this->stores as $store) {
            $storeInstance = $this->store($store)->getStore();

            if (method_exists($storeInstance, 'flushStaleTags')) {
                return $storeInstance->flushStaleTags();
            }
        }

        return null;
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return $this->attemptOnAllStores(__FUNCTION__, func_get_args());
    }

    /**
     * Attempt the given method on all stores.
     *
     * @throws Throwable
     */
    protected function attemptOnAllStores(string $method, array $arguments): mixed
    {
        $contextKey = self::FAILING_CACHES_CONTEXT_PREFIX . spl_object_id($this);

        $failingCaches = CoroutineContext::get($contextKey, []);

        [$lastException, $failedCaches] = [null, []];

        try {
            foreach ($this->stores as $store) {
                try {
                    return $this->store($store)->{$method}(...$arguments);
                } catch (Throwable $e) {
                    $lastException = $e;

                    $failedCaches[] = $store;

                    if (! in_array($store, $failingCaches)) {
                        $this->events->dispatch(new CacheFailedOver($store, $e));
                    }
                }
            }
        } finally {
            CoroutineContext::set($contextKey, $failedCaches);
        }

        throw $lastException ?? new RuntimeException('All failover cache stores failed.');
    }

    /**
     * Get the cache store for the given store name.
     */
    protected function store(string $store): RepositoryContract
    {
        return $this->cache->store($store);
    }
}
