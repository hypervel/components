<?php

declare(strict_types=1);

namespace Hypervel\Queue\Middleware;

use DateInterval;
use DateTimeInterface;
use Hyperf\Context\ApplicationContext;
use Hypervel\Cache\Contracts\Factory as CacheFactory;
use Hypervel\Support\Traits\InteractsWithTime;

class WithoutOverlapping
{
    use InteractsWithTime;

    /**
     * The number of seconds before the lock should expire.
     */
    public int $expiresAfter;

    /**
     * The prefix of the lock key.
     */
    public string $prefix = 'laravel-queue-overlap:';

    /**
     * Share the key across different jobs.
     */
    public bool $shareKey = false;

    /**
     * Create a new middleware instance.
     *
     * @param string $key the job's unique key used for preventing overlaps
     * @param null|DateTimeInterface|int $releaseAfter the number of seconds before a job should be available again if no lock was acquired
     * @param DateTimeInterface|int $expiresAfter the number of seconds before the lock should expire
     */
    public function __construct(
        public string $key = '',
        public null|DateTimeInterface|int $releaseAfter = 0,
        DateTimeInterface|int $expiresAfter = 0
    ) {
        $this->expiresAfter = $this->secondsUntil($expiresAfter);
    }

    /**
     * Process the job.
     */
    public function handle(mixed $job, callable $next): mixed
    {
        $lock = ApplicationContext::getContainer()
            ->get(CacheFactory::class)->lock(
                $this->getLockKey($job),
                $this->expiresAfter
            );

        if ($lock->get()) {
            try {
                $next($job);
            } finally {
                $lock->release();
            }
        } elseif (! is_null($this->releaseAfter)) {
            $job->release($this->releaseAfter);
        }

        return null;
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
     * Do not release the job back to the queue if no lock can be acquired.
     */
    public function dontRelease(): static
    {
        $this->releaseAfter = null;

        return $this;
    }

    /**
     * Set the maximum number of seconds that can elapse before the lock is released.
     */
    public function expireAfter(DateInterval|DateTimeInterface|int $expiresAfter): static
    {
        $this->expiresAfter = $this->secondsUntil($expiresAfter);

        return $this;
    }

    /**
     * Set the prefix of the lock key.
     */
    public function withPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Indicate that the lock key should be shared across job classes.
     */
    public function shared(): static
    {
        $this->shareKey = true;

        return $this;
    }

    /**
     * Get the lock key for the given job.
     */
    public function getLockKey(mixed $job): string
    {
        return $this->shareKey
            ? $this->prefix . $this->key
            : $this->prefix . get_class($job) . ':' . $this->key;
    }
}
