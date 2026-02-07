<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Closure;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;

class Lock
{
    /**
     * Create a Horizon lock manager.
     *
     * @param RedisFactory $redis the Redis factory implementation
     */
    public function __construct(
        public RedisFactory $redis
    ) {
    }

    /**
     * Execute the given callback if a lock can be acquired.
     */
    public function with(string $key, Closure $callback, int $seconds = 60): void
    {
        if ($this->get($key, $seconds)) {
            try {
                call_user_func($callback);
            } finally {
                $this->release($key);
            }
        }
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
        if ($result = $this->connection()->setNx($key, 1)) {
            $this->connection()->expire($key, $seconds);
        }

        return $result;
    }

    /**
     * Release the lock for the given key.
     */
    public function release(string $key): void
    {
        $this->connection()->del($key);
    }

    /**
     * Get the Redis connection instance.
     */
    public function connection(): RedisProxy
    {
        return $this->redis->get('horizon');
    }
}
