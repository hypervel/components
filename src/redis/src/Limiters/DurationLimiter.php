<?php

declare(strict_types=1);

namespace Hypervel\Redis\Limiters;

use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;

class DurationLimiter
{
    /**
     * The timestamp of the end of the current duration.
     */
    public ?int $decaysAt = null;

    /**
     * The number of remaining slots.
     */
    public ?int $remaining = null;

    /**
     * Create a new duration limiter instance.
     *
     * @param RedisFactory $redis the Redis factory implementation
     * @param string $connection the Redis connection name
     * @param string $name the unique name of the lock
     * @param int $maxLocks the allowed number of concurrent tasks
     * @param int $decay the number of seconds a slot should be maintained
     */
    public function __construct(
        protected RedisFactory $redis,
        protected string $connection,
        protected string $name,
        protected int $maxLocks,
        protected int $decay
    ) {
    }

    /**
     * Attempt to acquire the lock for the given number of seconds.
     *
     * @throws LimiterTimeoutException
     */
    public function block(int $timeout, ?callable $callback = null, int $sleep = 750): mixed
    {
        $starting = time();

        while (! $this->acquire()) {
            if (time() - $timeout >= $starting) {
                throw new LimiterTimeoutException();
            }

            usleep($sleep * 1000);
        }

        if (is_callable($callback)) {
            return $callback();
        }

        return true;
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        $results = $this->getConnection()->eval(
            $this->luaScript(),
            [
                $this->name,
                microtime(true),
                time(),
                $this->decay,
                $this->maxLocks,
            ],
            1
        );

        $this->decaysAt = $results[1];

        $this->remaining = max(0, $results[2]);

        return (bool) $results[0];
    }

    /**
     * Determine if the key has been "accessed" too many times.
     */
    public function tooManyAttempts(): bool
    {
        [$this->decaysAt, $this->remaining] = $this->getConnection()->eval(
            $this->tooManyAttemptsLuaScript(),
            [
                $this->name,
                microtime(true),
                time(),
                $this->decay,
                $this->maxLocks,
            ],
            1
        );

        return $this->remaining <= 0;
    }

    /**
     * Clear the limiter.
     */
    public function clear(): void
    {
        $this->getConnection()->del($this->name);
    }

    public function getConnection(): RedisProxy
    {
        return $this->redis->get($this->connection);
    }

    /**
     * Get the Lua script for acquiring a lock.
     *
     * KEYS[1] - The limiter name
     * ARGV[1] - Current time in microseconds
     * ARGV[2] - Current time in seconds
     * ARGV[3] - Duration of the bucket
     * ARGV[4] - Allowed number of tasks
     */
    protected function luaScript(): string
    {
        return <<<'LUA'
local function reset()
    redis.call('HMSET', KEYS[1], 'start', ARGV[2], 'end', ARGV[2] + ARGV[3], 'count', 1)
    return redis.call('EXPIRE', KEYS[1], ARGV[3] * 2)
end

if redis.call('EXISTS', KEYS[1]) == 0 then
    return {reset(), ARGV[2] + ARGV[3], ARGV[4] - 1}
end

if ARGV[1] >= redis.call('HGET', KEYS[1], 'start') and ARGV[1] <= redis.call('HGET', KEYS[1], 'end') then
    return {
        tonumber(redis.call('HINCRBY', KEYS[1], 'count', 1)) <= tonumber(ARGV[4]),
        redis.call('HGET', KEYS[1], 'end'),
        ARGV[4] - redis.call('HGET', KEYS[1], 'count')
    }
end

return {reset(), ARGV[2] + ARGV[3], ARGV[4] - 1}
LUA;
    }

    /**
     * Get the Lua script to determine if the key has been "accessed" too many times.
     *
     * KEYS[1] - The limiter name
     * ARGV[1] - Current time in microseconds
     * ARGV[2] - Current time in seconds
     * ARGV[3] - Duration of the bucket
     * ARGV[4] - Allowed number of tasks
     */
    protected function tooManyAttemptsLuaScript(): string
    {
        return <<<'LUA'

if redis.call('EXISTS', KEYS[1]) == 0 then
    return {0, ARGV[2] + ARGV[3]}
end

if ARGV[1] >= redis.call('HGET', KEYS[1], 'start') and ARGV[1] <= redis.call('HGET', KEYS[1], 'end') then
    return {
        redis.call('HGET', KEYS[1], 'end'),
        ARGV[4] - redis.call('HGET', KEYS[1], 'count')
    }
end

return {0, ARGV[2] + ARGV[3]}
LUA;
    }
}
