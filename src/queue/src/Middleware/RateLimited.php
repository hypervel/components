<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use DateTimeInterface;
use Hypervel\Container\Container;
use Hypervel\RateLimiter\AdmissionPolicy;
use Hypervel\RateLimiter\Limiter;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\RateLimiter\Unlimited;
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
     * The rate limiter store that should be used.
     */
    protected ?string $storeName = null;

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
            Collection::wrap($limiterResponse)->all(),
            $this->limiter->store(
                $this->storeName ?? $this->limiter->limiterStore($this->limiterName)
            ),
        );
    }

    /**
     * Handle a rate limited job.
     *
     * @param array<AdmissionPolicy> $limits
     */
    protected function handleJob(mixed $job, callable $next, array $limits, Limiter $limiter): mixed
    {
        // Laravel preflights every policy before recording hits. Atomic stores
        // consume in order, so an earlier accepted decision is never rolled back.
        foreach ($limits as $limit) {
            $result = $limiter->consume($limit, $this->limiterName);

            if ($result->denied()) {
                return $this->shouldRelease
                    ? $job->release($this->releaseAfter ?? $result->retryAfter() + 3)
                    : false;
            }
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
     * Prepare the object for serialization.
     */
    public function __sleep(): array
    {
        return [
            'limiterName',
            'storeName',
            'releaseAfter',
            'shouldRelease',
        ];
    }

    /**
     * Prepare the object after unserialization.
     */
    public function __wakeup(): void
    {
        $this->limiter = Container::getInstance()
            ->make(RateLimiter::class);
    }
}
