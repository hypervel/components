<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Support\Carbon;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;

class SwooleStore implements CanFlushLocks, LockProvider, Store
{
    public const EVICTION_POLICY_LRU = 'lru';

    public const EVICTION_POLICY_LFU = 'lfu';

    public const EVICTION_POLICY_TTL = 'ttl';

    public const EVICTION_POLICY_NOEVICTION = 'noeviction';

    protected const ONE_YEAR = 31536000;

    protected const USER_PREFIX = 'u:';

    protected const INTERVAL_PREFIX = 'i:';

    protected const LOCK_PREFIX = 'l:';

    protected SwooleTable $table;

    /**
     * All of the registered interval caches.
     *
     * @var array<string, true>
     */
    protected array $intervals = [];

    /**
     * Create a new Swoole store.
     */
    public function __construct(
        protected SwooleTableState $state,
        protected float $memoryLimitBuffer,
        protected string $evictionPolicy,
        protected float $evictionProportion
    ) {
        $this->table = $this->state->table();
    }

    /**
     * Retrieve an item from the cache by key.
     */
    public function get(string $key): mixed
    {
        $tableKey = $this->userKey($key);
        $record = $this->rawGet($tableKey);

        if (! $this->recordIsFalseOrExpired($record)) {
            $this->recordHit($tableKey);

            return unserialize($record['value']);
        }

        if ($this->hasLocalInterval($key) && ! is_null($interval = $this->getInterval($key))) {
            return $interval['resolver']();
        }

        if ($record !== false) {
            $this->forgetExpiredRecord($tableKey);
        }

        return null;
    }

    /**
     * Retrieve an interval item from the cache.
     */
    protected function getInterval(string $key): ?array
    {
        $record = $this->rawGet($this->intervalKey($key));

        return $this->recordIsFalseOrExpired($record)
            ? null
            : unserialize($record['value']);
    }

    /**
     * Determine if the key is a local interval.
     */
    protected function hasLocalInterval(string $key): bool
    {
        return isset($this->intervals[$key]);
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
        $tableKey = $this->userKey($key);
        $serialized = serialize($value);
        $expiration = $this->expiration($seconds);

        $result = $this->state->withRowLock(
            $tableKey,
            fn (): bool => $this->rawPutSerialized($tableKey, $serialized, $expiration),
        );

        $this->evictRecordsIfNeeded();

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
        $tableKey = $this->userKey($key);
        $serialized = serialize($value);
        $expiration = $this->expiration($seconds);

        $result = $this->state->withRowLock($tableKey, function () use ($tableKey, $serialized, $expiration): bool {
            $record = $this->rawGet($tableKey);

            if (! $this->recordIsFalseOrExpired($record)) {
                return false;
            }

            return $this->rawPutSerialized($tableKey, $serialized, $expiration);
        });

        if ($result) {
            $this->evictRecordsIfNeeded();
        }

        return $result;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(string $key, int $value = 1): int
    {
        $tableKey = $this->userKey($key);
        $wroteNewRecord = false;

        $result = $this->state->withRowLock($tableKey, function () use ($tableKey, $value, &$wroteNewRecord): int {
            $record = $this->rawGet($tableKey);

            if ($this->recordIsFalseOrExpired($record)) {
                $wroteNewRecord = true;
                $this->rawPutSerialized($tableKey, serialize($value), $this->expiration(static::ONE_YEAR));

                return $value;
            }

            $incremented = (int) (unserialize($record['value']) + $value);

            $this->rawPutSerialized($tableKey, serialize($incremented), $record['expiration']);

            return $incremented;
        });

        if ($wroteNewRecord) {
            $this->evictRecordsIfNeeded();
        }

        return $result;
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
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        $tableKey = $this->userKey($key);

        return $this->state->withRowLock($tableKey, function () use ($tableKey, $seconds): bool {
            $record = $this->rawGet($tableKey);

            if ($this->recordIsFalseOrExpired($record)) {
                if ($record !== false) {
                    $this->rawForget($tableKey);
                }

                return false;
            }

            return $this->table->set($tableKey, [
                'expiration' => $this->expiration($seconds),
            ]);
        });
    }

    /**
     * Register a cache key that should be refreshed at a given interval in seconds.
     */
    public function interval(string $key, Closure $resolver, int $seconds): void
    {
        $intervalKey = $this->intervalKey($key);
        $serialized = serialize([
            'resolver' => new SerializableClosure($resolver),
            'lastRefreshedAt' => null,
            'refreshInterval' => $seconds,
        ]);

        $this->state->withRowLock($intervalKey, function () use ($intervalKey, $serialized): void {
            if (! $this->recordIsFalseOrExpired($this->rawGet($intervalKey))) {
                return;
            }

            $result = $this->rawPutSerialized($intervalKey, $serialized, $this->expiration(static::ONE_YEAR));

            if (! $result) {
                throw new RuntimeException('Unable to register Swoole interval cache.');
            }
        });

        $this->intervals[$key] = true;
    }

    /**
     * Refresh all of the applicable interval caches.
     */
    public function refreshIntervalCaches(): void
    {
        foreach (array_keys($this->intervals) as $key) {
            $interval = $this->getInterval($key);

            if ($interval === null || ! $this->intervalShouldBeRefreshed($interval)) {
                continue;
            }

            $intervalKey = $this->intervalKey($key);

            $serialized = serialize(array_merge(
                $interval,
                ['lastRefreshedAt' => Carbon::now()->getTimestamp()],
            ));

            $this->state->withRowLock($intervalKey, function () use ($intervalKey, $serialized): void {
                $result = $this->rawPutSerialized($intervalKey, $serialized, $this->expiration(static::ONE_YEAR));

                if (! $result) {
                    throw new RuntimeException('Unable to refresh Swoole interval metadata.');
                }
            });

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
        $tableKey = $this->userKey($key);

        return $this->state->withRowLock(
            $tableKey,
            fn (): bool => $this->rawForget($tableKey),
        );
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        return $this->state->withAllRowLocks(function (): bool {
            foreach ($this->table as $tableKey => $record) {
                if ($this->isControlKey($tableKey)) {
                    continue;
                }

                $this->rawForget($tableKey);
            }

            return true;
        });
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

        return $this->state->withAllRowLocks(function (): bool {
            foreach ($this->table as $key => $record) {
                if ($this->isLockKey($key)) {
                    $this->rawForget($key);
                }
            }

            return true;
        });
    }

    /**
     * Get a lock instance.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): SwooleLock
    {
        return new SwooleLock($this, $name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     */
    public function restoreLock(string $name, string $owner): SwooleLock
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        return true;
    }

    /**
     * Attempt to acquire a lock.
     */
    public function acquireLock(string $name, string $owner, int $seconds): bool
    {
        $key = $this->lockKey($name);
        $expiresAt = $seconds > 0 ? $this->expiration($seconds) : null;

        return $this->state->withRowLock($key, function () use ($key, $owner, $expiresAt): bool {
            $lock = $this->rawLockRecord($key);

            if ($lock !== null && ! $this->lockIsExpired($lock)) {
                return false;
            }

            return $this->rawPutSerialized($key, serialize([
                'owner' => $owner,
                'expiresAt' => $expiresAt,
            ]), $this->expiration(static::ONE_YEAR));
        });
    }

    /**
     * Release a lock if it is owned by the given owner.
     */
    public function releaseLock(string $name, string $owner): bool
    {
        $key = $this->lockKey($name);

        return $this->state->withRowLock($key, function () use ($key, $owner): bool {
            $lock = $this->rawLockRecord($key);

            if ($lock === null || $this->lockIsExpired($lock)) {
                if ($lock !== null) {
                    $this->rawForget($key);
                }

                return false;
            }

            if ($lock['owner'] !== $owner) {
                return false;
            }

            return $this->rawForget($key);
        });
    }

    /**
     * Get the current lock owner.
     */
    public function getLockOwner(string $name): ?string
    {
        $lock = $this->rawLockRecord($this->lockKey($name));

        return $lock !== null && ! $this->lockIsExpired($lock)
            ? $lock['owner']
            : null;
    }

    /**
     * Refresh a lock's TTL.
     */
    public function refreshLock(string $name, string $owner, int $seconds): bool
    {
        $key = $this->lockKey($name);

        return $this->state->withRowLock($key, function () use ($key, $owner, $seconds): bool {
            $lock = $this->rawLockRecord($key);

            if ($lock === null || $this->lockIsExpired($lock) || $lock['owner'] !== $owner) {
                return false;
            }

            $lock['expiresAt'] = $this->expiration($seconds);

            return $this->rawPutSerialized($key, serialize($lock), $this->expiration(static::ONE_YEAR));
        });
    }

    /**
     * Get the remaining lock lifetime in seconds.
     */
    public function getLockRemainingLifetime(string $name): ?float
    {
        $lock = $this->rawLockRecord($this->lockKey($name));

        if ($lock === null || $lock['expiresAt'] === null || $this->lockIsExpired($lock)) {
            return null;
        }

        return max(0.0, $lock['expiresAt'] - $this->getCurrentTimestamp());
    }

    /**
     * Force a lock to release.
     */
    public function forceReleaseLock(string $name): void
    {
        $key = $this->lockKey($name);

        $this->state->withRowLock($key, fn () => $this->rawForget($key));
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

        if ($this->evictionPolicy === static::EVICTION_POLICY_NOEVICTION) {
            return;
        }

        while ($this->memoryLimitIsReached()) {
            if ($this->removeRecordsByEvictionPolicy() === 0) {
                return;
            }
        }
    }

    /**
     * Evict records if the table is near its memory limit.
     */
    protected function evictRecordsIfNeeded(): void
    {
        if (! $this->memoryLimitIsReached()) {
            return;
        }

        $this->evictRecords();
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

    /**
     * Remove records by the configured eviction policy.
     */
    protected function removeRecordsByEvictionPolicy(): int
    {
        if ($this->evictionPolicy === static::EVICTION_POLICY_NOEVICTION) {
            return 0;
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

    /**
     * Remove records by least recently used.
     */
    protected function removeRecordsByLRU(): int
    {
        return $this->handleRecordsEviction('last_used_at');
    }

    /**
     * Remove records by least frequently used.
     */
    protected function removeRecordsByLFU(): int
    {
        return $this->handleRecordsEviction('used_count');
    }

    /**
     * Remove records by TTL.
     */
    protected function removeRecordsByTTL(): int
    {
        return $this->handleRecordsEviction('expiration');
    }

    /**
     * Handle records eviction.
     */
    protected function handleRecordsEviction(string $column): int
    {
        $quantity = (int) round($this->table->getSize() * $this->evictionProportion);

        if ($quantity <= 0) {
            return 0;
        }

        $heap = new class($quantity) extends LimitedMaxHeap {
            protected function compare($left, $right): int
            {
                return $left['value'] <=> $right['value'];
            }
        };

        foreach ($this->table as $key => $record) {
            if ($this->isControlKey($key)) {
                continue;
            }

            $value = $record[$column];

            $heap->insert(compact('key', 'record', 'value'));
        }

        $deleted = 0;

        while (! $heap->isEmpty()) {
            $candidate = $heap->extract();

            if ($this->forgetEvictionCandidate($candidate['key'], $candidate['record'])) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Flush stale records.
     */
    protected function flushStaleRecords(): void
    {
        $now = $this->getCurrentTimestamp();
        $tableKeys = [];
        $lockKeys = [];

        foreach ($this->table as $key => $row) {
            if ($this->isLockKey($key)) {
                if ($this->rawLockPayloadIsExpired($row)) {
                    $lockKeys[] = $key;
                }

                continue;
            }

            if ($this->isControlKey($key)) {
                continue;
            }

            if ($row['expiration'] <= $now) {
                $tableKeys[] = $key;
            }
        }

        foreach ($tableKeys as $key) {
            $this->forgetExpiredRecord($key);
        }

        foreach ($lockKeys as $key) {
            $this->forgetExpiredLockRecord($key);
        }
    }

    /**
     * Record a cache hit.
     */
    protected function recordHit(string $key): void
    {
        if ($this->evictionPolicy === static::EVICTION_POLICY_LRU) {
            $this->table->set($key, ['last_used_at' => $this->getCurrentTimestamp()]);

            return;
        }

        if ($this->evictionPolicy === static::EVICTION_POLICY_LFU) {
            $this->table->incr($key, 'used_count', 1);
        }
    }

    /**
     * Forget an expired record by table key.
     */
    protected function forgetExpiredRecord(string $key): void
    {
        $this->state->withRowLock($key, function () use ($key): void {
            $record = $this->rawGet($key);

            if ($this->recordIsFalseOrExpired($record)) {
                $this->rawForget($key);
            }
        });
    }

    /**
     * Forget an eviction candidate by table key.
     */
    protected function forgetEvictionCandidate(string $key, array $candidate): bool
    {
        return $this->state->withRowLock($key, function () use ($key, $candidate): bool {
            if ($this->isControlKey($key) || $this->rawGet($key) !== $candidate) {
                return false;
            }

            return $this->rawForget($key);
        });
    }

    /**
     * Forget an expired lock record.
     */
    protected function forgetExpiredLockRecord(string $key): void
    {
        $this->state->withRowLock($key, function () use ($key): void {
            $record = $this->rawGet($key);

            if ($record !== false && $this->rawLockPayloadIsExpired($record)) {
                $this->rawForget($key);
            }
        });
    }

    /**
     * Get a raw table record.
     */
    protected function rawGet(string $key): array|false
    {
        return $this->table->get($key);
    }

    /**
     * Store a serialized raw table record.
     */
    protected function rawPutSerialized(string $key, string $serialized, float $expiration): bool
    {
        return $this->table->set($key, [
            'value' => $serialized,
            'expiration' => $expiration,
        ]);
    }

    /**
     * Forget a raw table record.
     */
    protected function rawForget(string $key): bool
    {
        return $this->table->del($key);
    }

    /**
     * Get the expiration timestamp for a TTL.
     */
    protected function expiration(int $seconds): float
    {
        return $this->getCurrentTimestamp() + $seconds;
    }

    /**
     * Get a raw lock record.
     *
     * @return null|array{owner: string, expiresAt: ?float}
     */
    protected function rawLockRecord(string $key): ?array
    {
        $record = $this->rawGet($key);

        return $record === false ? null : unserialize($record['value']);
    }

    /**
     * Determine if a lock is expired.
     *
     * @param array{expiresAt: ?float} $lock
     */
    protected function lockIsExpired(array $lock): bool
    {
        return $lock['expiresAt'] !== null && $lock['expiresAt'] <= $this->getCurrentTimestamp();
    }

    /**
     * Determine if a raw lock payload is expired.
     */
    protected function rawLockPayloadIsExpired(array $row): bool
    {
        $lock = unserialize($row['value']);

        return $this->lockIsExpired($lock);
    }

    /**
     * Get the table key for a user cache key.
     */
    protected function userKey(string $key): string
    {
        return $this->hashedTableKey(static::USER_PREFIX, $key);
    }

    /**
     * Get the table key for an interval cache key.
     */
    protected function intervalKey(string $key): string
    {
        return $this->hashedTableKey(static::INTERVAL_PREFIX, $key);
    }

    /**
     * Get the table key for a lock name.
     */
    protected function lockKey(string $name): string
    {
        return $this->hashedTableKey(static::LOCK_PREFIX, $name);
    }

    /**
     * Get a hashed table key.
     */
    protected function hashedTableKey(string $prefix, string $key): string
    {
        return $prefix . hash('xxh128', $key, false, [
            'seed' => $this->state->hashSeed(),
        ]);
    }

    /**
     * Determine if the table key is a user key.
     */
    protected function isUserKey(string $key): bool
    {
        return str_starts_with($key, static::USER_PREFIX);
    }

    /**
     * Determine if the table key is a control key.
     */
    protected function isControlKey(string $key): bool
    {
        return ! $this->isUserKey($key);
    }

    /**
     * Determine if the table key is a lock key.
     */
    protected function isLockKey(string $key): bool
    {
        return str_starts_with($key, static::LOCK_PREFIX);
    }
}
