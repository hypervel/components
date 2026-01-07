<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hyperf\Redis\Redis;
use Hypervel\Cache\Contracts\RefreshableLock;
use InvalidArgumentException;

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
     */
    public function release(): bool
    {
        return (bool) $this->redis->eval(LuaScripts::releaseLock(), [$this->name, $this->owner], 1);
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
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        // Permanent lock with no explicit TTL requested - nothing to refresh
        if ($seconds === null && $this->seconds <= 0) {
            return true;
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                'Refresh requires a positive TTL. For a permanent lock, acquire it with seconds=0.'
            );
        }

        return (bool) $this->redis->eval(LuaScripts::refreshLock(), [$this->name, $this->owner, $seconds], 1);
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
