<?php

declare(strict_types=1);

namespace Hypervel\Redis\Limiters;

use Hypervel\Contracts\Redis\LimiterTimeoutException;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Str;
use Throwable;

class ConcurrencyLimiter
{
    /**
     * Create a new concurrency limiter instance.
     *
     * @param RedisProxy $redis the Redis connection instance
     * @param string $name the name of the limiter
     * @param int $maxLocks the allowed number of concurrent tasks
     * @param int $releaseAfter the number of seconds a slot should be maintained
     */
    public function __construct(
        protected RedisProxy $redis,
        protected string $name,
        protected int $maxLocks,
        protected int $releaseAfter
    ) {
    }

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * @throws LimiterTimeoutException
     * @throws Throwable
     */
    public function block(int $timeout, ?callable $callback = null, int $sleep = 250): mixed
    {
        $starting = time();

        $id = Str::random(20);

        while (! $slot = $this->acquire($id)) {
            if (time() - $timeout >= $starting) {
                throw new LimiterTimeoutException();
            }

            usleep($sleep * 1000);
        }

        if (is_callable($callback)) {
            try {
                return tap($callback(), function () use ($slot, $id) {
                    $this->release($slot, $id);
                });
            } catch (Throwable $exception) {
                $this->release($slot, $id);

                throw $exception;
            }
        }

        return true;
    }

    /**
     * Attempt to acquire the lock.
     *
     * @param string $id a unique identifier for this lock
     */
    protected function acquire(string $id): mixed
    {
        $slots = array_map(function ($i) {
            return $this->name . $i;
        }, range(1, $this->maxLocks));

        return $this->redis->eval(...array_merge(
            [$this->lockScript(), count($slots)],
            array_merge($slots, [$this->name, $this->releaseAfter, $id])
        ));
    }

    /**
     * Get the Lua script for acquiring a lock.
     *
     * KEYS    - The keys that represent available slots
     * ARGV[1] - The limiter name
     * ARGV[2] - The number of seconds the slot should be reserved
     * ARGV[3] - The unique identifier for this lock
     */
    protected function lockScript(): string
    {
        return <<<'LUA'
for index, value in pairs(redis.call('mget', unpack(KEYS))) do
    if not value then
        redis.call('set', KEYS[index], ARGV[3], "EX", ARGV[2])
        return ARGV[1]..index
    end
end
LUA;
    }

    /**
     * Release the lock.
     */
    protected function release(string $key, string $id): void
    {
        $this->redis->eval($this->releaseScript(), 1, $key, $id);
    }

    /**
     * Get the Lua script to atomically release a lock.
     *
     * KEYS[1] - The name of the lock
     * ARGV[1] - The unique identifier for this lock
     */
    protected function releaseScript(): string
    {
        return <<<'LUA'
if redis.call('get', KEYS[1]) == ARGV[1]
then
    return redis.call('del', KEYS[1])
else
    return 0
end
LUA;
    }
}
