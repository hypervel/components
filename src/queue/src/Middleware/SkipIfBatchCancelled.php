<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

class SkipIfBatchCancelled
{
    /**
     * Process the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        if (method_exists($job, 'batch') && $job->batch()?->cancelled()) {
            return null;
        }

        return $next($job);
    }
}
