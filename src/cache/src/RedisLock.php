<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hyperf\Redis\Redis;
use Hypervel\Cache\Contracts\RefreshableLock;

class RedisLock extends Lock implements RefreshableLock
{
    /**
     * The Redis factory implementation.
     */
    protected Redis $redis;

    /**
     * Create a new lock instance.
     */
    public function __construct(Redis $redis, string $name, int $seconds, ?string $owner = null)
    {
        parent::__construct($name, $seconds, $owner);

        $this->redis = $redis;
    }

    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        if ($this->seconds > 0) {
            return $this->redis->set($this->name, $this->owner, ['EX' => $this->seconds, 'NX']) == true;
        }

        return $this->redis->setnx($this->name, $this->owner) == true;
    }

    /**
     * Release the lock.
     *
     * Uses a Lua script to atomically check ownership before deleting.
     */
    public function release(): bool
    {
        $script = <<<'LUA'
if redis.call("get",KEYS[1]) == ARGV[1] then
    return redis.call("del",KEYS[1])
else
    return 0
end
LUA;

        return (bool) $this->redis->eval($script, [$this->name, $this->owner], 1);
    }

    /**
     * Releases this lock in disregard of ownership.
     */
    public function forceRelease(): void
    {
        $this->redis->del($this->name);
    }

    /**
     * Returns the owner value written into the driver for this lock.
     */
    protected function getCurrentOwner(): string
    {
        return $this->redis->get($this->name);
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * When seconds is zero or negative, the lock becomes permanent (no expiry).
     * Uses a Lua script to atomically check ownership before modifying TTL.
     */
    public function refresh(?int $seconds = null): bool
    {
        $seconds ??= $this->seconds;

        if ($seconds > 0) {
            $script = <<<'LUA'
if redis.call("get",KEYS[1]) == ARGV[1] then
    return redis.call("expire",KEYS[1],ARGV[2])
else
    return 0
end
LUA;

            return (bool) $this->redis->eval($script, [$this->name, $this->owner, $seconds], 1);
        }

        // For seconds <= 0, remove expiry (make permanent) if we own the lock
        $script = <<<'LUA'
if redis.call("get",KEYS[1]) == ARGV[1] then
    return redis.call("persist",KEYS[1])
else
    return 0
end
LUA;

        return (bool) $this->redis->eval($script, [$this->name, $this->owner], 1);
    }

    /**
     * Get the number of seconds until the lock expires.
     */
    public function getRemainingLifetime(): ?float
    {
        $ttl = $this->redis->ttl($this->name);

        // -2 = key doesn't exist, -1 = key has no expiry
        if ($ttl < 0) {
            return null;
        }

        return (float) $ttl;
    }
}
