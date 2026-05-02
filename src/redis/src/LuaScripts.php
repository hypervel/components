<?php

declare(strict_types=1);

namespace Hypervel\Redis;

/**
 * Shared Redis Lua scripts for lock and limiter primitives.
 */
class LuaScripts
{
    /**
     * Get the Lua script to atomically release a lock.
     *
     * KEYS[1] - The name of the lock
     * ARGV[1] - The owner key of the lock instance trying to release it
     */
    public static function releaseLock(): string
    {
        return <<<'LUA'
if redis.call("get",KEYS[1]) == ARGV[1] then
    return redis.call("del",KEYS[1])
else
    return 0
end
LUA;
    }

    /**
     * Get the Lua script to atomically refresh a lock's TTL.
     *
     * KEYS[1] - The name of the lock
     * ARGV[1] - The owner key of the lock instance trying to refresh it
     * ARGV[2] - The new TTL in seconds
     */
    public static function refreshLock(): string
    {
        return <<<'LUA'
if redis.call("get",KEYS[1]) == ARGV[1] then
    return redis.call("expire",KEYS[1],ARGV[2])
else
    return 0
end
LUA;
    }

    /**
     * Get the Lua script for atomically acquiring a free concurrency-limiter slot.
     *
     * KEYS    - The prefixed slot keys to consider (one per limit)
     * ARGV[1] - The unprefixed limiter name (used to construct the return value)
     * ARGV[2] - The number of seconds the slot should be reserved (0 = permanent, no TTL)
     * ARGV[3] - The unique identifier (owner) for this lock
     *
     * Returns the UNPREFIXED slot name (e.g. "my-funnel1") on success, nil otherwise.
     * The unprefixed return is required so the caller can pass it to RedisStore::restoreLock()
     * which prepends the prefix exactly once.
     *
     * For ARGV[2] <= 0 the script writes the slot key without an EX expiration, matching
     * RedisLock::acquire()'s "seconds <= 0 means permanent" semantic. Sending EX 0 to Redis
     * would error with "invalid expire time in 'set' command".
     */
    public static function acquireConcurrencySlot(): string
    {
        return <<<'LUA'
for index, value in pairs(redis.call('mget', unpack(KEYS))) do
    if not value then
        if tonumber(ARGV[2]) > 0 then
            redis.call('set', KEYS[index], ARGV[3], "EX", ARGV[2])
        else
            redis.call('set', KEYS[index], ARGV[3])
        end
        return ARGV[1]..index
    end
end
LUA;
    }
}
