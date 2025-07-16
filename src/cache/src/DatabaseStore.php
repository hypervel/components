<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hyperf\DbConnection\Connection;
use Hyperf\Support\Traits\InteractsWithTime;
use Hypervel\Cache\Contracts\LockProvider;
use Hypervel\Cache\Contracts\Store;

class DatabaseStore implements Store, LockProvider
{
    use InteractsWithTime;
    use RetrievesMultipleKeys;

    /**
     * The database connection instance.
     */
    protected Connection $connection;

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
        Connection $connection,
        string $table,
        string $prefix = '',
        string $lockTable = 'cache_locks',
        array $lockLottery = [2, 100],
        int $defaultLockTimeoutInSeconds = 86400
    ) {
        $this->table = $table;
        $this->prefix = $prefix;
        $this->connection = $connection;
        $this->lockTable = $lockTable;
        $this->lockLottery = $lockLottery;
        $this->defaultLockTimeoutInSeconds = $defaultLockTimeoutInSeconds;
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        $prefixed = $this->prefix . $key;

        $cache = $this->table()
            ->where('key', '=', $prefixed)
            ->first();

        if (is_null($cache)) {
            return null;
        }

        $cache = is_array($cache) ? (object) $cache : $cache;

        if ($this->currentTime() >= $cache->expiration) {
            $this->forget($key);

            return null;
        }

        return $this->unserialize($cache->value);
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        $key = $this->prefix . $key;
        $value = $this->serialize($value);
        $expiration = $this->availableAt($seconds);

        try {
            return $this->table()->insertOrUpdate(
                compact('key', 'value', 'expiration'),
                ['key']
            ) > 0;
        } catch (\Throwable) {
            return $this->table()->updateOrInsert(
                compact('key'),
                compact('value', 'expiration')
            ) > 0;
        }
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
        $expiration = $this->availableAt($seconds);

        try {
            return $this->table()->insert(compact('key', 'value', 'expiration'));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->incrementOrDecrement($key, $value, function ($current, $value) {
            return $current + $value;
        });
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): int|bool
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
            $this->connection,
            $this->lockTable,
            $this->prefix . $name,
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
    protected function incrementOrDecrement(string $key, int $value, Closure $callback): int|bool
    {
        return $this->connection->transaction(function () use ($key, $value, $callback) {
            $prefixed = $this->prefix . $key;

            $cache = $this->table()->where('key', $prefixed)
                ->lockForUpdate()->first();

            if (is_null($cache)) {
                return false;
            }

            $cache = is_array($cache) ? (object) $cache : $cache;

            $current = $this->unserialize($cache->value);

            if (! is_numeric($current)) {
                return false;
            }

            $new = $callback((int) $current, $value);

            $this->table()->where('key', $prefixed)->update([
                'value' => $this->serialize($new),
            ]);

            return $new;
        });
    }

    /**
     * Get a query builder for the cache table.
     */
    protected function table()
    {
        return $this->connection->table($this->table);
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