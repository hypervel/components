<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\InteractsWithTime;
use Hypervel\Support\Str;
use RuntimeException;

class DatabaseStore implements CanFlushLocks, LockProvider, Store
{
    use InteractsWithTime;

    /**
     * The database connection resolver.
     */
    protected ConnectionResolverInterface $resolver;

    /**
     * The connection name.
     */
    protected ?string $connectionName;

    /**
     * The lock connection name.
     */
    protected ?string $lockConnectionName = null;

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
     * The classes that should be allowed during unserialization.
     */
    protected array|bool|null $serializableClasses;

    /**
     * Create a new database store.
     */
    public function __construct(
        ConnectionResolverInterface $resolver,
        ?string $connectionName,
        string $table,
        string $prefix = '',
        string $lockTable = 'cache_locks',
        array $lockLottery = [2, 100],
        int $defaultLockTimeoutInSeconds = 86400,
        array|bool|null $serializableClasses = null,
    ) {
        $this->resolver = $resolver;
        $this->connectionName = $connectionName;
        $this->table = $table;
        $this->prefix = $prefix;
        $this->lockTable = $lockTable;
        $this->lockLottery = $lockLottery;
        $this->defaultLockTimeoutInSeconds = $defaultLockTimeoutInSeconds;
        $this->serializableClasses = $serializableClasses;
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
            $this->forgetManyIfExpired($expired->pluck('key')->all(), prefixed: true);
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

        $expiration = $this->getTime() + $seconds;

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
        $expiration = $this->getTime() + $seconds;

        return $this->table()->insertOrIgnore(compact('key', 'value', 'expiration')) > 0;
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

            // If there is no value in the cache, we will return false here. Otherwise the
            // value will be decrypted and we will proceed with this function to either
            // increment or decrement this value based on the given action callbacks.
            if (is_null($cache)) {
                return false;
            }

            $current = $this->unserialize($cache->value);

            // Here we'll call this callback function that was given to the function which
            // is used to either increment or decrement the function. We use a callback
            // so we do not have to recreate all this logic in each of the functions.
            $new = $callback((int) $current, $value);

            if (! is_numeric($current)) {
                return false;
            }

            // Here we will update the values in the table. We will also encrypt the value
            // since database cache values are encrypted by default with secure storage
            // that can't be easily read. We will return the new value after storing.
            $connection->table($this->table)->where('key', $prefixed)->update([
                'value' => $this->serialize($new),
            ]);

            return $new;
        });
    }

    /**
     * Get the current system time.
     */
    protected function getTime(): int
    {
        return $this->currentTime();
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
            $this->lockConnectionName ?? $this->connectionName,
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
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        return (bool) $this->table()
            ->where('key', '=', $this->getPrefix() . $key)
            ->where('expiration', '>', $now = $this->getTime())
            ->update(['expiration' => $now + $seconds]);
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        return $this->forgetMany([$key]);
    }

    /**
     * Remove an item from the cache if it is expired.
     */
    public function forgetIfExpired(string $key): bool
    {
        return $this->forgetManyIfExpired([$key]);
    }

    /**
     * Remove multiple items from the cache.
     */
    protected function forgetMany(array $keys): bool
    {
        $this->table()->whereIn('key', (new Collection($keys))->flatMap(fn ($key) => [
            $this->prefix . $key,
            "{$this->prefix}hypervel:cache:flexible:created:{$key}",
        ])->all())->delete();

        return true;
    }

    /**
     * Remove all expired items from the given set from the cache.
     */
    protected function forgetManyIfExpired(array $keys, bool $prefixed = false): bool
    {
        $this->table()
            ->whereIn('key', (new Collection($keys))->flatMap(fn ($key) => $prefixed ? [
                $key,
                $this->prefix . 'hypervel:cache:flexible:created:' . Str::chopStart($key, $this->prefix),
            ] : [
                "{$this->prefix}{$key}",
                "{$this->prefix}hypervel:cache:flexible:created:{$key}",
            ])->all())
            ->where('expiration', '<=', $this->getTime())
            ->delete();

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
     * Remove all locks from the store.
     *
     * @throws RuntimeException
     */
    public function flushLocks(): bool
    {
        if (! $this->hasSeparateLockStore()) {
            throw new RuntimeException('Flushing locks is only supported when the lock store is separate from the cache store.');
        }

        $this->lockTable()->delete();

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
     * Get a query builder for the cache table.
     */
    protected function table(): Builder
    {
        return $this->connection()->table($this->table);
    }

    /**
     * Get a query builder for the cache locks table.
     */
    protected function lockTable(): Builder
    {
        return $this->getLockConnection()->table($this->lockTable);
    }

    /**
     * Get the underlying database connection.
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->connection();
    }

    /**
     * Set the connection name for the cache store.
     */
    public function setConnection(string $connectionName): static
    {
        $this->connectionName = $connectionName;

        return $this;
    }

    /**
     * Get the connection used to manage locks.
     */
    public function getLockConnection(): ConnectionInterface
    {
        return $this->resolver->connection($this->lockConnectionName ?? $this->connectionName);
    }

    /**
     * Specify the connection that should be used to manage locks.
     */
    public function setLockConnection(string $connectionName): static
    {
        $this->lockConnectionName = $connectionName;

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
     * Set the cache key prefix.
     */
    public function setPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    /**
     * Serialize the given value.
     */
    protected function serialize(mixed $value): string
    {
        $result = serialize($value);

        $connection = $this->connection();

        if (($connection instanceof PostgresConnection || $connection instanceof SQLiteConnection)
            && str_contains($result, "\0")) {
            $result = base64_encode($result);
        }

        return $result;
    }

    /**
     * Unserialize the given value.
     */
    protected function unserialize(string $value): mixed
    {
        $connection = $this->connection();

        if (($connection instanceof PostgresConnection || $connection instanceof SQLiteConnection)
            && ! Str::contains($value, [':', ';'])) {
            $value = base64_decode($value);
        }

        if ($this->serializableClasses !== null) {
            return unserialize($value, ['allowed_classes' => $this->serializableClasses]);
        }

        return unserialize($value);
    }

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        return $this->lockTable !== $this->table;
    }
}
