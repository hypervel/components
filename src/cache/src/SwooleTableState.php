<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Swoole\Atomic;

/**
 * Coordinates multi-step Swoole table row mutations across workers.
 *
 * Swoole Table does not currently provide full-row compare-and-swap,
 * set-if-absent, or delete-if-current primitives, so cache operations that need
 * atomic read-check-write behavior use striped shared Atomics around tiny
 * critical sections.
 *
 * @TODO Revisit this if Swoole Table adds full-row CAS / set-if-absent /
 * delete-if-current primitives so these operations can use native table atomics
 * instead of external stripe locks.
 */
class SwooleTableState
{
    protected const STRIPE_COUNT = 64;

    /**
     * Striped locks for row lifecycle operations.
     *
     * @var list<Atomic>
     */
    protected array $rowLocks;

    /**
     * Create a new Swoole table state instance.
     */
    public function __construct(
        protected SwooleTable $table,
        protected int $hashSeed = 0,
    ) {
        $this->hashSeed = $hashSeed ?: random_int(1, PHP_INT_MAX);

        $this->rowLocks = array_map(
            fn () => new Atomic(0),
            range(0, self::STRIPE_COUNT - 1),
        );
    }

    /**
     * Get the Swoole table.
     */
    public function table(): SwooleTable
    {
        return $this->table;
    }

    /**
     * Get the hash seed.
     */
    public function hashSeed(): int
    {
        return $this->hashSeed;
    }

    /**
     * Run the callback while holding the row lock for the given table key.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withRowLock(string $key, callable $callback): mixed
    {
        $lock = $this->lockFor($key);
        $this->acquire($lock);

        try {
            return $callback();
        } finally {
            $this->release($lock);
        }
    }

    /**
     * Run the callback while holding every row-lock stripe.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withAllRowLocks(callable $callback): mixed
    {
        $acquired = [];

        foreach ($this->rowLocks as $lock) {
            $this->acquire($lock);
            $acquired[] = $lock;
        }

        try {
            return $callback();
        } finally {
            while ($lock = array_pop($acquired)) {
                $this->release($lock);
            }
        }
    }

    /**
     * Get the striped lock for a table key.
     */
    protected function lockFor(string $key): Atomic
    {
        return $this->rowLocks[crc32($key) % self::STRIPE_COUNT];
    }

    /**
     * Acquire a striped lock.
     */
    protected function acquire(Atomic $lock): void
    {
        while (! $lock->cmpset(0, 1)) {
            // Critical sections must stay short, non-yielding, and fatal-free so finally can release the stripe.
            // A hard process death while holding a stripe leaves it locked until the Swoole table state is recreated.
        }
    }

    /**
     * Release a striped lock.
     */
    protected function release(Atomic $lock): void
    {
        $lock->cmpset(1, 0);
    }
}
