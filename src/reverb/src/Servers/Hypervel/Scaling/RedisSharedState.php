<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;

class RedisSharedState implements SharedState
{
    protected const string SUBSCRIPTION_KEY_TYPE = 's';

    protected const string USER_KEY_TYPE = 'u';

    protected const string CONNECTION_KEY_TYPE = 'c';

    protected const string SUBSCRIPTION_COUNT_LOCK_KEY_TYPE = 't';

    protected const string CACHE_MISS_LOCK_KEY_TYPE = 'm';

    protected const string CHANNEL_SMOOTHING_KEY_TYPE = 'h';

    protected const string MEMBER_SMOOTHING_KEY_TYPE = 'p';

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
        $channelKey = $this->channelKey($appId, $channel);

        if ($userId === null) {
            $channelCount = (int) $this->redis->incr($channelKey);
            $memberAdded = false;
        } else {
            [$channelCount, $userCount] = $this->redis->evalWithShaCache(
                $this->subscribePresenceScript(),
                [$channelKey, $this->userKey($appId, $channel, $userId)],
                [],
            );
            $channelCount = (int) $channelCount;
            $memberAdded = (int) $userCount === 1;
        }

        return new SubscriptionResult(
            channelOccupied: $channelCount === 1,
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
        $channelKey = $this->channelKey($appId, $channel);

        if ($userId === null) {
            $channelCount = (int) $this->redis->evalWithShaCache(
                $this->decrAndCleanupScript(),
                [$channelKey],
                [],
            );
            $memberRemoved = false;
        } else {
            [$channelCount, $userCount] = $this->redis->evalWithShaCache(
                $this->unsubscribePresenceScript(),
                [$channelKey, $this->userKey($appId, $channel, $userId)],
                [],
            );
            $channelCount = (int) $channelCount;
            $memberRemoved = (int) $userCount <= 0;
        }

        return new SubscriptionResult(
            channelOccupied: false,
            channelVacated: $channelCount <= 0,
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
        $key = $this->key(self::CONNECTION_KEY_TYPE, $appId);

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
        $key = $this->key(self::CONNECTION_KEY_TYPE, $appId);

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
        return (int) $this->redis->get($this->channelKey($appId, $channel));
    }

    /**
     * Get the current subscription count for a specific user in a channel.
     */
    public function getUserSubscriptionCount(string $appId, string $channel, string $userId): int
    {
        return (int) $this->redis->get($this->userKey($appId, $channel, $userId));
    }

    /**
     * Attempt to acquire a subscription_count webhook throttle lock.
     */
    public function trySubscriptionCountLock(string $appId, string $channel, int $ttlMs = 5000): bool
    {
        return $this->redis->set(
            $this->key(self::SUBSCRIPTION_COUNT_LOCK_KEY_TYPE, $appId, $channel),
            '1',
            'PX',
            $ttlMs,
            'NX',
        ) === true;
    }

    /**
     * Attempt to acquire a cache_miss webhook dedupe lock.
     */
    public function tryCacheMissLock(string $appId, string $channel, int $ttlMs = 10000): bool
    {
        return $this->redis->set(
            $this->key(self::CACHE_MISS_LOCK_KEY_TYPE, $appId, $channel),
            '1',
            'PX',
            $ttlMs,
            'NX',
        ) === true;
    }

    /**
     * Clear the cache_miss dedupe lock for a channel.
     */
    public function clearCacheMissLock(string $appId, string $channel): void
    {
        $this->redis->del($this->key(self::CACHE_MISS_LOCK_KEY_TYPE, $appId, $channel));
    }

    /**
     * Clear the subscription_count throttle lock for a channel.
     */
    public function clearSubscriptionCountLock(string $appId, string $channel): void
    {
        $this->redis->del($this->key(self::SUBSCRIPTION_COUNT_LOCK_KEY_TYPE, $appId, $channel));
    }

    /**
     * Mark a channel as having a pending deferred channel_vacated webhook.
     */
    public function setSmoothingPending(string $appId, string $channel, int $ttlMs): void
    {
        $this->redis->set(
            $this->key(self::CHANNEL_SMOOTHING_KEY_TYPE, $appId, $channel),
            '1',
            'PX',
            $ttlMs,
        );
    }

    /**
     * Atomically consume a channel smoothing marker if it is still live.
     */
    public function clearSmoothingPending(string $appId, string $channel, int $ttlMs): bool
    {
        return (int) $this->redis->del(
            $this->key(self::CHANNEL_SMOOTHING_KEY_TYPE, $appId, $channel),
        ) > 0;
    }

    /**
     * Mark a presence channel member as having a pending deferred member_removed webhook.
     */
    public function setMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): void
    {
        $this->redis->set(
            $this->key(self::MEMBER_SMOOTHING_KEY_TYPE, $appId, $channel, $userId),
            '1',
            'PX',
            $ttlMs,
        );
    }

    /**
     * Atomically consume a member smoothing marker if it is still live.
     */
    public function clearMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): bool
    {
        return (int) $this->redis->del(
            $this->key(self::MEMBER_SMOOTHING_KEY_TYPE, $appId, $channel, $userId),
        ) > 0;
    }

    /**
     * Lua script to atomically increment a presence channel and member.
     *
     * KEYS[1] - The channel counter key
     * KEYS[2] - The member counter key
     *
     * Returns the channel and member counts after incrementing.
     */
    protected function subscribePresenceScript(): string
    {
        return <<<'LUA'
            local channel_count = redis.call('INCR', KEYS[1])
            local user_count = redis.call('INCR', KEYS[2])
            return {channel_count, user_count}
        LUA;
    }

    /**
     * Lua script to atomically decrement and clean up a presence channel and member.
     *
     * KEYS[1] - The channel counter key
     * KEYS[2] - The member counter key
     *
     * Returns the channel and member counts after decrementing.
     */
    protected function unsubscribePresenceScript(): string
    {
        return <<<'LUA'
            local channel_count = redis.call('DECR', KEYS[1])
            if channel_count <= 0 then
                redis.call('DEL', KEYS[1])
            end

            local user_count = redis.call('DECR', KEYS[2])
            if user_count <= 0 then
                redis.call('DEL', KEYS[2])
            end

            return {channel_count, user_count}
        LUA;
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

    /**
     * Get a channel counter key.
     */
    protected function channelKey(string $appId, string $channel): string
    {
        return $this->key(self::SUBSCRIPTION_KEY_TYPE, $appId, $channel);
    }

    /**
     * Get a member counter key.
     */
    protected function userKey(string $appId, string $channel, string $userId): string
    {
        return $this->key(self::USER_KEY_TYPE, $appId, $channel, $userId);
    }

    /**
     * Get a Redis key for a canonical logical identity.
     */
    protected function key(string $type, string ...$parts): string
    {
        return 'reverb:' . $this->logicalKey($type, ...$parts);
    }

    /**
     * Get a canonical logical shared-state key.
     */
    protected function logicalKey(string $type, string ...$parts): string
    {
        return $type . '|' . implode('|', array_map(
            static fn (string $part): string => strlen($part) . ':' . $part,
            $parts,
        ));
    }
}
