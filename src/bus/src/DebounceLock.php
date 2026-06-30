<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Carbon\CarbonImmutable;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Support\Str;

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
        $cache = $this->resolveCache($job);

        $ttl = max(($debounceFor ?? $this->getDebounceDelay($job)) * 10, 300);

        $cache->put($key = static::getKey($job), $owner = Str::random(40), $ttl);

        return [
            'owner' => $owner,
            'maxWaitExceeded' => $this->maxWaitExceeded(
                $cache,
                $key,
                $ttl,
                $maxWait ?? $this->getMaxDebounceWait($job)
            ),
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

        if (! $cache->has($timestampKey)) {
            $cache->put($timestampKey, CarbonImmutable::now()->getTimestamp(), $ttl);

            return false;
        }

        $elapsed = CarbonImmutable::now()->getTimestamp() - $cache->get($timestampKey);

        if ($elapsed >= $maxWait) {
            $cache->forget($timestampKey);

            return true;
        }

        return false;
    }

    /**
     * Determine if the given owner is the current owner for this debounce key.
     */
    public function isCurrentOwner(mixed $job, string $owner): bool
    {
        return $this->resolveCache($job)->get(static::getKey($job)) === $owner;
    }

    /**
     * Determine if a debounce token exists for the given job.
     */
    public function lockExists(mixed $job): bool
    {
        return ! is_null($this->resolveCache($job)->get(static::getKey($job)));
    }

    /**
     * Remove the debounce token for the given job.
     */
    public function release(mixed $job, string $owner = ''): void
    {
        $key = static::getKey($job);

        $cache = $this->resolveCache($job);

        if ($owner !== '' && $cache->get($key) !== $owner) {
            return;
        }

        $cache->forget($key);
        $cache->forget($key . ':first_dispatched_at');
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
