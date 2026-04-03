<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use Hypervel\Container\Container;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\InteractsWithTime;
use Throwable;

class ThrottlesExceptionsWithRedis extends ThrottlesExceptions
{
    use InteractsWithTime;

    /**
     * The Redis connection instance.
     */
    protected ?RedisProxy $redis = null;

    /**
     * The Redis connection that should be used.
     */
    protected ?string $connectionName = null;

    /**
     * Process the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        $this->redis = Container::getInstance()
            ->make(Redis::class)
            ->connection($this->connectionName);

        $this->limiter = new DurationLimiter(
            $this->redis,
            $this->getKey($job),
            $this->maxAttempts,
            $this->decaySeconds
        );

        if ($this->limiter->tooManyAttempts()) {
            return $job->release($this->limiter->decaysAt - $this->currentTime());
        }

        try {
            $next($job);

            $this->limiter->clear();
        } catch (Throwable $throwable) {
            if ($this->whenCallback && ! call_user_func($this->whenCallback, $throwable, $this->limiter)) {
                throw $throwable;
            }

            if ($this->reportCallback && call_user_func($this->reportCallback, $throwable, $this->limiter)) {
                report($throwable);
            }

            if ($this->shouldDelete($throwable)) {
                return $job->delete();
            }

            if ($this->shouldFail($throwable)) {
                return $job->fail($throwable);
            }

            $this->limiter->acquire();

            return $job->release($this->retryAfterMinutes * 60);
        }

        return null;
    }

    /**
     * Specify the Redis connection that should be used.
     */
    public function connection(string $name): static
    {
        $this->connectionName = $name;

        return $this;
    }
}
