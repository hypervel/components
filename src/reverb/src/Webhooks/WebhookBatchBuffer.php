<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks;

use Hypervel\Redis\RedisProxy;

class WebhookBatchBuffer
{
    public function __construct(
        protected RedisProxy $redis,
    ) {
    }

    /**
     * Append an event to the buffer and check if a flush should be scheduled.
     *
     * Uses a single Lua script for one Redis round-trip: RPUSH the event
     * data and SET NX the debounce lock. Returns true if the lock was
     * newly acquired (caller should schedule a flush job).
     */
    public function appendAndCheckSchedule(string $appId, array $eventData): bool
    {
        $bufferKey = "reverb:webhook:buffer:{$appId}";
        $lockKey = "reverb:webhook:flush:{$appId}";

        return (bool) $this->redis->evalWithShaCache(
            $this->appendAndLockScript(),
            [$bufferKey, $lockKey],
            [json_encode($eventData, JSON_THROW_ON_ERROR), 30000],
        );
    }

    /**
     * Atomically claim events from the buffer into a processing hash.
     *
     * A single Lua script handles everything in one Redis round-trip:
     * - Guards against concurrent flushes (returns empty if processing hash exists)
     * - Claims up to $maxEvents from the buffer
     * - Applies $maxPayloadBytes limit, pushing overflow back to the buffer
     * - Stores only the retained events + timestamp in the processing hash
     *
     * Returns the retained events as decoded arrays. Empty return means either
     * the buffer is empty or another flush is in-flight.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claim(string $appId, int $maxEvents, int $maxPayloadBytes): array
    {
        $bufferKey = "reverb:webhook:buffer:{$appId}";
        $processingKey = "reverb:webhook:processing:{$appId}";

        $rawEvents = $this->redis->evalWithShaCache(
            $this->claimScript(),
            [$bufferKey, $processingKey],
            [$maxEvents, $maxPayloadBytes, 100],
        );

        if (empty($rawEvents)) {
            return [];
        }

        return array_map(
            fn (string $raw) => json_decode($raw, true, 512, JSON_THROW_ON_ERROR),
            $rawEvents,
        );
    }

    /**
     * Recover stale processing keys from crashed flush jobs.
     *
     * Uses an atomic Lua script so multiple workers calling this
     * concurrently don't duplicate events — the first worker recovers
     * the events, the rest no-op because the key is already deleted.
     *
     * Returns true if events were recovered (caller should schedule a flush).
     */
    public function recoverStaleProcessingKeys(string $appId, int $maxAgeSeconds = 60): bool
    {
        $processingKey = "reverb:webhook:processing:{$appId}";
        $bufferKey = "reverb:webhook:buffer:{$appId}";

        return (bool) $this->redis->evalWithShaCache(
            $this->recoverScript(),
            [$processingKey, $bufferKey],
            [(string) $maxAgeSeconds],
        );
    }

    /**
     * Acknowledge successful processing — delete the processing key.
     */
    public function acknowledge(string $appId): void
    {
        $this->redis->del("reverb:webhook:processing:{$appId}");
    }

    /**
     * Check if the buffer has remaining items.
     */
    public function hasRemaining(string $appId): bool
    {
        return (int) $this->redis->llen("reverb:webhook:buffer:{$appId}") > 0;
    }

    /**
     * Clear the debounce lock so a new flush can be scheduled.
     */
    public function clearFlushLock(string $appId): void
    {
        $this->redis->del("reverb:webhook:flush:{$appId}");
    }

    /**
     * Lua script: RPUSH event data + SET NX debounce lock.
     *
     * KEYS[1] = buffer list key
     * KEYS[2] = lock key
     * ARGV[1] = JSON event data
     * ARGV[2] = lock TTL in milliseconds
     *
     * Returns 1 if lock was newly acquired, 0 if already held.
     */
    protected function appendAndLockScript(): string
    {
        return <<<'LUA'
            redis.call('RPUSH', KEYS[1], ARGV[1])
            return redis.call('SET', KEYS[2], '1', 'NX', 'PX', ARGV[2]) and 1 or 0
        LUA;
    }

    /**
     * Lua script: atomically claim, trim by byte budget, and store in processing hash.
     *
     * KEYS[1] = buffer list key
     * KEYS[2] = processing hash key
     * ARGV[1] = max events to claim
     * ARGV[2] = max payload bytes
     * ARGV[3] = envelope overhead bytes
     *
     * Uses redis.call('TIME') for claimed_at so all nodes sharing Redis
     * use the same clock source for staleness detection.
     *
     * Guards against concurrent flushes: returns empty if processing hash exists.
     * Claims up to max events, applies byte budget, pushes overflow back to the
     * buffer, and stores only the retained events in the processing hash.
     * Returns the retained event strings.
     */
    protected function claimScript(): string
    {
        return <<<'LUA'
            if redis.call('EXISTS', KEYS[2]) == 1 then
                return {}
            end

            local count = tonumber(ARGV[1])
            local maxBytes = tonumber(ARGV[2])
            local totalBytes = tonumber(ARGV[3])

            local len = redis.call('LLEN', KEYS[1])
            if len == 0 then
                return {}
            end

            local actual = math.min(count, len)
            local claimed = redis.call('LRANGE', KEYS[1], 0, actual - 1)
            redis.call('LTRIM', KEYS[1], actual, -1)

            local retained = {}
            local overflow = {}

            for i, raw in ipairs(claimed) do
                local eventBytes = string.len(raw) + 1

                if (totalBytes + eventBytes > maxBytes) and (#retained > 0) then
                    table.insert(overflow, raw)
                else
                    totalBytes = totalBytes + eventBytes
                    table.insert(retained, raw)
                end
            end

            for i = #overflow, 1, -1 do
                redis.call('LPUSH', KEYS[1], overflow[i])
            end

            if #retained > 0 then
                local now = redis.call('TIME')[1]
                redis.call('HSET', KEYS[2], 'events', cjson.encode(retained), 'claimed_at', now)
            end

            return retained
        LUA;
    }

    /**
     * Lua script: atomically recover stale processing events to the buffer.
     *
     * KEYS[1] = processing hash key
     * KEYS[2] = buffer list key
     * ARGV[1] = max age in seconds
     *
     * Uses redis.call('TIME') for the current timestamp so all nodes
     * sharing Redis use the same clock source as the claim script.
     *
     * If the processing hash exists and claimed_at is older than max age,
     * pushes all events back to the front of the buffer and deletes the
     * hash. Returns 1 if recovered, 0 if no-op. Safe for concurrent calls.
     */
    protected function recoverScript(): string
    {
        return <<<'LUA'
            local claimedAt = redis.call('HGET', KEYS[1], 'claimed_at')
            if not claimedAt then
                return 0
            end

            local maxAge = tonumber(ARGV[1])
            local now = tonumber(redis.call('TIME')[1])
            if (now - tonumber(claimedAt)) < maxAge then
                return 0
            end

            local eventsJson = redis.call('HGET', KEYS[1], 'events')
            if eventsJson then
                local events = cjson.decode(eventsJson)
                for i = #events, 1, -1 do
                    redis.call('LPUSH', KEYS[2], events[i])
                end
            end

            redis.call('DEL', KEYS[1])
            return 1
        LUA;
    }
}
