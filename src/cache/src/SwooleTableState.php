<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Core\Swoole\StripedLock;

/**
 * Row stripes serialize compound mutations and let a flush hold back concurrent writers.
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
