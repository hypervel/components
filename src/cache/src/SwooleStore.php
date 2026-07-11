<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\CanFlushLocks;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Support\Carbon;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Throwable;

class SwooleStore implements CanFlushLocks, LockProvider, Store
{
    public const EVICTION_POLICY_LRU = 'lru';

    public const EVICTION_POLICY_LFU = 'lfu';

    public const EVICTION_POLICY_TTL = 'ttl';

    public const EVICTION_POLICY_NOEVICTION = 'noeviction';

    protected const ONE_YEAR = 31536000;

    protected const USER_PREFIX = 'u:';

    protected const INTERVAL_PREFIX = 'i:';

    protected const INTERVAL_INDEX_PREFIX = 'x:';

    protected const INTERVAL_INDEX_SHARDS = 64;

    /*
     * This timeout must stay comfortably above normal resolver runtimes. If a
     * worker crashes after claiming an interval, another process can reclaim it
     * after this window instead of freezing refreshes until restart.
     */
    protected const INTERVAL_REFRESH_CLAIM_TIMEOUT = 300.0;

    protected const LOCK_PREFIX = 'l:';

    protected SwooleTable $table;

    /**
     * Locally registered interval cache keys.
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

        if ($this->hasLocalInterval($key)) {
            $value = $this->refreshIntervalCache($this->intervalKey($key), force: true, rethrow: true);

            if ($value !== null) {
                return $value;
            }

            // A resolver may have stored a live null value; the locked stale-row recheck below decides whether to delete.
        }

        if ($record !== false) {
            $this->forgetExpiredRecord($tableKey);
        }

        return null;
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
        $result = true;

        foreach ($values as $key => $value) {
            $result = $this->put((string) $key, $value, $seconds) && $result;
        }

        return $result;
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

            $incremented = (int) (unserialize($record['value'], ['allowed_classes' => false]) + $value);

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
        $metadataKey = $this->intervalKey($key);
        $metadata = [
            'key' => $key,
            'metadataKey' => $metadataKey,
            'resolver' => serialize(new SerializableClosure($resolver)),
            'lastRefreshedAt' => null,
            'refreshingAt' => null,
            'refreshInterval' => $seconds,
        ];

        $metadataWritten = $this->state->withRowLock(
            $metadataKey,
            function () use ($metadataKey, $metadata): bool {
                $existing = $this->getIntervalMetadataByInternalKey($metadataKey);

                if ($existing !== null) {
                    $metadata['lastRefreshedAt'] = $existing['lastRefreshedAt'];
                    $metadata['refreshingAt'] = $existing['refreshingAt'];
                }

                return $this->putIntervalMetadataByInternalKey($metadataKey, $metadata);
            },
        );

        if (! $metadataWritten) {
            throw new RuntimeException("Unable to register Swoole interval cache [{$key}].");
        }

        try {
            $this->registerIntervalIndex($metadataKey);
        } catch (Throwable $e) {
            $this->state->withRowLock($metadataKey, fn (): bool => $this->rawForget($metadataKey));

            throw $e;
        }

        $this->registerLocalInterval($key);
    }

    /**
     * Refresh all of the applicable interval caches.
     */
    public function refreshIntervalCaches(): void
    {
        foreach ($this->registeredIntervalMetadataKeys() as $metadataKey) {
            $this->refreshIntervalCache($metadataKey);
        }
    }

    /**
     * Determine if the given interval record should be refreshed.
     */
    protected function intervalShouldBeRefreshed(array $metadata, float $now): bool
    {
        return is_null($metadata['lastRefreshedAt'])
               || ($now - $metadata['lastRefreshedAt']) >= $metadata['refreshInterval'];
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
     * Determine if the store can currently flush locks.
     */
    public function supportsFlushingLocks(): bool
    {
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
        return Carbon::hasTestNow()
            ? Carbon::now()->getPreciseTimestamp(6) / 1000000
            : microtime(true);
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

            $heap->insert([
                'key' => $key,
                'value' => $record[$column],
                'fingerprint' => $this->evictionFingerprint($record),
            ]);
        }

        $deleted = 0;

        while (! $heap->isEmpty()) {
            $candidate = $heap->extract();

            if ($this->forgetEvictionCandidate($candidate['key'], $candidate['fingerprint'])) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Get the compact eviction fingerprint for a raw table record.
     *
     * @return array{value_hash: string, expiration: float, last_used_at: float, used_count: int}
     */
    protected function evictionFingerprint(array $record): array
    {
        return [
            'value_hash' => hash('xxh128', $record['value']),
            'expiration' => $record['expiration'],
            'last_used_at' => $record['last_used_at'],
            'used_count' => $record['used_count'],
        ];
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
        // Hit metadata stays lock-free for the read hot path. If a concurrent delete wins,
        // Swoole can create an expired shell row that stale cleanup later prunes.
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
    protected function forgetEvictionCandidate(string $key, array $fingerprint): bool
    {
        return $this->state->withRowLock($key, function () use ($key, $fingerprint): bool {
            $record = $this->rawGet($key);

            if ($record === false || $this->evictionFingerprint($record) !== $fingerprint) {
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
     * Register a local interval key.
     */
    protected function registerLocalInterval(string $key): void
    {
        $this->intervals[$key] = true;
    }

    /**
     * Register an interval metadata key in the shared index.
     */
    protected function registerIntervalIndex(string $metadataKey): void
    {
        $indexKey = $this->intervalIndexKey($metadataKey);

        $result = $this->state->withRowLock($indexKey, function () use ($indexKey, $metadataKey): bool {
            $record = $this->rawGet($indexKey);
            $index = $this->recordIsFalseOrExpired($record) ? [] : unserialize($record['value']);

            $index[$metadataKey] = true;

            return $this->rawPutSerialized($indexKey, serialize($index), $this->expiration(static::ONE_YEAR));
        });

        if (! $result) {
            throw new RuntimeException("Unable to register Swoole interval index row [{$indexKey}].");
        }
    }

    /**
     * Get registered interval metadata keys.
     */
    protected function registeredIntervalMetadataKeys(): array
    {
        $metadataKeys = [];

        for ($i = 0; $i < static::INTERVAL_INDEX_SHARDS; ++$i) {
            $indexKey = static::INTERVAL_INDEX_PREFIX . $i;
            $record = $this->rawGet($indexKey);

            if ($this->recordIsFalseOrExpired($record)) {
                continue;
            }

            foreach (array_keys(unserialize($record['value'])) as $metadataKey) {
                $metadataKeys[$metadataKey] = true;
            }

            $this->touchInternalRow($indexKey);
        }

        return array_keys($metadataKeys);
    }

    /**
     * Refresh a single interval cache.
     */
    protected function refreshIntervalCache(string $metadataKey, bool $force = false, bool $rethrow = false): mixed
    {
        $claimedAt = null;

        try {
            $now = $this->getCurrentTimestamp();

            $claim = $this->state->withRowLock($metadataKey, function () use ($metadataKey, $now, $force): ?array {
                $metadata = $this->getIntervalMetadataByInternalKey($metadataKey);

                if ($metadata === null) {
                    return null;
                }

                if (! $force && ! $this->intervalShouldBeRefreshed($metadata, $now)) {
                    return null;
                }

                if ($metadata['refreshingAt'] !== null
                    && ! $this->intervalClaimIsStale($metadata['refreshingAt'], $now, $metadata['refreshInterval'])) {
                    return null;
                }

                $metadata['refreshingAt'] = $now;

                if (! $this->putIntervalMetadataByInternalKey($metadataKey, $metadata)) {
                    throw new RuntimeException("Unable to claim Swoole interval cache [{$metadata['key']}].");
                }

                return [$metadata, $now];
            });

            if ($claim === null) {
                return null;
            }

            [$metadata, $claimedAt] = $claim;

            /** @var SerializableClosure $resolver */
            $resolver = unserialize($metadata['resolver']);
            $value = $resolver();

            $commit = $this->prepareIntervalRefreshCommit($metadataKey, $claimedAt);

            if ($commit === null) {
                // Another refresher owns this interval now; do not write or clear its claim.
                return null;
            }

            [$metadata, $claimedAt] = $commit;

            // Keep the public write outside the metadata lock: it can serialize user values,
            // lock the user row, and trigger eviction, while the claim timeout floor remains much larger.
            if (! $this->forever($metadata['key'], $value)) {
                throw new RuntimeException("Unable to refresh Swoole interval cache [{$metadata['key']}].");
            }

            $this->completeIntervalRefresh($metadataKey, $claimedAt);

            return $value;
        } catch (Throwable $e) {
            if ($claimedAt !== null) {
                $this->clearIntervalClaim($metadataKey, $claimedAt);
            }

            if ($rethrow) {
                throw $e;
            }

            $this->reportIntervalException($e);

            return null;
        }
    }

    /**
     * Prepare a claimed interval refresh for public value commit.
     *
     * @return null|array{0: array, 1: float}
     */
    protected function prepareIntervalRefreshCommit(string $metadataKey, float $claimedAt): ?array
    {
        return $this->state->withRowLock($metadataKey, function () use ($metadataKey, $claimedAt): ?array {
            $metadata = $this->getIntervalMetadataByInternalKey($metadataKey);

            if ($metadata === null || $metadata['refreshingAt'] !== $claimedAt) {
                return null;
            }

            $commitClaimedAt = $this->getCurrentTimestamp();
            // Restamp ownership so failed public writes clear only this refresher's current claim.
            $metadata['refreshingAt'] = $commitClaimedAt;

            if (! $this->putIntervalMetadataByInternalKey($metadataKey, $metadata)) {
                throw new RuntimeException("Unable to prepare Swoole interval cache refresh [{$metadata['key']}].");
            }

            return [$metadata, $commitClaimedAt];
        });
    }

    /**
     * Complete an interval refresh.
     */
    protected function completeIntervalRefresh(string $metadataKey, float $claimedAt): void
    {
        $this->state->withRowLock($metadataKey, function () use ($metadataKey, $claimedAt): void {
            $metadata = $this->getIntervalMetadataByInternalKey($metadataKey);

            if ($metadata === null || $metadata['refreshingAt'] !== $claimedAt) {
                return;
            }

            $metadata['lastRefreshedAt'] = $claimedAt;
            $metadata['refreshingAt'] = null;

            if (! $this->putIntervalMetadataByInternalKey($metadataKey, $metadata)) {
                throw new RuntimeException("Unable to complete Swoole interval cache refresh [{$metadata['key']}].");
            }
        });
    }

    /**
     * Clear an interval refresh claim.
     */
    protected function clearIntervalClaim(string $metadataKey, float $claimedAt): void
    {
        $this->state->withRowLock($metadataKey, function () use ($metadataKey, $claimedAt): void {
            $metadata = $this->getIntervalMetadataByInternalKey($metadataKey);

            if ($metadata === null || $metadata['refreshingAt'] !== $claimedAt) {
                return;
            }

            $metadata['refreshingAt'] = null;

            $this->putIntervalMetadataByInternalKey($metadataKey, $metadata);
        });
    }

    /**
     * Determine if an interval refresh claim is stale.
     */
    protected function intervalClaimIsStale(float $refreshingAt, float $now, int $refreshInterval): bool
    {
        $timeout = max(static::INTERVAL_REFRESH_CLAIM_TIMEOUT, $refreshInterval * 2);

        return ($now - $refreshingAt) >= $timeout;
    }

    /**
     * Get interval metadata by internal key.
     */
    protected function getIntervalMetadataByInternalKey(string $metadataKey): ?array
    {
        $record = $this->rawGet($metadataKey);

        return $this->recordIsFalseOrExpired($record)
            ? null
            : unserialize($record['value']);
    }

    /**
     * Store interval metadata by internal key.
     */
    protected function putIntervalMetadataByInternalKey(string $metadataKey, array $metadata): bool
    {
        return $this->rawPutSerialized($metadataKey, serialize($metadata), $this->expiration(static::ONE_YEAR));
    }

    /**
     * Touch an internal row.
     */
    protected function touchInternalRow(string $key): void
    {
        $this->state->withRowLock($key, function () use ($key): void {
            if ($this->rawGet($key) !== false) {
                $this->table->set($key, ['expiration' => $this->expiration(static::ONE_YEAR)]);
            }
        });
    }

    /**
     * Report an interval refresh exception.
     */
    protected function reportIntervalException(Throwable $e): void
    {
        $container = Container::getInstance();

        if ($container->bound(ExceptionHandler::class)) {
            $container->make(ExceptionHandler::class)->report($e);

            return;
        }

        file_put_contents('php://stderr', (string) $e . PHP_EOL);
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
     * Get the interval index shard key.
     */
    protected function intervalIndexKey(string $metadataKey): string
    {
        return static::INTERVAL_INDEX_PREFIX . (crc32($metadataKey) % static::INTERVAL_INDEX_SHARDS);
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
     * Determine if the table key is a control key.
     */
    protected function isControlKey(string $key): bool
    {
        return str_starts_with($key, static::INTERVAL_PREFIX)
            || str_starts_with($key, static::INTERVAL_INDEX_PREFIX)
            || $this->isLockKey($key);
    }

    /**
     * Determine if the table key is a lock key.
     */
    protected function isLockKey(string $key): bool
    {
        return str_starts_with($key, static::LOCK_PREFIX);
    }
}
