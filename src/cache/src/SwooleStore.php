<?php

declare(strict_types=1);

namespace SwooleTW\Hyperf\Cache;

use Carbon\Carbon;
use Closure;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Swoole\Table;
use SwooleTW\Hyperf\Cache\Contracts\Store;

class SwooleStore implements Store
{
    public const EVICTION_POLICY_LRU = 'lru';

    public const EVICTION_POLICY_LFU = 'lfu';

    public const EVICTION_POLICY_TTL = 'ttl';

    public const EVICTION_POLICY_NOEVICTION = 'noeviction';

    protected const ONE_YEAR = 31536000;

    /**
     * All of the registered interval caches.
     */
    protected array $intervals = [];

    /**
     * Create a new Swoole store.
     */
    public function __construct(protected Table $table) {}

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        $record = $this->getRecord($key);

        if (! $this->recordIsFalseOrExpired($record)) {
            return unserialize($record['value']);
        }

        if (in_array($key, $this->intervals)
            && ! is_null($interval = $this->getInterval($key))) {
            return $interval['resolver']();
        }

        $this->forget($key);

        return null;
    }

    /**
     * Retrieve an interval item from the cache.
     */
    protected function getInterval(string $key): ?array
    {
        $interval = $this->get('interval-' . $key);

        return $interval ? unserialize($interval) : null;
    }

    /**
     * Retrieve multiple items from the cache by key.
     * Items not found in the cache will have a null value.
     */
    public function many(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($key) => [$key => $this->get($key)])->all();
    }

    /**
     * Store an item in the cache for a given number of seconds.
     */
    public function put(string $key, mixed $value, int $seconds): bool
    {
        $now = $this->getCurrentTimestamp();

        return $this->table->set($key, [
            'value' => serialize($value),
            'expiration' => $now + $seconds,
        ]);
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, int $seconds): bool
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $seconds);
        }

        return true;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        $record = $this->getRecord($key);

        if ($this->recordIsFalseOrExpired($record)) {
            return tap($value, fn ($value) => $this->put($key, $value, static::ONE_YEAR));
        }

        return tap((int) (unserialize($record['value']) + $value), function ($value) use ($key, $record) {
            $this->table->set($key, [
                'value' => serialize($value),
                'expiration' => $record['expiration'],
            ]);
        });
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, static::ONE_YEAR);
    }

    /**
     * Register a cache key that should be refreshed at a given interval (in minutes).
     */
    public function interval(string $key, Closure $resolver, int $seconds): void
    {
        if (! is_null($this->getInterval($key))) {
            $this->intervals[] = $key;

            return;
        }

        $this->forever('interval-' . $key, serialize([
            'resolver' => new SerializableClosure($resolver),
            'lastRefreshedAt' => null,
            'refreshInterval' => $seconds,
        ]));

        $this->intervals[] = $key;
    }

    /**
     * Refresh all of the applicable interval caches.
     */
    public function refreshIntervalCaches(): void
    {
        foreach ($this->intervals as $key) {
            if (! $this->intervalShouldBeRefreshed($interval = $this->getInterval($key))) {
                continue;
            }

            $this->forever('interval-' . $key, serialize(array_merge(
                $interval,
                ['lastRefreshedAt' => Carbon::now()->getTimestamp()],
            )));

            $this->forever($key, $interval['resolver']());
        }
    }

    /**
     * Determine if the given interval record should be refreshed.
     */
    protected function intervalShouldBeRefreshed(array $interval): bool
    {
        return is_null($interval['lastRefreshedAt'])
               || (Carbon::now()->getTimestamp() - $interval['lastRefreshedAt']) >= $interval['refreshInterval'];
    }

    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        return $this->table->del($key);
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        foreach ($this->table as $key => $record) {
            if (str_starts_with($key, 'interval-')) {
                continue;
            }

            $this->forget($key);
        }

        return true;
    }

    /**
     * Determine if the record is missing or expired.
     */
    protected function recordIsFalseOrExpired(array|false $record): bool
    {
        return $record === false || $record['expiration'] <= $this->getCurrentTimestamp();
    }

    /**
     * Get the cache key prefix.
     */
    public function getPrefix(): string
    {
        return '';
    }

    /**
     * Evict records when memory limit is reached.
     */
    public function evictRecordsWhenMemoryLimitIsReached(
        float $memoryLimitBuffer,
        string $evictionPolicy,
        float $evictionProportion
    ): void {
        while ($this->memoryLimitIsReached($memoryLimitBuffer)) {
            $this->evictRecords($evictionPolicy, $evictionProportion);
        }
    }

    /**
     * Retrieve an record from the table and write used info by key.
     */
    protected function getRecord(string $key): array|false
    {
        $record = $this->table->get($key);

        if (! $record) {
            return false;
        }

        $record['last_used_at'] = $this->getCurrentTimestamp();
        $record['used_count'] = ($record['used_count'] ?? 0) + 1;

        $this->table->set($key, $record);

        return $record;
    }

    /**
     * Get the current UNIX timestamp, with microsecond.
     */
    protected function getCurrentTimestamp(): float
    {
        return Carbon::now()->getPreciseTimestamp(6) / 1000000;
    }

    /**
     * Determine if the memory limit is reached.
     */
    protected function memoryLimitIsReached(float $memoryLimitBuffer): bool
    {
        $stats = $this->table->stats();
        $conflictRate = 1 - ($stats['available_slice_num'] / $stats['total_slice_num']);
        $memoryUsage = $this->table->stats()['num'] / $this->table->getSize();
        $allowedMemoryUsage = 1 - $memoryLimitBuffer;

        return $conflictRate > $allowedMemoryUsage || $memoryUsage > $allowedMemoryUsage;
    }

    /**
     * Evict records.
     */
    protected function evictRecords(string $policy, float $proportion)
    {
        if ($policy === static::EVICTION_POLICY_NOEVICTION) {
            return;
        }

        if ($policy === static::EVICTION_POLICY_LRU) {
            return $this->evictRecordsByLRU($proportion);
        }

        if ($policy === static::EVICTION_POLICY_LFU) {
            return $this->evictRecordsByLFU($proportion);
        }

        if ($policy === static::EVICTION_POLICY_TTL) {
            return $this->evictRecordsByTTL($proportion);
        }

        throw new InvalidArgumentException("Eviction policy [{$policy}] is not supported.");
    }

    protected function evictRecordsByLRU(float $proportion): void
    {
        $this->handleRecordsEviction($proportion, 'last_used_at');
    }

    protected function evictRecordsByLFU(float $proportion): void
    {
        $this->handleRecordsEviction($proportion, 'used_count');
    }

    protected function evictRecordsByTTL(float $proportion): void
    {
        $this->handleRecordsEviction($proportion, 'expiration');
    }

    protected function handleRecordsEviction(float $proportion, string $column): void
    {
        $quantity = (int) round($this->table->getSize() * $proportion);

        collect($this->table)
            ->map(fn ($record) => $record[$column])
            ->sort()
            ->take($quantity)
            ->each(fn ($_, $key) => $this->forget($key));
    }
}
