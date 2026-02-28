<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Queue\Attributes\UniqueFor;

class UniqueLock
{
    use ReadsQueueAttributes;

    /**
     * Create a new unique lock manager instance.
     */
    public function __construct(
        protected Cache $cache
    ) {
    }

    /**
     * Attempt to acquire a lock for the given job.
     */
    public function acquire(mixed $job): bool
    {
        $uniqueFor = method_exists($job, 'uniqueFor')
            ? $job->uniqueFor()
            : ($this->getAttributeValue($job, UniqueFor::class, 'uniqueFor') ?? 0);

        $cache = method_exists($job, 'uniqueVia')
            ? ($job->uniqueVia() ?? $this->cache)
            : $this->cache;

        return (bool) $cache->lock(static::getKey($job), $uniqueFor)->get();
    }

    /**
     * Release the lock for the given job.
     */
    public function release(mixed $job): void
    {
        $cache = method_exists($job, 'uniqueVia')
            ? ($job->uniqueVia() ?? $this->cache)
            : $this->cache;

        $cache->lock(static::getKey($job))->forceRelease();
    }

    /**
     * Generate the lock key for the given job.
     */
    public static function getKey(mixed $job): string
    {
        $uniqueId = method_exists($job, 'uniqueId')
            ? $job->uniqueId()
            : ($job->uniqueId ?? '');

        $jobName = method_exists($job, 'displayName')
            ? $job->displayName()
            : get_class($job);

        return 'laravel_unique_job:' . $jobName . ':' . $uniqueId;
    }
}
