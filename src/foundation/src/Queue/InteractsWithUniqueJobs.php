<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Queue;

use Hypervel\Bus\UniqueLock;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Queue\ShouldBeUnique;

trait InteractsWithUniqueJobs
{
    /**
     * Store unique job information in the context in case we can't resolve the job on the queue side.
     */
    public function addUniqueJobInformationToContext(mixed $job): void
    {
        if ($job instanceof ShouldBeUnique) {
            // IMPORTANT: Uses Laravel's keys for cross-framework queue interoperability.
            CoroutineContext::propagated()->addHidden([
                'laravel_unique_job_cache_store' => $this->getUniqueJobCacheStore($job),
                'laravel_unique_job_key' => UniqueLock::getKey($job),
            ]);
        }
    }

    /**
     * Remove the unique job information from the context.
     */
    public function removeUniqueJobInformationFromContext(mixed $job): void
    {
        if ($job instanceof ShouldBeUnique) {
            // IMPORTANT: Uses Laravel's keys for cross-framework queue interoperability.
            CoroutineContext::propagated()->forgetHidden([
                'laravel_unique_job_cache_store',
                'laravel_unique_job_key',
            ]);
        }
    }

    /**
     * Determine the cache store used by the unique job to acquire locks.
     */
    protected function getUniqueJobCacheStore(mixed $job): ?string
    {
        return method_exists($job, 'uniqueVia')
            ? $job->uniqueVia()->getName()
            : config('cache.default');
    }
}
