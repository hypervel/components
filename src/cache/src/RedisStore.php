<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hypervel\Cache\Contracts\LockProvider;

class RedisStore extends TaggableStore implements LockProvider
{
    protected RedisFactory $factory;

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
     * Create a new Redis store.
     */
    public function __construct(RedisFactory $factory, string $prefix = '', string $connection = 'default')
    {
        $this->factory = $factory;
        $this->setPrefix($prefix);
        $this->setConnection($connection);
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        $value = $this->connection()->get($this->prefix . $key);

        return $this->unserialize($value);
    }

    /**
     * Retrieve multiple items from the cache by key.
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        $results = [];

        $values = $this->connection()->mget(array_map(function ($key) {
            return $this->prefix . $key;
        }, $keys));

        foreach ($values as $index => $value) {
            $results[$keys[$index]] = $this->unserialize($value);
        }

        return $results;
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return (bool) $this->connection()->setex(
            $this->prefix . $key,
            (int) max(1, $seconds),
            $this->serialize($value)
        );
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, int $seconds): bool
    {
        $this->connection()->multi();

        $manyResult = null;

        foreach ($values as $key => $value) {
            $result = $this->put($key, $value, $seconds);

            $manyResult = is_null($manyResult) ? $result : $result && $manyResult;
        }

        $this->connection()->exec();

        return $manyResult ?: false;
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        $lua = "return redis.call('exists',KEYS[1])<1 and redis.call('setex',KEYS[1],ARGV[2],ARGV[1])";

        return (bool) $this->connection()->eval(
            $lua,
            [
                $this->prefix . $key,
                $this->serialize($value),
                (int) max(1, $seconds),
            ],
            1
        );
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        return $this->connection()->incrby($this->prefix . $key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->connection()->decrby($this->prefix . $key, $value);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        return (bool) $this->connection()->set($this->prefix . $key, $this->serialize($value));
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
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        return (bool) $this->connection()->del($this->prefix . $key);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->connection()->flushdb();

        return true;
    }

    /**
     * Begin executing a new tags operation.
     */
    public function tags(mixed $names): RedisTaggedCache
    {
        return new RedisTaggedCache(
            $this,
            new RedisTagSet($this, is_array($names) ? $names : func_get_args())
        );
    }

    /**
     * Get the Redis connection instance.
     */
    public function connection(): RedisProxy
    {
        return $this->factory->get($this->connection);
    }

    /**
     * Get the Redis connection instance that should be used to manage locks.
     */
    public function lockConnection(): RedisProxy
    {
        return $this->factory->get($this->lockConnection ?? $this->connection);
    }

    /**
     * Specify the name of the connection that should be used to store data.
     */
    public function setConnection(string $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * Specify the name of the connection that should be used to manage locks.
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
    public function getRedis(): RedisFactory
    {
        return $this->factory;
    }

    /**
     * Set the cache key prefix.
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = ! empty($prefix) ? $prefix . ':' : '';
    }

    /**
     * Serialize the value.
     */
    protected function serialize(mixed $value): mixed
    {
        // is_nan() doesn't work in strict mode; NaN is the only value where $v !== $v
        return is_numeric($value) && ! in_array($value, [INF, -INF]) && ($value === $value) ? $value : serialize($value); // @phpstan-ignore identical.alwaysTrue
    }

    /**
     * Unserialize the value.
     */
    protected function unserialize(mixed $value): mixed
    {
        if ($value === null || $value === false) {
            return null;
        }
        return is_numeric($value) ? $value : unserialize((string) $value);
    }
}
