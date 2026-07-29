<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Closure;
use Hypervel\Cache\RedisLock;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Redis\RedisProxy;
use InvalidArgumentException;

class Lock
{
    /**
     * Create a Horizon lock manager.
     *
     * @param Redis $redis the Redis factory implementation
     */
    public function __construct(
        public Redis $redis
    ) {
    }

    /**
     * Execute the given callback if a lock can be acquired.
     */
    public function with(string $key, Closure $callback, int $seconds = 60): void
    {
        $this->assertPositiveLifetime($key, $seconds);

        (new RedisLock($this->connection(), $key, $seconds))->get($callback);
    }

    /**
     * Determine if a lock exists for the given key.
     */
    public function exists(string $key): bool
    {
        return $this->connection()->exists($key) === 1;
    }

    /**
     * Attempt to get a lock for the given key.
     */
    public function get(string $key, int $seconds = 60): bool
    {
        $this->assertPositiveLifetime($key, $seconds);

        return $this->connection()->set($key, '1', 'EX', $seconds, 'NX') === true;
    }

    /**
     * Release the lock for the given key.
     */
    public function release(string $key): void
    {
        $this->connection()->del($key);
    }

    /**
     * Ensure the lock lifetime is positive.
     */
    private function assertPositiveLifetime(string $key, int $seconds): void
    {
        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                "Horizon lock [{$key}] requires a positive lifetime; {$seconds} given."
            );
        }
    }

    /**
     * Get the Redis connection instance.
     */
    public function connection(): RedisProxy
    {
        return $this->redis->connection('horizon');
    }
}
