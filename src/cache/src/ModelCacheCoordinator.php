<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hypervel\Cache\Exceptions\UnsupportedModelCacheStoreException;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\RefreshableLock;
use Hypervel\Contracts\Cache\Repository as CacheRepository;

/**
 * Coordinate shared model cache fills and exact invalidations.
 */
class ModelCacheCoordinator
{
    /**
     * The duration of a model cache fill lock.
     */
    private const int FILL_LOCK_SECONDS = 10;

    /**
     * The maximum time an invalidation waits for a fill lock.
     */
    private const int INVALIDATION_WAIT_SECONDS = 11;

    /**
     * The delay between invalidation lock acquisition attempts.
     */
    private const int INVALIDATION_RETRY_MILLISECONDS = 25;

    /**
     * The envelope marker key.
     */
    private const string ENVELOPE_MARKER_KEY = '__hypervel_model_cache';

    /**
     * The envelope marker value.
     */
    private const string ENVELOPE_MARKER_VALUE = 'present';

    /**
     * The envelope value key.
     */
    private const string ENVELOPE_VALUE_KEY = 'value';

    /**
     * Retrieve or fill a shared model cache entry.
     *
     * @param Closure(): mixed $read
     * @param null|(Closure(): CacheRepository) $writeCache
     */
    public function fill(
        CacheRepository $cache,
        string $key,
        int $ttl,
        Closure $read,
        bool $cacheNull = true,
        ?Closure $writeCache = null,
    ): mixed {
        $cached = $cache->get($key);

        if ($this->isEnvelope($cached)) {
            return $cached[self::ENVELOPE_VALUE_KEY];
        }

        $lock = $this->lock($cache, $key);
        $acquired = false;
        $result = $lock
            ->get(function () use ($cache, $key, $ttl, $read, $cacheNull, $writeCache, $lock, &$acquired): mixed {
                $acquired = true;
                $cached = $cache->get($key);

                if ($this->isEnvelope($cached)) {
                    return $cached[self::ENVELOPE_VALUE_KEY];
                }

                $value = $read();

                if ($value === null && ! $cacheNull) {
                    return null;
                }

                // A source read may outlive the lease. Only publish after atomically
                // confirming ownership and re-arming the lock for the cache write.
                if (! $lock->refresh()) {
                    return $value;
                }

                ($writeCache === null ? $cache : $writeCache())
                    ->put($key, $this->envelope($value), $ttl);

                return $value;
            });

        return $acquired ? $result : $read();
    }

    /**
     * Invalidate an exact shared model cache entry.
     */
    public function invalidate(CacheRepository $cache, string $key): bool
    {
        return (bool) $this->lock($cache, $key)
            ->betweenBlockedAttemptsSleepFor(self::INVALIDATION_RETRY_MILLISECONDS)
            ->block(
                self::INVALIDATION_WAIT_SECONDS,
                fn (): bool => $cache->forget($key),
            );
    }

    /**
     * Create a cache presence envelope.
     *
     * @return array{__hypervel_model_cache: 'present', value: mixed}
     */
    private function envelope(mixed $value): array
    {
        return [
            self::ENVELOPE_MARKER_KEY => self::ENVELOPE_MARKER_VALUE,
            self::ENVELOPE_VALUE_KEY => $value,
        ];
    }

    /**
     * Determine whether the value is a cache presence envelope.
     */
    private function isEnvelope(mixed $value): bool
    {
        return is_array($value)
            && count($value) === 2
            && ($value[self::ENVELOPE_MARKER_KEY] ?? null) === self::ENVELOPE_MARKER_VALUE
            && array_key_exists(self::ENVELOPE_VALUE_KEY, $value);
    }

    /**
     * Get a refreshable model cache lock.
     *
     * @throws UnsupportedModelCacheStoreException
     */
    private function lock(CacheRepository $cache, string $key): RefreshableLock
    {
        $store = $cache->getStore();
        $validatedStore = $store instanceof MemoizedStore
            ? $store->getInnerStore()
            : $store;

        if ($validatedStore instanceof StackStore || $validatedStore instanceof FailoverStore) {
            throw new UnsupportedModelCacheStoreException(sprintf(
                'Model caching does not support cache store [%s] because stack and failover stores cannot guarantee that cached values and locks use the same backend.',
                $validatedStore::class,
            ));
        }

        if (! $validatedStore instanceof LockProvider) {
            throw new UnsupportedModelCacheStoreException(sprintf(
                'Model caching does not support cache store [%s] because it does not provide atomic locks.',
                $validatedStore::class,
            ));
        }

        $lock = $validatedStore->lock($this->lockKey($key), self::FILL_LOCK_SECONDS);

        if (! $lock instanceof RefreshableLock) {
            throw new UnsupportedModelCacheStoreException(sprintf(
                'Model caching does not support cache store [%s] because it does not provide refreshable atomic locks.',
                $validatedStore::class,
            ));
        }

        return $lock;
    }

    /**
     * Build a bounded lock key that cannot collide with the cached value.
     */
    private function lockKey(string $key): string
    {
        return 'model-cache:lock:' . hash('xxh128', $key);
    }
}
