<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks;

use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Str;

class WebhookBatchBuffer
{
    /**
     * The age in seconds after which a claimed batch is treated as abandoned.
     */
    protected const int CLAIM_TIMEOUT_SECONDS = 60;

    /**
     * The lifetime in milliseconds of the key that marks a flush as scheduled.
     */
    protected const int FLUSH_LOCK_MILLISECONDS = 30000;

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
        $tag = $this->appHashTag($appId);
        $bufferKey = "reverb:webhook:{{$tag}}:buffer";
        $lockKey = "reverb:webhook:{{$tag}}:flush";

        return (bool) $this->redis->evalWithShaCache(
            $this->appendAndLockScript(),
            [$bufferKey, $lockKey],
            [json_encode($eventData, JSON_THROW_ON_ERROR), self::FLUSH_LOCK_MILLISECONDS],
        );
    }

    /**
     * Atomically claim a batch of events from the buffer into a processing hash.
     *
     * The batch's webhook ID, time and events are stored with a claim token.
     * A batch claimed longer ago than the claim timeout is taken over with a
     * new token and its stored identity, so a re-sent batch has the same body
     * and receivers can recognize it. Returns null when the buffer is empty or
     * another flush holds a current claim.
     *
     * @return null|array{token: string, payload: WebhookPayload}
     */
    public function claim(string $appId, int $maxEvents, int $maxPayloadBytes): ?array
    {
        $tag = $this->appHashTag($appId);
        $token = Str::random();

        $batch = $this->redis->evalWithShaCache(
            $this->claimScript(),
            ["reverb:webhook:{{$tag}}:buffer", "reverb:webhook:{{$tag}}:processing"],
            [
                $maxEvents,
                $maxPayloadBytes,
                100,
                self::CLAIM_TIMEOUT_SECONDS,
                $token,
                (string) Str::orderedUuid(),
                (int) (microtime(true) * 1000),
            ],
        );

        if ($batch === []) {
            return null;
        }

        return [
            'token' => $token,
            'payload' => new WebhookPayload(
                webhookId: $batch[0],
                timeMs: (int) $batch[1],
                events: json_decode($batch[2], true, 512, JSON_THROW_ON_ERROR),
            ),
        ];
    }

    /**
     * Determine whether a flush job should be scheduled for recovery.
     *
     * A flush is needed for a batch claimed longer ago than the claim timeout,
     * or for buffered events that no flush has claimed. The scheduling key is
     * taken atomically, so only one of the workers checking at once schedules it.
     */
    public function shouldScheduleFlush(string $appId): bool
    {
        $tag = $this->appHashTag($appId);

        return (bool) $this->redis->evalWithShaCache(
            $this->scheduleFlushScript(),
            [
                "reverb:webhook:{{$tag}}:processing",
                "reverb:webhook:{{$tag}}:buffer",
                "reverb:webhook:{{$tag}}:flush",
            ],
            [self::CLAIM_TIMEOUT_SECONDS, self::FLUSH_LOCK_MILLISECONDS],
        );
    }

    /**
     * Acknowledge a sent batch by deleting its processing hash.
     *
     * The hash is kept when another flush has since taken the batch over.
     */
    public function acknowledge(string $appId, string $token): void
    {
        $tag = $this->appHashTag($appId);

        $this->redis->evalWithShaCache(
            $this->acknowledgeScript(),
            ["reverb:webhook:{{$tag}}:processing"],
            [$token],
        );
    }

    /**
     * Check if the buffer has remaining items.
     */
    public function hasRemaining(string $appId): bool
    {
        $tag = $this->appHashTag($appId);

        return (int) $this->redis->llen("reverb:webhook:{{$tag}}:buffer") > 0;
    }

    /**
     * Clear the debounce lock so a new flush can be scheduled.
     */
    public function clearFlushLock(string $appId): void
    {
        $tag = $this->appHashTag($appId);

        $this->redis->del("reverb:webhook:{{$tag}}:flush");
    }

    /**
     * Build the Redis Cluster hash tag for an application.
     */
    protected function appHashTag(string $appId): string
    {
        return hash('xxh128', 'app|' . strlen($appId) . ':' . $appId);
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
     * Lua script: atomically claim a batch, or take over an abandoned one.
     *
     * KEYS[1] = buffer list key
     * KEYS[2] = processing hash key
     * ARGV[1] = max events to claim
     * ARGV[2] = max payload bytes
     * ARGV[3] = envelope overhead bytes
     * ARGV[4] = claim timeout in seconds
     * ARGV[5] = claim token
     * ARGV[6] = webhook ID for a new batch
     * ARGV[7] = time in milliseconds for a new batch
     *
     * Uses redis.call('TIME') for claimed_at so all nodes sharing Redis
     * use the same clock source for staleness detection.
     *
     * A current claim returns empty, and an abandoned one gets the new token and
     * keeps its stored batch. Otherwise the batch is the longest prefix of the
     * buffer within the event and byte limits, keeping the buffer's order; an
     * oversized first event is still taken so the buffer always progresses.
     * Returns the webhook ID, time and JSON events array of the claimed batch.
     */
    protected function claimScript(): string
    {
        return <<<'LUA'
            local now = tonumber(redis.call('TIME')[1])
            local claimedAt = redis.call('HGET', KEYS[2], 'claimed_at')

            if claimedAt then
                if now - tonumber(claimedAt) < tonumber(ARGV[4]) then
                    return {}
                end

                redis.call('HSET', KEYS[2], 'token', ARGV[5], 'claimed_at', now)

                return redis.call('HMGET', KEYS[2], 'webhook_id', 'time_ms', 'events')
            end

            local maxBytes = tonumber(ARGV[2])
            local totalBytes = tonumber(ARGV[3])
            local candidates = redis.call('LRANGE', KEYS[1], 0, tonumber(ARGV[1]) - 1)
            local retained = {}

            for _, raw in ipairs(candidates) do
                local eventBytes = string.len(raw) + 1

                if totalBytes + eventBytes > maxBytes and #retained > 0 then
                    break
                end

                totalBytes = totalBytes + eventBytes
                table.insert(retained, raw)
            end

            if #retained == 0 then
                return {}
            end

            redis.call('LTRIM', KEYS[1], #retained, -1)

            local events = '[' .. table.concat(retained, ',') .. ']'
            redis.call('HSET', KEYS[2], 'token', ARGV[5], 'webhook_id', ARGV[6], 'time_ms', ARGV[7], 'events', events, 'claimed_at', now)

            return {ARGV[6], ARGV[7], events}
        LUA;
    }

    /**
     * Lua script: decide whether recovery should schedule a flush.
     *
     * KEYS[1] = processing hash key
     * KEYS[2] = buffer list key
     * KEYS[3] = lock key
     * ARGV[1] = claim timeout in seconds
     * ARGV[2] = lock TTL in milliseconds
     *
     * A claimed batch needs a flush once its claim is older than the timeout,
     * and unclaimed events need one whenever the buffer is non-empty. Returns 1
     * if the lock was newly acquired for that flush, 0 otherwise.
     */
    protected function scheduleFlushScript(): string
    {
        return <<<'LUA'
            local claimedAt = redis.call('HGET', KEYS[1], 'claimed_at')

            if claimedAt then
                if tonumber(redis.call('TIME')[1]) - tonumber(claimedAt) < tonumber(ARGV[1]) then
                    return 0
                end
            elseif redis.call('LLEN', KEYS[2]) == 0 then
                return 0
            end

            return redis.call('SET', KEYS[3], '1', 'NX', 'PX', ARGV[2]) and 1 or 0
        LUA;
    }

    /**
     * Lua script: delete the processing hash if the token still owns it.
     *
     * KEYS[1] = processing hash key
     * ARGV[1] = claim token
     */
    protected function acknowledgeScript(): string
    {
        return <<<'LUA'
            if redis.call('HGET', KEYS[1], 'token') == ARGV[1] then
                return redis.call('DEL', KEYS[1])
            end

            return 0
        LUA;
    }
}
