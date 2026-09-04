<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use Throwable;

class DebounceLock
{
    use ReadsQueueAttributes;

    /**
     * Create a new debounce lock manager instance.
     */
    public function __construct(
        protected Cache $cache
    ) {
    }

    /**
     * Store a debounce owner token for the given job.
     *
     * Overwrites any existing token, implementing last-writer-wins semantics.
     *
     * @return array{owner: string, maxWaitExceeded: bool}
     */
    public function acquire(mixed $job, ?int $debounceFor = null, ?int $maxWait = null): array
    {
        [$cache, $key, $ttl, $maxWait] = $this->resolveLockValues($job, $debounceFor, $maxWait);

        return $this->acquireResolvedLock($cache, $key, $ttl, $maxWait);
    }

    /**
     * Store and register a debounce owner token for a dispatch operation.
     *
     * @return array{owner: string, maxWaitExceeded: bool}
     */
    public function acquireForDispatch(object $job, ?int $debounceFor = null, ?int $maxWait = null): array
    {
        [$cache, $key, $ttl, $maxWait] = $this->resolveLockValues($job, $debounceFor, $maxWait);
        $result = $this->acquireResolvedLock($cache, $key, $ttl, $maxWait);

        if (isset(class_uses_recursive($job)[Queueable::class])) {
            $job->debounceOwner = $result['owner'];
        }

        DispatchLockContext::registerDebounce($job, $cache, $key, $result['owner']);

        return $result;
    }

    /**
     * Resolve the values needed to acquire a debounce lock.
     *
     * @return array{Cache, string, int, null|int}
     */
    protected function resolveLockValues(mixed $job, ?int $debounceFor, ?int $maxWait): array
    {
        $cache = $this->resolveCache($job);
        $ttl = max(($debounceFor ?? $this->getDebounceDelay($job)) * 10, 300);

        return [
            $cache,
            static::getKey($job),
            $ttl,
            $maxWait ?? $this->getMaxDebounceWait($job),
        ];
    }

    /**
     * Store a debounce owner token using resolved values.
     *
     * @return array{owner: string, maxWaitExceeded: bool}
     */
    protected function acquireResolvedLock(Cache $cache, string $key, int $ttl, ?int $maxWait): array
    {
        $cache->put($key, $owner = Str::random(40), $ttl);

        try {
            $maxWaitExceeded = $this->maxWaitExceeded($cache, $key, $ttl, $maxWait);
        } catch (Throwable $exception) {
            try {
                static::releaseOwned($cache, $key, $owner);
            } catch (Throwable) {
                // A cleanup failure must not replace the acquisition failure.
            }

            throw $exception;
        }

        return [
            'owner' => $owner,
            'maxWaitExceeded' => $maxWaitExceeded,
        ];
    }

    /**
     * Determine if the maximum debounce wait time has been exceeded.
     */
    protected function maxWaitExceeded(Cache $cache, string $key, int $ttl, ?int $maxWait): bool
    {
        if ($maxWait === null) {
            return false;
        }

        $timestampKey = $key . ':first_dispatched_at';
        $firstDispatchedAt = $cache->get($timestampKey);

        if ($firstDispatchedAt === null) {
            $cache->add($timestampKey, CarbonImmutable::now()->getTimestamp(), $ttl);

            return false;
        }

        $elapsed = CarbonImmutable::now()->getTimestamp() - $firstDispatchedAt;

        if ($elapsed >= $maxWait) {
            $cache->forget($timestampKey);

            return true;
        }

        return false;
    }

    /**
     * Get the current owner for the given job.
     */
    public function getCurrentOwner(mixed $job): ?string
    {
        /** @var null|string $owner */
        $owner = $this->resolveCache($job)->get(static::getKey($job));

        return $owner;
    }

    /**
     * Remove the debounce token for the given job.
     */
    public function release(mixed $job, string $owner = ''): void
    {
        $cache = $this->resolveCache($job);

        static::releaseOwned($cache, static::getKey($job), $owner);
    }

    /**
     * Remove the maximum wait timestamp for the given job.
     */
    public function releaseMaxWait(mixed $job): void
    {
        $this->resolveCache($job)->forget(static::getKey($job) . ':first_dispatched_at');
    }

    /**
     * Release a debounce lock from already-resolved provenance.
     *
     * @internal
     */
    public static function releaseOwned(Cache $cache, string $key, string $owner = ''): void
    {
        if ($owner !== '' && $cache->get($key) !== $owner) {
            return;
        }

        $exception = null;

        try {
            $cache->forget($key);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        try {
            $cache->forget($key . ':first_dispatched_at');
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Get the debounce delay for the given job.
     */
    public function getDebounceDelay(mixed $job): ?int
    {
        $debounceFor = $this->getAttributeValue($job, DebounceFor::class, 'debounceFor');

        if ($debounceFor === null) {
            return null;
        }

        /** @var int $debounceFor */
        return $debounceFor;
    }

    /**
     * Get the maximum debounce wait time for the given job.
     */
    public function getMaxDebounceWait(mixed $job): ?int
    {
        return $this->getAttributeInstance($job, DebounceFor::class)?->maxWait;
    }

    /**
     * Generate the cache key for the given job.
     */
    public static function getKey(mixed $job): string
    {
        $debounceId = method_exists($job, 'debounceId')
            ? $job->debounceId()
            : ($job->debounceId ?? '');

        $jobName = method_exists($job, 'displayName')
            ? hash('xxh128', $job->displayName())
            : get_class($job);

        // IMPORTANT: Uses Laravel's prefix for cross-framework queue interoperability.
        return 'laravel_debounced_job:' . $jobName . ':' . $debounceId;
    }

    /**
     * Resolve the cache store for the given job.
     */
    protected function resolveCache(mixed $job): Cache
    {
        return method_exists($job, 'debounceVia')
            ? ($job->debounceVia() ?? $this->cache)
            : $this->cache;
    }
}
