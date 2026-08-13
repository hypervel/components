<?php

declare(strict_types=1);

namespace Hypervel\Saloon\RateLimit\Queue;

use Hypervel\Saloon\RateLimit\Exceptions\RateLimitReachedException;

class ReleaseOnRateLimit
{
    /**
     * Handle the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        try {
            return $next($job);
        } catch (RateLimitReachedException $exception) {
            // The release already schedules the retry. Rethrowing would report
            // the handled backoff as a job failure.
            return $job->release($exception->result()->retryAfter());
        }
    }
}
