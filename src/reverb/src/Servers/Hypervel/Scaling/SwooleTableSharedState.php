<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

use ErrorException;
use Hypervel\Reverb\Loggers\Log;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use RuntimeException;
use Swoole\Atomic;
use Swoole\Table;

class SwooleTableSharedState implements SharedState
{
    /**
     * Number of striped locks for inter-worker row lifecycle protection.
     */
    protected const STRIPE_COUNT = 64;

    /**
     * Striped Atomic locks for inter-worker row lifecycle operations.
     *
     * Prevents races where one worker's decr+del interleaves with another
     * worker's ensureRowExists+incr on the same key. Created before fork
     * so they're shared across all workers via shared memory.
     *
     * @var list<Atomic>
     */
    protected array $locks;

    /**
     * Create a new Swoole Table shared state instance.
     *
     * Must be created before fork (via instance(), not singleton()) so
     * both the Table and the Atomic locks are in shared memory.
     *
     * @param Table $table Main counter table (subscription counts, connection slots)
     * @param Table $lockTable Webhook throttle/dedupe lock table (timestamp-based TTLs)
     */
    public function __construct(
        protected Table $table,
        protected Table $lockTable,
    ) {
        $this->locks = array_map(
            fn () => new Atomic(0),
            range(0, self::STRIPE_COUNT - 1),
        );
    }

    /**
     * Record a channel subscription and return the transition result.
     */
    public function subscribe(string $appId, string $channel, ?string $userId = null): SubscriptionResult
    {
        $channelKey = "sub:{$appId}:{$channel}";
        $newCount = $this->atomicIncr($channelKey);
        $channelOccupied = ($newCount === 1);

        $memberAdded = false;
        if ($userId !== null) {
            $userKey = "user:{$appId}:{$channel}:{$userId}";
            $userCount = $this->atomicIncr($userKey);
            $memberAdded = ($userCount === 1);
        }

        return new SubscriptionResult(
            channelOccupied: $channelOccupied,
            channelVacated: false,
            memberAdded: $memberAdded,
            memberRemoved: false,
            subscriptionCount: $newCount,
        );
    }

    /**
     * Record a channel unsubscription and return the transition result.
     */
    public function unsubscribe(string $appId, string $channel, ?string $userId = null): SubscriptionResult
    {
        $channelKey = "sub:{$appId}:{$channel}";
        $newCount = $this->atomicDecrAndCleanup($channelKey);
        $channelVacated = ($newCount <= 0);

        $memberRemoved = false;
        if ($userId !== null) {
            $userKey = "user:{$appId}:{$channel}:{$userId}";
            $userCount = $this->atomicDecrAndCleanup($userKey);
            $memberRemoved = ($userCount <= 0);
        }

        return new SubscriptionResult(
            channelOccupied: false,
            channelVacated: $channelVacated,
            memberAdded: false,
            memberRemoved: $memberRemoved,
            subscriptionCount: max(0, $newCount),
        );
    }

    /**
     * Attempt to acquire a connection slot for the given app.
     */
    public function acquireConnectionSlot(string $appId, int $maxConnections): bool
    {
        $key = "conn:{$appId}";
        $newCount = $this->atomicIncr($key);

        if ($newCount > $maxConnections) {
            $this->atomicDecrAndCleanup($key);

            return false;
        }

        return true;
    }

    /**
     * Release a connection slot for the given app.
     */
    public function releaseConnectionSlot(string $appId): void
    {
        $key = "conn:{$appId}";

        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            if (! $this->table->exists($key)) {
                return;
            }

            $newCount = $this->table->decr($key, 'count', 1);

            if ($newCount <= 0) {
                $this->table->del($key);
            }
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Get the underlying Swoole Table instance.
     */
    public function table(): Table
    {
        return $this->table;
    }

    /**
     * Get the webhook lock Swoole Table instance.
     */
    public function lockTable(): Table
    {
        return $this->lockTable;
    }

    /**
     * Get the current subscription count for a channel.
     */
    public function getSubscriptionCount(string $appId, string $channel): int
    {
        $row = $this->table->get("sub:{$appId}:{$channel}", 'count');

        return $row !== false ? (int) $row : 0;
    }

    /**
     * Get the current subscription count for a specific user in a channel.
     */
    public function getUserSubscriptionCount(string $appId, string $channel, string $userId): int
    {
        $row = $this->table->get("user:{$appId}:{$channel}:{$userId}", 'count');

        return $row !== false ? (int) $row : 0;
    }

    /**
     * Attempt to acquire a subscription_count webhook throttle lock.
     */
    public function trySubscriptionCountLock(string $appId, string $channel, int $ttlMs = 5000): bool
    {
        return $this->tryLock("subcount-lock:{$appId}:{$channel}", $ttlMs);
    }

    /**
     * Attempt to acquire a cache_miss webhook dedupe lock.
     */
    public function tryCacheMissLock(string $appId, string $channel, int $ttlMs = 10000): bool
    {
        return $this->tryLock("cache-miss-lock:{$appId}:{$channel}", $ttlMs);
    }

    /**
     * Clear the cache_miss dedupe lock for a channel.
     */
    public function clearCacheMissLock(string $appId, string $channel): void
    {
        $key = "cache-miss-lock:{$appId}:{$channel}";
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            $this->lockTable->del($key);
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Clear the subscription_count throttle lock for a channel.
     */
    public function clearSubscriptionCountLock(string $appId, string $channel): void
    {
        $key = "subcount-lock:{$appId}:{$channel}";
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            $this->lockTable->del($key);
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Mark a channel as having a pending deferred channel_vacated webhook.
     */
    public function setSmoothingPending(string $appId, string $channel, int $ttlMs): void
    {
        $key = "smoothing:{$appId}:{$channel}";
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            $this->setLockRow($key, microtime(true));
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Atomically consume a channel smoothing marker if it is still live.
     */
    public function clearSmoothingPending(string $appId, string $channel, int $ttlMs): bool
    {
        return $this->consumeMarker("smoothing:{$appId}:{$channel}", $ttlMs);
    }

    /**
     * Mark a presence channel member as having a pending deferred member_removed webhook.
     */
    public function setMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): void
    {
        $key = "smoothing:{$appId}:{$channel}:{$userId}";
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            $this->setLockRow($key, microtime(true));
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Atomically consume a member smoothing marker if it is still live.
     */
    public function clearMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): bool
    {
        return $this->consumeMarker("smoothing:{$appId}:{$channel}:{$userId}", $ttlMs);
    }

    /**
     * Attempt to acquire a timestamp-based lock in the lock table.
     *
     * Uses the same striped Atomic locks for inter-worker safety.
     * If the lock row doesn't exist or the timestamp has expired,
     * the lock is (re-)acquired. Otherwise returns false.
     */
    protected function tryLock(string $key, int $ttlMs): bool
    {
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            $row = $this->lockTable->get($key, 'locked_at');
            $now = microtime(true);

            if ($row !== false && ($now - (float) $row) < ($ttlMs / 1000.0)) {
                return false;
            }

            return $this->setLockRow($key, $now);
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Write a timestamp row to the lock table, handling table-full failures.
     *
     * Returns true if the row was written, false if the table is full.
     * Must be called while holding the stripe lock for the key.
     */
    protected function setLockRow(string $key, float $timestamp): bool
    {
        try {
            $result = $this->lockTable->set($key, ['locked_at' => $timestamp]);
        } catch (ErrorException $e) {
            Log::error(
                "Reverb webhook lock table is full — increase 'reverb.swoole_shared_state.lock_rows' in config. "
                . "Webhook suppressed due to full lock table for key [{$key}]."
            );

            return false;
        }

        if ($result === false) {
            Log::error(
                "Reverb webhook lock table is full — increase 'reverb.swoole_shared_state.lock_rows' in config. "
                . "Webhook suppressed due to full lock table for key [{$key}]."
            );

            return false;
        }

        return true;
    }

    /**
     * Atomically consume a timestamp-based marker if it is still live.
     *
     * Checks the marker under the stripe lock. If the marker exists and has
     * not expired, deletes it and returns true. If the marker is expired or
     * does not exist, cleans up the stale row and returns false.
     */
    protected function consumeMarker(string $key, int $ttlMs): bool
    {
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            $row = $this->lockTable->get($key, 'locked_at');

            if ($row === false) {
                return false;
            }

            $this->lockTable->del($key);

            $now = microtime(true);

            if (($now - (float) $row) >= ($ttlMs / 1000.0)) {
                return false;
            }

            return true;
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Atomically ensure a row exists and increment it.
     *
     * Guarded by a striped lock to prevent a concurrent del() from
     * another worker between ensureRowExists() and incr().
     */
    protected function atomicIncr(string $key): int
    {
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            $this->ensureRowExists($key);

            $newCount = $this->table->incr($key, 'count', 1);
            $this->ensureOperationSucceeded($newCount, $key);

            return $newCount;
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Atomically decrement a counter and delete the row if it reaches zero.
     *
     * Guarded by a striped lock to prevent a concurrent incr() from
     * another worker between decr() and del().
     */
    protected function atomicDecrAndCleanup(string $key): int
    {
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            $newCount = $this->table->decr($key, 'count', 1);

            if ($newCount <= 0) {
                $this->table->del($key);
            }

            return $newCount;
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Get the striped lock for a given key.
     */
    protected function lockFor(string $key): Atomic
    {
        return $this->locks[crc32($key) % self::STRIPE_COUNT];
    }

    /**
     * Acquire a striped lock (spin-lock).
     */
    protected function acquire(Atomic $lock): void
    {
        while (! $lock->cmpset(0, 1)) {
            // Spin — lock is held for nanoseconds (C-level table operations only)
        }
    }

    /**
     * Release a striped lock.
     */
    protected function release(Atomic $lock): void
    {
        $lock->cmpset(1, 0);
    }

    /**
     * Ensure a row exists in the table before incrementing.
     *
     * Must be called within a lock — another worker's del() could
     * remove the row between exists() and set() otherwise.
     */
    protected function ensureRowExists(string $key): void
    {
        if (! $this->table->exists($key)) {
            try {
                $result = $this->table->set($key, ['count' => 0]);
            } catch (ErrorException $e) {
                throw new RuntimeException(
                    "Reverb shared state table is full — increase 'reverb.swoole_shared_state.rows' in config. "
                    . "Failed to create row for key [{$key}].",
                    previous: $e,
                );
            }

            if ($result === false) {
                throw new RuntimeException(
                    "Reverb shared state table is full — increase 'reverb.swoole_shared_state.rows' in config. "
                    . "Failed to create row for key [{$key}]."
                );
            }
        }
    }

    /**
     * Ensure an incr/decr operation succeeded.
     */
    protected function ensureOperationSucceeded(int|false $result, string $key): void
    {
        if ($result === false) {
            throw new RuntimeException(
                "Reverb shared state table operation failed for key [{$key}]. "
                . "The table may be full — increase 'reverb.swoole_shared_state.rows' in config."
            );
        }
    }
}
