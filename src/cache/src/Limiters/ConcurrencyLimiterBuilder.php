<?php

declare(strict_types=1);

namespace Hypervel\Cache\Limiters;

use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\Repository;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Limiters\Lease;
use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Support\InteractsWithTime;
use Throwable;

class ConcurrencyLimiterBuilder
{
    use InteractsWithTime;

    /**
     * The maximum number of entities that can hold the lock at the same time.
     */
    public int $maxLocks;

    /**
     * The number of seconds to maintain the lock until it is automatically released.
     */
    public int $releaseAfter = 60;

    /**
     * The number of seconds to block until a lock is available.
     */
    public int $timeout = 3;

    /**
     * The number of milliseconds to wait between attempts to acquire the lock.
     */
    public int $sleep = 250;

    /**
     * Create a new builder instance.
     *
     * @param Repository $connection the cache repository instance
     * @param string $name the name of the limiter
     */
    public function __construct(
        public Repository $connection,
        public string $name,
    ) {
    }

    /**
     * Set the maximum number of locks that can be obtained per time window.
     */
    public function limit(int $maxLocks): static
    {
        $this->maxLocks = $maxLocks;

        return $this;
    }

    /**
     * Set the number of seconds until the lock will be released.
     */
    public function releaseAfter(DateInterval|DateTimeInterface|int $releaseAfter): static
    {
        $this->releaseAfter = $this->secondsUntil($releaseAfter);

        return $this;
    }

    /**
     * Set the number of seconds to block until a lock is available.
     */
    public function block(int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * The number of milliseconds to wait between lock acquisition attempts.
     */
    public function sleep(int $sleep): static
    {
        $this->sleep = $sleep;

        return $this;
    }

    /**
     * Acquire a lease on one of the limiter's slots, waiting up to the configured timeout.
     *
     * @throws LimiterTimeoutException
     */
    public function acquire(): Lease
    {
        return $this->createLimiter()->acquire($this->timeout, $this->sleep);
    }

    /**
     * Execute the given callback if a lock is obtained, otherwise call the failure callback.
     *
     * @throws LimiterTimeoutException
     */
    public function then(callable $callback, ?callable $failure = null): mixed
    {
        try {
            $lease = $this->acquire();
        } catch (LimiterTimeoutException $timeoutException) {
            if ($failure !== null) {
                return $failure($timeoutException);
            }

            throw $timeoutException;
        }

        try {
            $result = $callback();
        } catch (Throwable $throwable) {
            try {
                $lease->release();
            } catch (Throwable) {
                // Preserve the callback failure as primary.
            }

            throw $throwable;
        }

        $lease->release();

        return $result;
    }

    /**
     * Create the concurrency limiter instance.
     */
    protected function createLimiter(): ConcurrencyLimiter
    {
        // Type is guaranteed by the LockProvider check in Repository::funnel(),
        // but Repository::getStore() returns Store so phpstan needs help narrowing.
        /** @var LockProvider&Store $store */
        $store = $this->connection->getStore();

        if ($store instanceof RedisStore) {
            return new RedisConcurrencyLimiter($store, $this->name, $this->maxLocks, $this->releaseAfter);
        }

        return new ConcurrencyLimiter($store, $this->name, $this->maxLocks, $this->releaseAfter);
    }
}
