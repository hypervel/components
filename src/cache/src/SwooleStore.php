<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Carbon\Carbon;
use Closure;
use Hypervel\Cache\Contracts\Store;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Swoole\Table;

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
    public function __construct(
        protected Table $table,
        protected float $memoryLimitBuffer,
        protected string $evictionPolicy,
        protected float $evictionProportion
    ) {
    }

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

        $result = $this->table->set($key, [
            'value' => serialize($value),
            'expiration' => $now + $seconds,
        ]);

        $this->evictRecords();

        return $result;
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
     * Store an item in the cache if the key doesn't exist.
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        if ($this->table->exists($key)) {
            return false;
        }

        return $this->put($key, $value, $seconds);
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        $record = $this->getRecord($key);

        if ($this->recordIsFalseOrExpired($record)) {
            return tap($value, fn ($value) => $this->forever($key, $value));
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
     * Evict records.
     */
    public function evictRecords(): void
    {
        $this->flushStaleRecords();

        while ($this->memoryLimitIsReached()) {
            $this->removeRecordsByEvictionPolicy();
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
    protected function memoryLimitIsReached(): bool
    {
        $stats = $this->table->stats();
        $conflictRate = 1 - ($stats['available_slice_num'] / $stats['total_slice_num']);
        $memoryUsage = $stats['num'] / $this->table->getSize();
        $allowedMemoryUsage = 1 - $this->memoryLimitBuffer;

        return $conflictRate > $allowedMemoryUsage || $memoryUsage > $allowedMemoryUsage;
    }

    protected function removeRecordsByEvictionPolicy()
    {
        if ($this->evictionPolicy === static::EVICTION_POLICY_NOEVICTION) {
            return;
        }

        if ($this->evictionPolicy === static::EVICTION_POLICY_LRU) {
            return $this->removeRecordsByLRU();
        }

        if ($this->evictionPolicy === static::EVICTION_POLICY_LFU) {
            return $this->removeRecordsByLFU();
        }

        if ($this->evictionPolicy === static::EVICTION_POLICY_TTL) {
            return $this->removeRecordsByTTL();
        }

        throw new InvalidArgumentException("Eviction policy [{$this->evictionPolicy}] is not supported.");
    }

    protected function removeRecordsByLRU(): void
    {
        $this->handleRecordsEviction('last_used_at');
    }

    protected function removeRecordsByLFU(): void
    {
        $this->handleRecordsEviction('used_count');
    }

    protected function removeRecordsByTTL(): void
    {
        $this->handleRecordsEviction('expiration');
    }

    protected function handleRecordsEviction(string $column): void
    {
        $quantity = (int) round($this->table->getSize() * $this->evictionProportion);

        $heap = new class($quantity) extends LimitedMaxHeap {
            protected function compare($left, $right): int
            {
                return $left['value'] <=> $right['value'];
            }
        };

        foreach ($this->table as $key => $record) {
            $value = $record[$column];

            $heap->insert(compact('key', 'value'));
        }

        while (! $heap->isEmpty()) {
            $this->forget($heap->extract()['key']);
        }
    }

    protected function flushStaleRecords(): void
    {
        $now = $this->getCurrentTimestamp();

        $keys = [];

        foreach ($this->table as $key => $row) {
            if ($row['expiration'] < $now) {
                $keys[] = $key;
            }
        }

        foreach ($keys as $key) {
            $this->forget($key);
        }
    }
}
