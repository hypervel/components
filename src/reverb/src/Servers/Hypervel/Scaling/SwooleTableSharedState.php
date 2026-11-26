<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

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
     * Must be created before the fork so both the Table and the Atomic
     * locks are in shared memory.
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
            $newCount = $this->locks->withLock($channelKey, fn (): int => $this->incrementCount($channelKey));
            $memberAdded = false;
        } else {
            $userKey = $this->physicalKey(self::USER_KEY_TYPE, $appId, $channel, $userId);
            [$newCount, $userCount] = $this->locks->withLocks(
                [$channelKey, $userKey],
                function () use ($channelKey, $userKey): array {
                    $this->ensurePresenceRowsExist($channelKey, $userKey);

                    return [
                        $this->incrementCount($channelKey),
                        $this->incrementCount($userKey),
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
            $newCount = $this->locks->withLock($channelKey, fn (): int => $this->decrAndCleanup($channelKey));
            $memberRemoved = false;
        } else {
            $userKey = $this->physicalKey(self::USER_KEY_TYPE, $appId, $channel, $userId);
            [$newCount, $userCount] = $this->locks->withLocks(
                [$channelKey, $userKey],
                fn (): array => [
                    $this->decrAndCleanup($channelKey),
                    $this->decrAndCleanup($userKey),
                ],
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

        return $this->locks->withLock($key, function () use ($key, $maxConnections): bool {
            if ((int) $this->table->get($key, 'count') >= $maxConnections) {
                return false;
            }

            $this->incrementCount($key);

            return true;
        });
    }

    /**
     * Release a connection slot for the given app.
     */
    public function releaseConnectionSlot(string $appId): void
    {
        $key = $this->physicalKey(self::CONNECTION_KEY_TYPE, $appId);

        $this->locks->withLock($key, fn (): int => $this->decrAndCleanup($key));
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
        $this->lockTable->del($this->physicalKey(self::CACHE_MISS_LOCK_KEY_TYPE, $appId, $channel));
    }

    /**
     * Clear the subscription_count throttle lock for a channel.
     */
    public function clearSubscriptionCountLock(string $appId, string $channel): void
    {
        $this->lockTable->del($this->physicalKey(self::SUBSCRIPTION_COUNT_LOCK_KEY_TYPE, $appId, $channel));
    }

    /**
     * Mark a channel as having a pending deferred channel_vacated webhook.
     */
    public function setSmoothingPending(string $appId, string $channel, int $ttlMs): void
    {
        $this->setMarker($this->physicalKey(self::CHANNEL_SMOOTHING_KEY_TYPE, $appId, $channel));
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
        $this->setMarker($this->physicalKey(self::MEMBER_SMOOTHING_KEY_TYPE, $appId, $channel, $userId));
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
     */
    protected function tryLock(string $key, int $ttlMs): bool
    {
        do {
            $lockedAt = $this->lockTable->get($key, 'locked_at');
            $now = microtime(true);

            if ($lockedAt === false) {
                $acquired = $this->addRow($this->lockTable, $key, ['locked_at' => $now]);

                if ($acquired === null) {
                    $this->reportFullLockTable($key);

                    return false;
                }
            } elseif ($now - (float) $lockedAt < $ttlMs / 1000.0) {
                return false;
            } else {
                $acquired = $this->lockTable->cmpset($key, ['locked_at' => $lockedAt], ['locked_at' => $now]);
            }
        } while (! $acquired);

        return true;
    }

    /**
     * Store a webhook smoothing marker.
     */
    protected function setMarker(string $key): void
    {
        if (! @$this->lockTable->set($key, ['locked_at' => microtime(true)])) {
            $this->reportFullLockTable($key);
        }
    }

    /**
     * Report a full webhook lock table.
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
     */
    protected function consumeMarker(string $key, int $ttlMs): bool
    {
        $lockedAt = $this->lockTable->getdel($key, 'locked_at');

        return $lockedAt !== false && microtime(true) - (float) $lockedAt < $ttlMs / 1000.0;
    }

    /**
     * Increment a counter while its caller holds the stripe.
     */
    protected function incrementCount(string $key): int
    {
        $count = @$this->table->incr($key, 'count', 1);

        if ($count === false) {
            throw $this->fullCounterTableException($key);
        }

        return $count;
    }

    /**
     * Decrement a counter and delete the row if it reaches zero.
     *
     * Must be called while holding the stripe lock for the key.
     */
    protected function decrAndCleanup(string $key): int
    {
        if (! $this->table->exists($key)) {
            return 0;
        }

        $newCount = $this->table->decr($key, 'count', 1);

        if ($newCount <= 0) {
            $this->table->del($key);
        }

        return $newCount;
    }

    /**
     * Ensure a row exists in the table before incrementing.
     *
     * Return whether this operation created the row and owns its rollback.
     */
    protected function ensureRowExists(string $key): bool
    {
        return $this->addRow($this->table, $key, ['count' => 0]) ?? throw $this->fullCounterTableException($key);
    }

    /**
     * Insert a row, returning false for a duplicate or null when the table is full.
     */
    protected function addRow(Table $table, string $key, array $values): ?bool
    {
        // Both failures return false, but only exhaustion sets the native error.
        // Intercept the allocation warning locally so an outer error handler cannot overwrite swoole_last_error().
        swoole_clear_error();
        set_error_handler(static fn (): bool => true);

        try {
            if ($table->add($key, $values)) {
                return true;
            }
        } finally {
            restore_error_handler();
        }

        return swoole_last_error() === SWOOLE_ERROR_PHP_RUNTIME_NOTICE ? null : false;
    }

    /**
     * Create the exception for an exhausted subscription or connection table.
     */
    protected function fullCounterTableException(string $key): RuntimeException
    {
        return new RuntimeException(
            "Reverb shared state table is full — increase 'reverb.servers.reverb.swoole_shared_state.rows' in config. "
            . "Failed to create row for key [{$key}]."
        );
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
