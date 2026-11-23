<?php

declare(strict_types=1);

namespace Hypervel\Redis\Limiters;

use Hypervel\Contracts\Limiters\LimiterTimeoutException;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Sleep;

use function Hypervel\Support\now;

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
     * @param RedisProxy $redis the Redis connection instance
     * @param string $name the unique name of the lock
     * @param int $maxLocks the allowed number of concurrent tasks
     * @param int $decay the number of seconds a slot should be maintained
     */
    public function __construct(
        protected RedisProxy $redis,
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
        $deadline = (int) now()->format('Uu') + $timeout * 1_000_000;

        while (! $this->acquire()) {
            $remaining = $deadline - (int) now()->format('Uu');

            if ($remaining <= 0) {
                throw new LimiterTimeoutException;
            }

            ConnectionResolver::releaseIdleConnections();
            // Allow a final attempt without sleeping beyond the remaining wait.
            Sleep::usleep(min($sleep * 1000, $remaining));
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
        $results = $this->redis->withConnection(
            fn (RedisConnection $connection): mixed => $connection->evalWithShaCache(
                $this->luaScript(),
                [$this->name],
                [microtime(true), time(), $this->decay, $this->maxLocks],
            ),
        );

        $this->decaysAt = (int) $results[1];

        // Lua numbers lose precision above 2^53, so remaining is calculated here.
        $this->remaining = max(0, $this->maxLocks - (int) $results[2]);

        return (bool) $results[0];
    }

    /**
     * Determine if the key has been "accessed" too many times.
     */
    public function tooManyAttempts(): bool
    {
        $results = $this->redis->withConnection(
            fn (RedisConnection $connection): mixed => $connection->evalWithShaCache(
                $this->tooManyAttemptsLuaScript(),
                [$this->name],
                [microtime(true), time(), $this->decay, $this->maxLocks],
            ),
        );

        $this->decaysAt = (int) $results[0];
        $this->remaining = max(0, $this->maxLocks - (int) $results[1]);

        return $this->remaining <= 0;
    }

    /**
     * Clear the limiter.
     */
    public function clear(): void
    {
        $this->redis->del($this->name);
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
    return {reset(), ARGV[2] + ARGV[3], 1}
end

if ARGV[1] >= redis.call('HGET', KEYS[1], 'start') and ARGV[1] <= redis.call('HGET', KEYS[1], 'end') then
    return {
        tonumber(redis.call('HINCRBY', KEYS[1], 'count', 1)) <= tonumber(ARGV[4]),
        redis.call('HGET', KEYS[1], 'end'),
        redis.call('HGET', KEYS[1], 'count')
    }
end

return {reset(), ARGV[2] + ARGV[3], 1}
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
    return {ARGV[2] + ARGV[3], 0}
end

if ARGV[1] >= redis.call('HGET', KEYS[1], 'start') and ARGV[1] <= redis.call('HGET', KEYS[1], 'end') then
    return {
        redis.call('HGET', KEYS[1], 'end'),
        redis.call('HGET', KEYS[1], 'count')
    }
end

return {ARGV[2] + ARGV[3], 0}
LUA;
    }
}
