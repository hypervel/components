<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hypervel\Redis\Limiters\DurationLimiter;
use Hypervel\Support\Traits\InteractsWithTime;

use function Hyperf\Tappable\tap;

class RateLimitedWithRedis extends RateLimited
{
    use InteractsWithTime;

    /**
     * The Redis factory implementation.
     */
    protected RedisFactory $redis;

    /**
     * The timestamp of the end of the current duration by key.
     */
    public array $decaysAt = [];

    /**
     * Create a new middleware instance.
     */
    public function __construct(string $limiterName)
    {
        parent::__construct($limiterName);

        $this->redis = ApplicationContext::getContainer()
            ->get(RedisFactory::class);
    }

    /**
     * Handle a rate limited job.
     */
    protected function handleJob(mixed $job, callable $next, array $limits): mixed
    {
        foreach ($limits as $limit) {
            if ($this->tooManyAttempts($limit->key, $limit->maxAttempts, $limit->decaySeconds)) {
                return $this->shouldRelease
                    ? $job->release($this->getTimeUntilNextRetry($limit->key))
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
        $limiter = new DurationLimiter(
            $this->redis,
            $this->getConnectionName(),
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

    protected function getConnectionName(): string
    {
        return ApplicationContext::getContainer()
            ->get(ConfigInterface::class)
            ->get('queue.connections.redis.connection', 'default');
    }

    /**
     * Prepare the object after unserialization.
     */
    public function __wakeup()
    {
        parent::__wakeup();

        $this->redis = ApplicationContext::getContainer()->get(RedisFactory::class);
    }
}
