<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use Closure;

use function value;

class Skip
{
    /**
     * Create a new middleware instance.
     *
     * @param bool $skip whether the job should be skipped
     */
    public function __construct(protected bool $skip = false)
    {
    }

    /**
     * Apply the middleware if the given condition is truthy.
     *
     * @param bool|(Closure(): bool) $condition
     */
    public static function when(bool|Closure $condition): static
    {
        return new static(value($condition));
    }

    /**
     * Apply the middleware unless the given condition is truthy.
     *
     * @param bool|(Closure(): bool) $condition
     */
    public static function unless(bool|Closure $condition): static
    {
        return new static(! value($condition));
    }

    /**
     * Handle the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        if ($this->skip) {
            return false;
        }

        return $next($job);
    }
}
