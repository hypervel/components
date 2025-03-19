<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Cache\Contracts\Factory as CacheFactory;

class UniqueLock
{
    /**
     * Create a new unique lock manager instance.
     */
    public function __construct(
        protected CacheFactory $cache
    ) {
    }

    /**
     * Attempt to acquire a lock for the given job.
     */
    public function acquire(mixed $job): bool
    {
        $uniqueFor = method_exists($job, 'uniqueFor')
            ? $job->uniqueFor()
            : ($job->uniqueFor ?? 0);

        $cache = method_exists($job, 'uniqueVia')
            ? $job->uniqueVia()
            : $this->cache;

        return (bool) $cache->lock($this->getKey($job), $uniqueFor)->get();
    }

    /**
     * Release the lock for the given job.
     */
    public function release(mixed $job): void
    {
        $cache = method_exists($job, 'uniqueVia')
            ? $job->uniqueVia()
            : $this->cache;

        $cache->lock($this->getKey($job))->forceRelease();
    }

    /**
     * Generate the lock key for the given job.
     */
    protected function getKey(mixed $job): string
    {
        $uniqueId = method_exists($job, 'uniqueId')
            ? $job->uniqueId()
            : ($job->uniqueId ?? '');

        return 'laravel_unique_job:' . get_class($job) . ':' . $uniqueId;
    }
}
