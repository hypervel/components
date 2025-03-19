<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use Hypervel\Cache\RateLimiter;
use Hypervel\Context\ApplicationContext;
use Throwable;

class ThrottlesExceptions
{
    /**
     * The developer specified key that the rate limiter should use.
     */
    protected ?string $key = null;

    /**
     * Indicates whether the throttle key should use the job's UUID.
     */
    protected bool $byJob = false;

    /**
     * The number of minutes to wait before retrying the job after an exception.
     */
    protected int $retryAfterMinutes = 0;

    /**
     * The callback that determines if the exception should be reported.
     *
     * @var callable
     */
    protected $reportCallback;

    /**
     * The callback that determines if rate limiting should apply.
     *
     * @var callable
     */
    protected $whenCallback;

    /**
     * The prefix of the rate limiter key.
     */
    protected string $prefix = 'laravel_throttles_exceptions:';

    /**
     * The rate limiter instance.
     */
    protected $limiter;

    /**
     * Create a new middleware instance.
     *
     * @param int $maxAttempts the maximum number of attempts allowed before rate limiting applies
     * @param int $decaySeconds the number of seconds until the maximum attempts are reset
     */
    public function __construct(
        protected int $maxAttempts = 10,
        protected int $decaySeconds = 600
    ) {
    }

    /**
     * Process the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        $this->limiter = ApplicationContext::getContainer()
            ->get(RateLimiter::class);

        if ($this->limiter->tooManyAttempts($jobKey = $this->getKey($job), $this->maxAttempts)) {
            return $job->release($this->getTimeUntilNextRetry($jobKey));
        }

        try {
            $next($job);

            $this->limiter->clear($jobKey);
        } catch (Throwable $throwable) {
            if ($this->whenCallback && ! call_user_func($this->whenCallback, $throwable)) {
                throw $throwable;
            }

            if ($this->reportCallback && call_user_func($this->reportCallback, $throwable)) {
                report($throwable);
            }

            $this->limiter->hit($jobKey, $this->decaySeconds);

            return $job->release($this->retryAfterMinutes * 60);
        }

        return null;
    }

    /**
     * Specify a callback that should determine if rate limiting behavior should apply.
     */
    public function when(callable $callback): static
    {
        $this->whenCallback = $callback;

        return $this;
    }

    /**
     * Set the prefix of the rate limiter key.
     */
    public function withPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Specify the number of minutes a job should be delayed when it is released (before it has reached its max exceptions).
     */
    public function backoff(int $backoff): static
    {
        $this->retryAfterMinutes = $backoff;

        return $this;
    }

    /**
     * Get the cache key associated for the rate limiter.
     */
    protected function getKey(mixed $job): string
    {
        if ($this->key) {
            return $this->prefix . $this->key;
        }
        if ($this->byJob) {
            return $this->prefix . $job->job->uuid();
        }

        return $this->prefix . md5(get_class($job));
    }

    /**
     * Set the value that the rate limiter should be keyed by.
     */
    public function by(string $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Indicate that the throttle key should use the job's UUID.
     */
    public function byJob(): static
    {
        $this->byJob = true;

        return $this;
    }

    /**
     * Report exceptions and optionally specify a callback that determines if the exception should be reported.
     */
    public function report(?callable $callback = null): static
    {
        $this->reportCallback = $callback ?? fn () => true;

        return $this;
    }

    /**
     * Get the number of seconds that should elapse before the job is retried.
     */
    protected function getTimeUntilNextRetry(string $key): int
    {
        return $this->limiter->availableIn($key) + 3;
    }
}
