<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use DateTimeInterface;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\Unlimited;
use Hypervel\Container\Container;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use UnitEnum;

use function Hypervel\Support\enum_value;

class RateLimited
{
    /**
     * The rate limiter instance.
     */
    protected RateLimiter $limiter;

    /**
     * The name of the rate limiter.
     */
    protected string $limiterName;

    /**
     * The number of seconds before a job should be available again if the limit is exceeded.
     */
    public DateTimeInterface|int|null $releaseAfter = null;

    /**
     * Indicates if the job should be released if the limit is exceeded.
     */
    public bool $shouldRelease = true;

    /**
     * Create a new middleware instance.
     */
    public function __construct(UnitEnum|string $limiterName)
    {
        $this->limiter = Container::getInstance()
            ->make(RateLimiter::class);

        $this->limiterName = (string) enum_value($limiterName);
    }

    /**
     * Process the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        if (is_null($limiter = $this->limiter->limiter($this->limiterName))) {
            return $next($job);
        }

        $limiterResponse = $limiter($job);

        if ($limiterResponse instanceof Unlimited) {
            return $next($job);
        }

        return $this->handleJob(
            $job,
            $next,
            Collection::make(Arr::wrap($limiterResponse))->map(function ($limit) {
                return (object) [
                    'key' => md5($this->limiterName . $limit->key),
                    'maxAttempts' => $limit->maxAttempts,
                    'decaySeconds' => $limit->decaySeconds,
                ];
            })->all()
        );
    }

    /**
     * Handle a rate limited job.
     */
    protected function handleJob(mixed $job, callable $next, array $limits): mixed
    {
        foreach ($limits as $limit) {
            if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                return $this->shouldRelease
                    ? $job->release($this->releaseAfter ?: $this->getTimeUntilNextRetry($limit->key))
                    : false;
            }

            $this->limiter->hit($limit->key, $limit->decaySeconds);
        }

        return $next($job);
    }

    /**
     * Set the delay (in seconds) to release the job back to the queue.
     */
    public function releaseAfter(DateTimeInterface|int $releaseAfter): static
    {
        $this->releaseAfter = $releaseAfter;

        return $this;
    }

    /**
     * Do not release the job back to the queue if the limit is exceeded.
     */
    public function dontRelease(): static
    {
        $this->shouldRelease = false;

        return $this;
    }

    /**
     * Get the number of seconds that should elapse before the job is retried.
     */
    protected function getTimeUntilNextRetry(string $key): int
    {
        return $this->limiter->availableIn($key) + 3;
    }

    /**
     * Prepare the object for serialization.
     */
    public function __sleep(): array
    {
        return [
            'limiterName',
            'shouldRelease',
        ];
    }

    /**
     * Prepare the object after unserialization.
     */
    public function __wakeup()
    {
        $this->limiter = Container::getInstance()
            ->make(RateLimiter::class);
    }
}
