<?php

declare(strict_types=1);

namespace Hypervel\Redis\Limiters;

use Hypervel\Contracts\Limiters\RefreshableLease;
use Hypervel\Redis\LuaScripts;
use Hypervel\Redis\RedisProxy;
use InvalidArgumentException;

/**
 * A held slot in a Redis funnel limiter.
 *
 * The owner id is stored and compared raw, never packed: the slot value is
 * written by the acquire Lua script, compared raw by release/refresh scripts,
 * and read raw during permanent-slot ownership checks. RedisLock writes
 * through set(), so its owner must be packed before Lua comparisons; funnel
 * leases do not.
 */
class ConcurrencyLease implements RefreshableLease
{
    /**
     * Create a new lease instance.
     */
    public function __construct(
        protected RedisProxy $redis,
        protected string $key,
        protected string $owner,
        protected int $releaseAfter,
    ) {
    }

    /**
     * Release the held slot if still owned by this lease.
     */
    public function release(): bool
    {
        return (bool) $this->redis->eval(LuaScripts::releaseLock(), 1, $this->key, $this->owner);
    }

    /**
     * Get the owner identifier of this lease.
     */
    public function owner(): string
    {
        return $this->owner;
    }

    /**
     * Refresh the lease's TTL if still owned by this lease.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        if ($seconds === null && $this->releaseAfter <= 0) {
            return $this->redis->withoutSerializationOrCompression(
                fn (): bool => $this->redis->get($this->key) === $this->owner
            );
        }

        $seconds ??= $this->releaseAfter;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('Refresh requires a positive TTL.');
        }

        return (bool) $this->redis->eval(LuaScripts::refreshLock(), 1, $this->key, $this->owner, $seconds);
    }

    /**
     * Get the number of seconds until the lease expires.
     */
    public function getRemainingLifetime(): ?float
    {
        $ttl = $this->redis->ttl($this->key);

        if ($ttl < 0) {
            return null;
        }

        return (float) $ttl;
    }
}
