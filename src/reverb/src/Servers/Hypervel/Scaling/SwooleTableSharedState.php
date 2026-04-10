<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Servers\Hypervel\Scaling;

use ErrorException;
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
     */
    public function __construct(
        protected Table $table,
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
