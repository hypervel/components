<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;

class RedisSharedState implements SharedState
{
    /**
     * Create a new Redis shared state instance.
     */
    public function __construct(
        protected RedisProxy $redis,
    ) {
    }

    /**
     * Record a channel subscription and return the transition result.
     */
    public function subscribe(string $appId, string $channel, ?string $userId = null): SubscriptionResult
    {
        $channelKey = "reverb:sub:{$appId}:{$channel}";
        $channelCount = (int) $this->redis->incr($channelKey);
        $channelOccupied = ($channelCount === 1);

        $memberAdded = false;
        if ($userId !== null) {
            $userKey = "reverb:user:{$appId}:{$channel}:{$userId}";
            $userCount = (int) $this->redis->incr($userKey);
            $memberAdded = ($userCount === 1);
        }

        return new SubscriptionResult(
            channelOccupied: $channelOccupied,
            channelVacated: false,
            memberAdded: $memberAdded,
            memberRemoved: false,
            subscriptionCount: $channelCount,
        );
    }

    /**
     * Record a channel unsubscription and return the transition result.
     *
     * Uses Lua scripts for atomic DECR + conditional DEL to prevent a
     * race where a concurrent INCR between the DECR and DEL would be lost.
     */
    public function unsubscribe(string $appId, string $channel, ?string $userId = null): SubscriptionResult
    {
        $channelKey = "reverb:sub:{$appId}:{$channel}";
        $channelCount = (int) $this->redis->evalWithShaCache(
            $this->decrAndCleanupScript(),
            [$channelKey],
            [],
        );
        $channelVacated = ($channelCount <= 0);

        $memberRemoved = false;
        if ($userId !== null) {
            $userKey = "reverb:user:{$appId}:{$channel}:{$userId}";
            $userCount = (int) $this->redis->evalWithShaCache(
                $this->decrAndCleanupScript(),
                [$userKey],
                [],
            );
            $memberRemoved = ($userCount <= 0);
        }

        return new SubscriptionResult(
            channelOccupied: false,
            channelVacated: $channelVacated,
            memberAdded: false,
            memberRemoved: $memberRemoved,
            subscriptionCount: max(0, $channelCount),
        );
    }

    /**
     * Attempt to acquire a connection slot for the given app.
     *
     * Uses a Lua script for atomic increment + limit check + rollback.
     */
    public function acquireConnectionSlot(string $appId, int $maxConnections): bool
    {
        $key = "reverb:conn:{$appId}";

        return (bool) $this->redis->evalWithShaCache(
            $this->acquireSlotScript(),
            [$key],
            [$maxConnections],
        );
    }

    /**
     * Release a connection slot for the given app.
     */
    public function releaseConnectionSlot(string $appId): void
    {
        $key = "reverb:conn:{$appId}";

        $this->redis->evalWithShaCache(
            $this->decrAndCleanupScript(),
            [$key],
            [],
        );
    }

    /**
     * Get the current subscription count for a channel.
     */
    public function getSubscriptionCount(string $appId, string $channel): int
    {
        return (int) $this->redis->get("reverb:sub:{$appId}:{$channel}");
    }

    /**
     * Get the current subscription count for a specific user in a channel.
     */
    public function getUserSubscriptionCount(string $appId, string $channel, string $userId): int
    {
        return (int) $this->redis->get("reverb:user:{$appId}:{$channel}:{$userId}");
    }

    /**
     * Attempt to acquire a subscription_count webhook throttle lock.
     */
    public function trySubscriptionCountLock(string $appId, string $channel, int $ttlMs = 5000): bool
    {
        return $this->redis->set("reverb:subcount-lock:{$appId}:{$channel}", '1', 'PX', $ttlMs, 'NX') == true;
    }

    /**
     * Attempt to acquire a cache_miss webhook dedupe lock.
     */
    public function tryCacheMissLock(string $appId, string $channel, int $ttlMs = 10000): bool
    {
        return $this->redis->set("reverb:cache-miss-lock:{$appId}:{$channel}", '1', 'PX', $ttlMs, 'NX') == true;
    }

    /**
     * Clear the cache_miss dedupe lock for a channel.
     */
    public function clearCacheMissLock(string $appId, string $channel): void
    {
        $this->redis->del("reverb:cache-miss-lock:{$appId}:{$channel}");
    }

    /**
     * Clear the subscription_count throttle lock for a channel.
     */
    public function clearSubscriptionCountLock(string $appId, string $channel): void
    {
        $this->redis->del("reverb:subcount-lock:{$appId}:{$channel}");
    }

    /**
     * Mark a channel as having a pending deferred channel_vacated webhook.
     */
    public function setSmoothingPending(string $appId, string $channel, int $ttlMs): void
    {
        $this->redis->set("reverb:smoothing:{$appId}:{$channel}", '1', 'PX', $ttlMs);
    }

    /**
     * Atomically consume a channel smoothing marker if it is still live.
     */
    public function clearSmoothingPending(string $appId, string $channel, int $ttlMs): bool
    {
        return (int) $this->redis->del("reverb:smoothing:{$appId}:{$channel}") > 0;
    }

    /**
     * Mark a presence channel member as having a pending deferred member_removed webhook.
     */
    public function setMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): void
    {
        $this->redis->set("reverb:smoothing:{$appId}:{$channel}:{$userId}", '1', 'PX', $ttlMs);
    }

    /**
     * Atomically consume a member smoothing marker if it is still live.
     */
    public function clearMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): bool
    {
        return (int) $this->redis->del("reverb:smoothing:{$appId}:{$channel}:{$userId}") > 0;
    }

    /**
     * Lua script to atomically decrement a counter and delete the key if it reaches zero.
     *
     * KEYS[1] - The counter key
     *
     * Returns the new count after decrement.
     */
    protected function decrAndCleanupScript(): string
    {
        return <<<'LUA'
            local count = redis.call('DECR', KEYS[1])
            if count <= 0 then
                redis.call('DEL', KEYS[1])
            end
            return count
        LUA;
    }

    /**
     * Lua script to atomically acquire a connection slot.
     *
     * Increments the counter and checks against the limit. If over limit,
     * rolls back the increment and returns 0 (false). Otherwise returns 1 (true).
     *
     * KEYS[1] - The connection counter key
     * ARGV[1] - Maximum allowed connections
     */
    protected function acquireSlotScript(): string
    {
        return <<<'LUA'
            local count = redis.call('INCR', KEYS[1])
            if count > tonumber(ARGV[1]) then
                redis.call('DECR', KEYS[1])
                return 0
            end
            return 1
        LUA;
    }
}
