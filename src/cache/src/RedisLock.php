<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hyperf\Redis\Redis;

class RedisLock extends Lock
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
        return (bool) $this->redis->eval(
            <<<'LUA'
                if redis.call("get",KEYS[1]) == ARGV[1] then
                    return redis.call("del",KEYS[1])
                else
                    return 0
                end
                LUA,
            [$this->name, $this->owner],
            1
        );
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
}
