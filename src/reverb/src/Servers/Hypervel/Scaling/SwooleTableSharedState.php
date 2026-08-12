<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

use ErrorException;
use Hypervel\Core\Swoole\StripedLock;
use Hypervel\Reverb\Servers\Hypervel\Contracts\SharedState;
use Hypervel\Support\Facades\Log;
use RuntimeException;
use Swoole\Table;
use Throwable;

class SwooleTableSharedState implements SharedState
{
    protected const string SUBSCRIPTION_KEY_TYPE = 's';

    protected const string USER_KEY_TYPE = 'u';

    protected const string CONNECTION_KEY_TYPE = 'c';

    protected const string SUBSCRIPTION_COUNT_LOCK_KEY_TYPE = 't';

    protected const string CACHE_MISS_LOCK_KEY_TYPE = 'm';

    protected const string CHANNEL_SMOOTHING_KEY_TYPE = 'h';

    protected const string MEMBER_SMOOTHING_KEY_TYPE = 'p';

    protected int $hashSeed;

    /**
     * Create a new Swoole Table shared state instance.
     *
     * Must be created before fork (via instance(), not singleton()) so
     * both the Table and the Atomic locks are in shared memory.
     *
     * @param Table $table Main counter table (subscription counts, connection slots)
     * @param Table $lockTable Webhook throttle/dedupe lock table (timestamp-based TTLs)
     * @param StripedLock $locks Inter-worker row lifecycle locks created before fork
     */
    public function __construct(
        protected Table $table,
        protected Table $lockTable,
        protected StripedLock $locks,
        int $hashSeed = 0,
    ) {
        $this->hashSeed = $hashSeed ?: random_int(1, PHP_INT_MAX);
    }

    /**
     * Record a channel subscription and return the transition result.
     */
    public function subscribe(string $appId, string $channel, ?string $userId = null): SubscriptionResult
    {
        $channelKey = $this->physicalKey(self::SUBSCRIPTION_KEY_TYPE, $appId, $channel);

        if ($userId === null) {
            $newCount = $this->atomicIncr($channelKey);
            $memberAdded = false;
        } else {
            $userKey = $this->physicalKey(self::USER_KEY_TYPE, $appId, $channel, $userId);
            [$newCount, $userCount] = $this->locks->withLocks(
                [$channelKey, $userKey],
                function () use ($channelKey, $userKey): array {
                    $this->ensurePresenceRowsExist($channelKey, $userKey);

                    return [
                        $this->table->incr($channelKey, 'count', 1),
                        $this->table->incr($userKey, 'count', 1),
                    ];
                },
            );

            $memberAdded = ($userCount === 1);
        }

        return new SubscriptionResult(
            channelOccupied: $newCount === 1,
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
        $channelKey = $this->physicalKey(self::SUBSCRIPTION_KEY_TYPE, $appId, $channel);

        if ($userId === null) {
            $newCount = $this->atomicDecrAndCleanup($channelKey);
            $memberRemoved = false;
        } else {
            $userKey = $this->physicalKey(self::USER_KEY_TYPE, $appId, $channel, $userId);
            [$newCount, $userCount] = $this->locks->withLocks(
                [$channelKey, $userKey],
                function () use ($channelKey, $userKey): array {
                    $this->ensurePresenceRowsExist($channelKey, $userKey);

                    return [
                        $this->decrAndCleanup($channelKey),
                        $this->decrAndCleanup($userKey),
                    ];
                },
            );

            $memberRemoved = ($userCount <= 0);
        }

        return new SubscriptionResult(
            channelOccupied: false,
            channelVacated: $newCount <= 0,
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
        $key = $this->physicalKey(self::CONNECTION_KEY_TYPE, $appId);
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
        $key = $this->physicalKey(self::CONNECTION_KEY_TYPE, $appId);

        $this->locks->withLock($key, function () use ($key): void {
            if (! $this->table->exists($key)) {
                return;
            }

            $newCount = $this->table->decr($key, 'count', 1);

            if ($newCount <= 0) {
                $this->table->del($key);
            }
        });
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
        $row = $this->table->get(
            $this->physicalKey(self::SUBSCRIPTION_KEY_TYPE, $appId, $channel),
            'count',
        );

        return $row !== false ? (int) $row : 0;
    }

    /**
     * Get the current subscription count for a specific user in a channel.
     */
    public function getUserSubscriptionCount(string $appId, string $channel, string $userId): int
    {
        $row = $this->table->get(
            $this->physicalKey(self::USER_KEY_TYPE, $appId, $channel, $userId),
            'count',
        );

        return $row !== false ? (int) $row : 0;
    }

    /**
     * Attempt to acquire a subscription_count webhook throttle lock.
     */
    public function trySubscriptionCountLock(string $appId, string $channel, int $ttlMs = 5000): bool
    {
        return $this->tryLock(
            $this->physicalKey(self::SUBSCRIPTION_COUNT_LOCK_KEY_TYPE, $appId, $channel),
            $ttlMs,
        );
    }

    /**
     * Attempt to acquire a cache_miss webhook dedupe lock.
     */
    public function tryCacheMissLock(string $appId, string $channel, int $ttlMs = 10000): bool
    {
        return $this->tryLock(
            $this->physicalKey(self::CACHE_MISS_LOCK_KEY_TYPE, $appId, $channel),
            $ttlMs,
        );
    }

    /**
     * Clear the cache_miss dedupe lock for a channel.
     */
    public function clearCacheMissLock(string $appId, string $channel): void
    {
        $key = $this->physicalKey(self::CACHE_MISS_LOCK_KEY_TYPE, $appId, $channel);

        $this->locks->withLock(
            $key,
            fn (): bool => $this->lockTable->del($key),
        );
    }

    /**
     * Clear the subscription_count throttle lock for a channel.
     */
    public function clearSubscriptionCountLock(string $appId, string $channel): void
    {
        $key = $this->physicalKey(self::SUBSCRIPTION_COUNT_LOCK_KEY_TYPE, $appId, $channel);

        $this->locks->withLock(
            $key,
            fn (): bool => $this->lockTable->del($key),
        );
    }

    /**
     * Mark a channel as having a pending deferred channel_vacated webhook.
     */
    public function setSmoothingPending(string $appId, string $channel, int $ttlMs): void
    {
        $key = $this->physicalKey(self::CHANNEL_SMOOTHING_KEY_TYPE, $appId, $channel);
        $stored = $this->locks->withLock(
            $key,
            fn (): bool => $this->setLockRow($key, microtime(true)),
        );

        if (! $stored) {
            $this->reportFullLockTable($key);
        }
    }

    /**
     * Atomically consume a channel smoothing marker if it is still live.
     */
    public function clearSmoothingPending(string $appId, string $channel, int $ttlMs): bool
    {
        return $this->consumeMarker(
            $this->physicalKey(self::CHANNEL_SMOOTHING_KEY_TYPE, $appId, $channel),
            $ttlMs,
        );
    }

    /**
     * Mark a presence channel member as having a pending deferred member_removed webhook.
     */
    public function setMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): void
    {
        $key = $this->physicalKey(self::MEMBER_SMOOTHING_KEY_TYPE, $appId, $channel, $userId);
        $stored = $this->locks->withLock(
            $key,
            fn (): bool => $this->setLockRow($key, microtime(true)),
        );

        if (! $stored) {
            $this->reportFullLockTable($key);
        }
    }

    /**
     * Atomically consume a member smoothing marker if it is still live.
     */
    public function clearMemberSmoothingPending(string $appId, string $channel, string $userId, int $ttlMs): bool
    {
        return $this->consumeMarker(
            $this->physicalKey(self::MEMBER_SMOOTHING_KEY_TYPE, $appId, $channel, $userId),
            $ttlMs,
        );
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
        $writeFailed = false;
        $stored = $this->locks->withLock($key, function () use ($key, $ttlMs, &$writeFailed): bool {
            $row = $this->lockTable->get($key, 'locked_at');
            $now = microtime(true);

            if ($row !== false && ($now - (float) $row) < ($ttlMs / 1000.0)) {
                return false;
            }

            $stored = $this->setLockRow($key, $now);
            $writeFailed = ! $stored;

            return $stored;
        });

        if ($writeFailed) {
            $this->reportFullLockTable($key);
        }

        return $stored;
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
            $this->lockTable->set($key, ['locked_at' => $timestamp]);

            return true;
        } catch (ErrorException) {
            return false;
        }
    }

    /**
     * Report a failed write after releasing its stripe lock.
     */
    protected function reportFullLockTable(string $key): void
    {
        Log::error(
            "Reverb webhook lock table is full — increase 'reverb.servers.reverb.swoole_shared_state.lock_rows' in config. "
            . "Webhook suppressed due to full lock table for key [{$key}]."
        );
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
        return $this->locks->withLock($key, function () use ($key, $ttlMs): bool {
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
        });
    }

    /**
     * Atomically ensure a row exists and increment it.
     *
     * Guarded by a striped lock to prevent a concurrent del() from
     * another worker between ensureRowExists() and incr().
     */
    protected function atomicIncr(string $key): int
    {
        return $this->locks->withLock($key, function () use ($key): int {
            $this->ensureRowExists($key);

            return $this->table->incr($key, 'count', 1);
        });
    }

    /**
     * Atomically decrement a counter and delete the row if it reaches zero.
     *
     * Guarded by a striped lock to prevent a concurrent incr() from
     * another worker between decr() and del().
     */
    protected function atomicDecrAndCleanup(string $key): int
    {
        return $this->locks->withLock($key, function () use ($key): int {
            $this->ensureRowExists($key);

            return $this->decrAndCleanup($key);
        });
    }

    /**
     * Decrement a counter and delete the row if it reaches zero.
     *
     * Must be called while holding the stripe lock for the key.
     */
    protected function decrAndCleanup(string $key): int
    {
        $newCount = $this->table->decr($key, 'count', 1);

        if ($newCount <= 0) {
            $this->table->del($key);
        }

        return $newCount;
    }

    /**
     * Ensure a row exists in the table before incrementing.
     *
     * Must be called within a lock — another worker's del() could
     * remove the row between exists() and set() otherwise.
     */
    protected function ensureRowExists(string $key): bool
    {
        if ($this->table->exists($key)) {
            return false;
        }

        try {
            $this->table->set($key, ['count' => 0]);
        } catch (ErrorException $exception) {
            throw new RuntimeException(
                "Reverb shared state table is full — increase 'reverb.servers.reverb.swoole_shared_state.rows' in config. "
                . "Failed to create row for key [{$key}].",
                previous: $exception,
            );
        }

        return true;
    }

    /**
     * Ensure both presence rows exist before publishing either mutation.
     */
    protected function ensurePresenceRowsExist(string $channelKey, string $userKey): void
    {
        $channelCreated = $this->ensureRowExists($channelKey);

        try {
            $this->ensureRowExists($userKey);
        } catch (Throwable $exception) {
            if ($channelCreated) {
                $this->table->del($channelKey);
            }

            throw $exception;
        }
    }

    /**
     * Get a bounded physical table key for a logical identity.
     */
    protected function physicalKey(string $type, string ...$parts): string
    {
        return $type . hash('xxh128', $this->logicalKey($type, ...$parts), false, [
            'seed' => $this->hashSeed,
        ]);
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
