<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use Closure;
use Hypervel\Container\Container;
use Hypervel\RateLimiter\Limit;
use Hypervel\RateLimiter\RateLimiter;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

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
    protected Closure|int $retryAfterMinutes = 0;

    /**
     * The callback that determines if the exception should be reported.
     *
     * @var ?callable
     */
    protected $reportCallback;

    /**
     * The callback that determines if rate limiting should apply.
     *
     * @var ?callable
     */
    protected $whenCallback;

    /**
     * The callbacks that determine if the job should be deleted.
     *
     * @var callable[]
     */
    protected array $deleteWhenCallbacks = [];

    /**
     * The callbacks that determine if the job should be failed.
     *
     * @var callable[]
     */
    protected array $failWhenCallbacks = [];

    /**
     * The prefix of the rate limiter key.
     */
    protected string $prefix = 'hypervel:queue:throttles-exceptions:';

    /**
     * The rate limiter store that should be used.
     */
    protected ?string $storeName = null;

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
        $limiter = Container::getInstance()
            ->make(RateLimiter::class)
            ->store($this->storeName);
        $policy = new Limit(
            maxAttempts: $this->maxAttempts,
            decaySeconds: $this->decaySeconds,
            key: $this->getKey($job),
        );
        $result = $limiter->inspect($policy);

        if ($result->denied()) {
            return $job->release($result->retryAfter() + 3);
        }

        try {
            $next($job);

            $limiter->clear($policy);
        } catch (Throwable $throwable) {
            if ($this->whenCallback && ! call_user_func($this->whenCallback, $throwable, $limiter)) {
                throw $throwable;
            }

            if ($this->reportCallback && call_user_func($this->reportCallback, $throwable, $limiter)) {
                report($throwable);
            }

            if ($this->shouldDelete($throwable)) {
                return $job->delete();
            }

            if ($this->shouldFail($throwable)) {
                return $job->fail($throwable);
            }

            $result = $limiter->consume($policy);

            return $job->release(
                $result->denied()
                    ? $result->retryAfter() + 3
                    : $this->getTimeUntilNextRetryAfterException($throwable),
            );
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
     * Add a callback that should determine if the job should be deleted.
     */
    public function deleteWhen(callable|string $callback): static
    {
        $this->deleteWhenCallbacks[] = is_string($callback)
            ? fn (Throwable $e) => $e instanceof $callback
            : $callback;

        return $this;
    }

    /**
     * Add a callback that should determine if the job should be failed.
     */
    public function failWhen(callable|string $callback): static
    {
        $this->failWhenCallbacks[] = is_string($callback)
            ? fn (Throwable $e) => $e instanceof $callback
            : $callback;

        return $this;
    }

    /**
     * Determine if the job should be deleted for the given exception.
     */
    protected function shouldDelete(Throwable $throwable): bool
    {
        foreach ($this->deleteWhenCallbacks as $callback) {
            if (call_user_func($callback, $throwable)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the job should be failed for the given exception.
     */
    protected function shouldFail(Throwable $throwable): bool
    {
        foreach ($this->failWhenCallbacks as $callback) {
            if (call_user_func($callback, $throwable)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set the prefix of the rate limiter key.
     */
    public function withPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    // Hypervel selects Redis through the same store API as every other backend
    // instead of exposing Laravel's Redis-only queue middleware and connection().
    /**
     * Specify the rate limiter store that should be used.
     */
    public function store(UnitEnum|string $store): static
    {
        $this->storeName = (string) enum_value($store);

        return $this;
    }

    /**
     * Specify the number of minutes a job should be delayed when it is released (before it has reached its max exceptions).
     */
    public function backoff(Closure|int $backoff): static
    {
        $this->retryAfterMinutes = $backoff;

        return $this;
    }

    /**
     * Get the number of seconds that should elapse before the job is retried after an exception.
     */
    protected function getTimeUntilNextRetryAfterException(Throwable $throwable): int
    {
        $backoff = $this->retryAfterMinutes instanceof Closure
            ? ($this->retryAfterMinutes)($throwable)
            : $this->retryAfterMinutes;

        return $backoff * 60;
    }

    /**
     * Get the key associated with the rate limiter.
     */
    protected function getKey(mixed $job): string
    {
        if ($this->key) {
            return $this->prefix . $this->key;
        }

        if ($this->byJob) {
            return $this->prefix . $job->job->uuid();
        }

        $jobName = method_exists($job, 'displayName')
            ? $job->displayName()
            : get_class($job);

        return $this->prefix . $jobName;
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
}
