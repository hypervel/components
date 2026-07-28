<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use Closure;

class Release
{
    /**
     * Create a new middleware instance.
     *
     * @param bool $release whether the job should be released back onto the queue
     * @param int $releaseAfter the number of seconds before the job is available again
     */
    public function __construct(
        protected bool $release = false,
        protected int $releaseAfter = 0,
    ) {
    }

    /**
     * Release the job back onto the queue if the given condition is truthy.
     *
     * @param bool|(Closure(): bool) $condition
     */
    public static function when(Closure|bool $condition, int $releaseAfter = 0): static
    {
        return new static(value($condition), $releaseAfter);
    }

    /**
     * Release the job back onto the queue unless the given condition is truthy.
     *
     * @param bool|(Closure(): bool) $condition
     */
    public static function unless(Closure|bool $condition, int $releaseAfter = 0): static
    {
        return new static(! value($condition), $releaseAfter);
    }

    /**
     * Handle the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        if ($this->release) {
            return $job->release($this->releaseAfter);
        }

        return $next($job);
    }
}
