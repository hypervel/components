<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Support\Traits\InteractsWithTime;
use Throwable;

class ThrottlesExceptionsWithRedis extends ThrottlesExceptions
{
    use InteractsWithTime;

    /**
     * The Redis factory implementation.
     */
    protected ?RedisFactory $redis = null;

    /**
     * The rate limiter instance.
     */
    protected $limiter;

    /**
     * Process the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        $this->redis = ApplicationContext::getContainer()
            ->get(RedisFactory::class);

        $this->limiter = new DurationLimiter(
            $this->redis,
            $this->getConnectionName(),
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
            if ($this->whenCallback && ! call_user_func($this->whenCallback, $throwable)) {
                throw $throwable;
            }

            if ($this->reportCallback && call_user_func($this->reportCallback, $throwable)) {
                report($throwable);
            }

            $this->limiter->acquire();

            return $job->release($this->retryAfterMinutes * 60);
        }

        return null;
    }

    protected function getConnectionName(): string
    {
        return ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('queue.connections.redis.connection', 'default');
    }
}
