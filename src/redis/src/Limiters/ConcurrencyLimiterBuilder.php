<?php

declare(strict_types=1);

namespace Hypervel\Redis\Limiters;

use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Redis\LimiterTimeoutException;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\InteractsWithTime;

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
     * The amount of time to block until a lock is available.
     */
    public int $timeout = 3;

    /**
     * The number of milliseconds to wait between attempts to acquire the lock.
     */
    public int $sleep = 250;

    /**
     * Create a new builder instance.
     *
     * @param RedisProxy $connection the Redis connection instance
     * @param string $name the name of the lock
     */
    public function __construct(
        public RedisProxy $connection,
        public string $name
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
    public function releaseAfter(DateTimeInterface|DateInterval|int $releaseAfter): static
    {
        $this->releaseAfter = $this->secondsUntil($releaseAfter);

        return $this;
    }

    /**
     * Set the amount of time to block until a lock is available.
     */
    public function block(int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Set the number of milliseconds to wait between lock acquisition attempts.
     */
    public function sleep(int $sleep): static
    {
        $this->sleep = $sleep;

        return $this;
    }

    /**
     * Execute the given callback if a lock is obtained, otherwise call the failure callback.
     *
     * @throws LimiterTimeoutException
     */
    public function then(callable $callback, ?callable $failure = null): mixed
    {
        try {
            return (new ConcurrencyLimiter(
                $this->connection,
                $this->name,
                $this->maxLocks,
                $this->releaseAfter
            ))->block($this->timeout, $callback, $this->sleep);
        } catch (LimiterTimeoutException $e) {
            if ($failure) {
                return $failure($e);
            }

            throw $e;
        }
    }
}
