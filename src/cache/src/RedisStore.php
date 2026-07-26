<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Cache\Redis\AllTaggedCache;
use Hypervel\Cache\Redis\AllTagSet;
use Hypervel\Cache\Redis\AnyTaggedCache;
use Hypervel\Cache\Redis\AnyTagSet;
use Hypervel\Cache\Redis\Operations\Add;
use Hypervel\Cache\Redis\Operations\AllTagOperations;
use Hypervel\Cache\Redis\Operations\AnyTagOperations;
use Hypervel\Cache\Redis\Operations\Decrement;
use Hypervel\Cache\Redis\Operations\Flush;
use Hypervel\Cache\Redis\Operations\Forever;
use Hypervel\Cache\Redis\Operations\Forget;
use Hypervel\Cache\Redis\Operations\Get;
use Hypervel\Cache\Redis\Operations\Increment;
use Hypervel\Cache\Redis\Operations\Many;
use Hypervel\Cache\Redis\Operations\Put;
use Hypervel\Cache\Redis\Operations\PutMany;
use Hypervel\Cache\Redis\Operations\Touch;
use Hypervel\Cache\Redis\Support\Serialization;
use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Redis\RedisProxy;
use RuntimeException;

class RedisStore extends TaggableStore implements CanFlushLocks, LockProvider
{
    protected Redis $redis;

    /**
     * A string that should be prepended to keys.
     */
    protected string $prefix;

    /**
     * The Redis connection instance that should be used to manage locks.
     */
    protected string $connection;

    /**
     * The name of the connection that should be used for locks.
     */
    protected string $lockConnection;

    /**
     * The tag mode (All or Any).
     */
    protected TagMode $tagMode = TagMode::All;

    /**
     * Cached StoreContext instance.
     */
    private ?StoreContext $context = null;

    /**
     * Cached Serialization instance.
     */
    private ?Serialization $serialization = null;

    /**
     * Cached shared operation instances.
     */
    private ?Get $getOperation = null;

    private ?Many $manyOperation = null;

    private ?Put $putOperation = null;

    private ?PutMany $putManyOperation = null;

    private ?Add $addOperation = null;

    private ?Forever $foreverOperation = null;

    private ?Forget $forgetOperation = null;

    private ?Touch $touchOperation = null;

    private ?Increment $incrementOperation = null;

    private ?Decrement $decrementOperation = null;

    private ?Flush $flushOperation = null;

    /**
     * Cached tag operation containers.
     */
    private ?AnyTagOperations $anyTagOperations = null;

    private ?AllTagOperations $allTagOperations = null;

    /**
     * The classes that should be allowed during unserialization.
     */
    protected array|bool|null $serializableClasses;

    /**
     * Create a new Redis store.
     */
    public function __construct(
        Redis $redis,
        string $prefix = '',
        string $connection = 'default',
        array|bool|null $serializableClasses = null,
    ) {
        $this->redis = $redis;
        $this->serializableClasses = $serializableClasses;
        $this->setPrefix($prefix);
        $this->setConnection($connection);
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        return $this->getGetOperation()->execute($key);
    }

    /**
     * Retrieve multiple items from the cache by key.
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        return $this->getManyOperation()->execute($keys);
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->getPutOperation()->execute($key, $value, $seconds);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, int $seconds): bool
    {
        return $this->getPutManyOperation()->execute($values, $seconds);
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        return $this->getAddOperation()->execute($key, $value, $seconds);
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        return $this->getIncrementOperation()->execute($key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->getDecrementOperation()->execute($key, $value);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->getForeverOperation()->execute($key, $value);
    }

    /**
     * Get a lock instance.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): RedisLock
    {
        return new RedisLock($this->lockConnection(), $this->prefix . $name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     */
    public function restoreLock(string $name, string $owner): RedisLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        if ($this->tagMode === TagMode::Any) {
            return $this->anyTagOps()->touch()->execute($key, $seconds);
        }

        return $this->getTouchOperation()->execute($key, $seconds);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        if ($this->tagMode === TagMode::Any) {
            return $this->anyTagOps()->forget()->execute($key);
        }

        return $this->getForgetOperation()->execute($key);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        return $this->getFlushOperation()->execute();
    }

    /**
     * Determine if the store can currently flush locks.
     */
    public function supportsFlushingLocks(): bool
    {
        return $this->hasSeparateLockStore();
    }

    /**
     * Remove all locks from the store.
     *
     * @throws RuntimeException
     */
    public function flushLocks(): bool
    {
        if (! $this->hasSeparateLockStore()) {
            throw new RuntimeException('Flushing locks is only supported when the lock store is separate from the cache store.');
        }

        $this->lockConnection()->flushdb();

        return true;
    }

    /**
     * Get the any tag operations container.
     *
     * Use this to access all any-mode tagged cache operations.
     */
    public function anyTagOps(): AnyTagOperations
    {
        return $this->anyTagOperations ??= new AnyTagOperations(
            $this->getContext(),
            $this->getSerialization()
        );
    }

    /**
     * Get the all tag operations container.
     *
     * Use this to access all all-mode tagged cache operations.
     */
    public function allTagOps(): AllTagOperations
    {
        return $this->allTagOperations ??= new AllTagOperations(
            $this->getContext(),
            $this->getSerialization()
        );
    }

    /**
     * Begin executing a new tags operation.
     */
    public function tags(mixed $names): AllTaggedCache|AnyTaggedCache
    {
        $names = is_array($names) ? $names : func_get_args();

        if ($this->tagMode === TagMode::Any) {
            return new AnyTaggedCache(
                $this,
                new AnyTagSet($this, $names)
            );
        }

        return new AllTaggedCache(
            $this,
            new AllTagSet($this, $names)
        );
    }

    /**
     * Remove all expired tag set entries.
     *
     * Returns an array of snake_case stat keys with integer values,
     * or null if no stats are available.
     *
     * @return null|array<string, int>
     */
    public function flushStaleTags(): ?array
    {
        if ($this->tagMode === TagMode::Any) {
            return $this->anyTagOps()->prune()->execute();
        }

        return $this->allTagOps()->prune()->execute();
    }

    /**
     * Set the tag mode.
     *
     * Boot-only. Mutates state on a per-worker singleton; runtime mutation
     * races across coroutines.
     */
    public function setTagMode(TagMode|string $mode): static
    {
        $this->tagMode = $mode instanceof TagMode
            ? $mode
            : TagMode::fromConfig($mode);

        $this->clearCachedInstances();

        return $this;
    }

    /**
     * Get the tag mode.
     */
    public function getTagMode(): TagMode
    {
        return $this->tagMode;
    }

    /**
     * Pin a pool connection for the duration of a callback.
     *
     * All Redis operations inside the callback reuse the same connection,
     * avoiding multiple pool checkouts.
     */
    public function withPinnedConnection(callable $callback): mixed
    {
        return $this->connection()->withPinnedConnection($callback);
    }

    /**
     * Get the Redis connection instance.
     */
    public function connection(): RedisProxy
    {
        return $this->redis->connection($this->connection);
    }

    /**
     * Get the Redis connection instance that should be used to manage locks.
     */
    public function lockConnection(): RedisProxy
    {
        return $this->redis->connection($this->lockConnection ?? $this->connection);
    }

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        return ($this->lockConnection ?? $this->connection) !== $this->connection;
    }

    /**
     * Specify the name of the connection that should be used to store data.
     *
     * Boot-only. Persists on the cached store for the worker lifetime and
     * triggers cache invalidation of resolved operations; per-request use
     * races across coroutines.
     */
    public function setConnection(?string $connection): void
    {
        $this->connection = $connection ?? 'default';
        $this->clearCachedInstances();
    }

    /**
     * Specify the name of the connection that should be used to manage locks.
     *
     * Boot-only. Persists on the cached store for the worker lifetime;
     * per-request use races across coroutines.
     */
    public function setLockConnection(string $connection): static
    {
        $this->lockConnection = $connection;

        return $this;
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Get the Redis database instance.
     */
    public function getRedis(): Redis
    {
        return $this->redis;
    }

    /**
     * Set the cache key prefix.
     *
     * Boot-only. Persists on the cached store for the worker lifetime and
     * triggers cache invalidation of resolved operations; per-request use
     * races across coroutines.
     */
    public function setPrefix(?string $prefix): void
    {
        $this->prefix = $prefix ?? '';
        $this->clearCachedInstances();
    }

    /**
     * Get the StoreContext instance.
     */
    public function getContext(): StoreContext
    {
        return $this->context ??= new StoreContext(
            $this->redis,
            $this->connection,
            $this->prefix,
            $this->tagMode,
        );
    }

    /**
     * Get the Serialization instance.
     */
    public function getSerialization(): Serialization
    {
        return $this->serialization ??= new Serialization($this->serializableClasses);
    }

    /**
     * Clear all cached instances when connection or prefix changes.
     */
    private function clearCachedInstances(): void
    {
        $this->context = null;
        $this->serialization = null;

        // Shared operations
        $this->getOperation = null;
        $this->manyOperation = null;
        $this->putOperation = null;
        $this->putManyOperation = null;
        $this->addOperation = null;
        $this->foreverOperation = null;
        $this->forgetOperation = null;
        $this->touchOperation = null;
        $this->incrementOperation = null;
        $this->decrementOperation = null;
        $this->flushOperation = null;

        // Tag operation containers
        $this->anyTagOperations = null;
        $this->allTagOperations = null;
    }

    private function getGetOperation(): Get
    {
        return $this->getOperation ??= new Get(
            $this->getContext(),
            $this->getSerialization()
        );
    }

    private function getManyOperation(): Many
    {
        return $this->manyOperation ??= new Many(
            $this->getContext(),
            $this->getSerialization()
        );
    }

    private function getPutOperation(): Put
    {
        return $this->putOperation ??= new Put(
            $this->getContext(),
            $this->getSerialization()
        );
    }

    private function getPutManyOperation(): PutMany
    {
        return $this->putManyOperation ??= new PutMany(
            $this->getContext(),
            $this->getSerialization()
        );
    }

    private function getAddOperation(): Add
    {
        return $this->addOperation ??= new Add(
            $this->getContext(),
            $this->getSerialization()
        );
    }

    private function getForeverOperation(): Forever
    {
        return $this->foreverOperation ??= new Forever(
            $this->getContext(),
            $this->getSerialization()
        );
    }

    private function getForgetOperation(): Forget
    {
        return $this->forgetOperation ??= new Forget($this->getContext());
    }

    private function getTouchOperation(): Touch
    {
        return $this->touchOperation ??= new Touch($this->getContext());
    }

    private function getIncrementOperation(): Increment
    {
        return $this->incrementOperation ??= new Increment($this->getContext());
    }

    private function getDecrementOperation(): Decrement
    {
        return $this->decrementOperation ??= new Decrement($this->getContext());
    }

    private function getFlushOperation(): Flush
    {
        return $this->flushOperation ??= new Flush($this->getContext());
    }
}
