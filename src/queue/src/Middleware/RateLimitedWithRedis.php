<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use Hypervel\Container\Container;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Support\InteractsWithTime;

class RateLimitedWithRedis extends RateLimited
{
    use InteractsWithTime;

    /**
     * The name of the Redis connection that should be used.
     */
    protected ?string $connectionName = null;

    /**
     * The timestamp of the end of the current duration by key.
     */
    public array $decaysAt = [];

    /**
     * Create a new middleware instance.
     */
    public function __construct(string $limiterName, ?string $connection = null)
    {
        parent::__construct($limiterName);

        $this->connectionName = $connection;
    }

    /**
     * Handle a rate limited job.
     */
    protected function handleJob(mixed $job, callable $next, array $limits): mixed
    {
        foreach ($limits as $limit) {
            if ($this->tooManyAttempts($limit->key, $limit->maxAttempts, $limit->decaySeconds)) {
                return $this->shouldRelease
                    ? $job->release($this->releaseAfter ?: $this->getTimeUntilNextRetry($limit->key))
                    : false;
            }
        }

        return $next($job);
    }

    /**
     * Determine if the given key has been "accessed" too many times.
     */
    protected function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool
    {
        $redis = Container::getInstance()
            ->make(Redis::class)
            ->connection($this->connectionName);

        $limiter = new DurationLimiter(
            $redis,
            $key,
            $maxAttempts,
            $decaySeconds
        );

        return tap(! $limiter->acquire(), function () use ($key, $limiter) {
            $this->decaysAt[$key] = $limiter->decaysAt;
        });
    }

    /**
     * Get the number of seconds that should elapse before the job is retried.
     */
    protected function getTimeUntilNextRetry(string $key): int
    {
        return ($this->decaysAt[$key] - $this->currentTime()) + 3;
    }

    /**
     * Specify the Redis connection that should be used.
     */
    public function connection(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }

    /**
     * Prepare the object for serialization.
     */
    public function __sleep(): array
    {
        return array_merge(parent::__sleep(), ['connectionName']);
    }

    /**
     * Prepare the object after unserialization.
     */
    public function __wakeup()
    {
        parent::__wakeup();
    }
}
