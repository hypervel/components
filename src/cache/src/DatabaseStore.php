<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hyperf\Support\Traits\InteractsWithTime;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Builder;
use Hypervel\Support\Arr;
use Throwable;

class DatabaseStore implements Store, LockProvider
{
    use InteractsWithTime;

    /**
     * The database connection resolver.
     */
    protected ConnectionResolverInterface $resolver;

    /**
     * The connection name.
     */
    protected string $connectionName;

    /**
     * The name of the cache table.
     */
    protected string $table;

    /**
     * A string that should be prepended to keys.
     */
    protected string $prefix;

    /**
     * The name of the cache locks table.
     */
    protected string $lockTable;

    /**
     * An array representation of the lock lottery odds.
     */
    protected array $lockLottery;

    /**
     * The default number of seconds that a lock should be held.
     */
    protected int $defaultLockTimeoutInSeconds;

    /**
     * Create a new database store.
     */
    public function __construct(
        ConnectionResolverInterface $resolver,
        string $connectionName,
        string $table,
        string $prefix = '',
        string $lockTable = 'cache_locks',
        array $lockLottery = [2, 100],
        int $defaultLockTimeoutInSeconds = 86400
    ) {
        $this->resolver = $resolver;
        $this->connectionName = $connectionName;
        $this->table = $table;
        $this->prefix = $prefix;
        $this->lockTable = $lockTable;
        $this->lockLottery = $lockLottery;
        $this->defaultLockTimeoutInSeconds = $defaultLockTimeoutInSeconds;
    }

    /**
     * Get a fresh connection from the pool.
     */
    protected function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connectionName);
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        return $this->many([$key])[$key];
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        if (count($keys) === 0) {
            return [];
        }

        $results = array_fill_keys($keys, null);

        // First we will retrieve all of the items from the cache using their keys and
        // the prefix value.
        $values = $this->table()
            ->whereIn('key', array_map(function ($key) {
                return $this->prefix . $key;
            }, $keys))
            ->get();

        $currentTime = $this->currentTime();

        // If this cache expiration date is past the current time, we will remove this
        // item from the cache. Then we will return a null value since the cache is
        // expired. We will use "Carbon" to make this comparison with the column.
        [$values, $expired] = $values->partition(function ($cache) use ($currentTime) {
            return $cache->expiration > $currentTime;
        });

        if ($expired->isNotEmpty()) {
            $this->table()
                ->whereIn('key', $expired->pluck('key')->all())
                ->where('expiration', '<=', $currentTime)
                ->delete();
        }

        return Arr::map($results, function ($value, $key) use ($values) {
            if ($cache = $values->firstWhere('key', $this->prefix . $key)) {
                return $this->unserialize($cache->value);
            }

            return $value;
        });
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        return $this->putMany([$key => $value], $seconds);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, int $seconds): bool
    {
        $serializedValues = [];

        $expiration = $this->currentTime() + $seconds;

        foreach ($values as $key => $value) {
            $serializedValues[] = [
                'key' => $this->prefix . $key,
                'value' => $this->serialize($value),
                'expiration' => $expiration,
            ];
        }

        return $this->table()->upsert($serializedValues, 'key') > 0;
    }

    /**
     * Store an item in the cache if the key doesn't exist.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        if (! is_null($this->get($key))) {
            return false;
        }

        $key = $this->prefix . $key;
        $value = $this->serialize($value);
        $expiration = $this->currentTime() + $seconds;

        try {
            return $this->table()->insert(compact('key', 'value', 'expiration'));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): bool|int
    {
        return $this->incrementOrDecrement($key, $value, function ($current, $value) {
            return $current + $value;
        });
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): bool|int
    {
        return $this->incrementOrDecrement($key, $value, function ($current, $value) {
            return $current - $value;
        });
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, 315360000);
    }

    /**
     * Get a lock instance.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): DatabaseLock
    {
        return new DatabaseLock(
            $this->resolver,
            $this->connectionName,
            $this->prefix . $name,
            $this->lockTable,
            $seconds,
            $owner,
            $this->lockLottery,
            $this->defaultLockTimeoutInSeconds
        );
    }

    /**
     * Restore a lock instance using the owner identifier.
     */
    public function restoreLock(string $name, string $owner): DatabaseLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        $this->table()->where('key', '=', $this->prefix . $key)->delete();

        return true;
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        $this->table()->delete();

        return true;
    }

    /**
     * Remove all expired entries from the cache.
     */
    public function pruneExpired(): int
    {
        return $this->table()
            ->where('expiration', '<=', $this->currentTime())
            ->delete();
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Increment or decrement an item in the cache.
     */
    protected function incrementOrDecrement(string $key, int $value, Closure $callback): bool|int
    {
        return $this->connection()->transaction(function ($connection) use ($key, $value, $callback) {
            $prefixed = $this->prefix . $key;

            $cache = $connection->table($this->table)
                ->where('key', $prefixed)
                ->lockForUpdate()
                ->first();

            if (is_null($cache)) {
                return false;
            }

            $cache = is_array($cache) ? (object) $cache : $cache;

            $current = $this->unserialize($cache->value);

            if (! is_numeric($current)) {
                return false;
            }

            $new = $callback((int) $current, $value);

            $connection->table($this->table)->where('key', $prefixed)->update([
                'value' => $this->serialize($new),
            ]);

            return $new;
        });
    }

    /**
     * Get a query builder for the cache table.
     */
    protected function table(): Builder
    {
        return $this->connection()->table($this->table);
    }

    /**
     * Serialize the given value.
     */
    protected function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * Unserialize the given value.
     */
    protected function unserialize(string $value): mixed
    {
        return unserialize($value);
    }
}
