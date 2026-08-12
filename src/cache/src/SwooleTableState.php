<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Core\Swoole\StripedLock;

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
    protected StripedLock $locks;

    /**
     * Create a new Swoole table state instance.
     */
    public function __construct(
        protected SwooleTable $table,
        protected int $hashSeed = 0,
    ) {
        $this->hashSeed = $hashSeed ?: random_int(1, PHP_INT_MAX);
        $this->locks = new StripedLock;
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
        return $this->locks->withLock($key, $callback);
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
        return $this->locks->withAllLocks($callback);
    }
}
