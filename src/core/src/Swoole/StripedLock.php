<?php

declare(strict_types=1);

namespace Hypervel\Core\Swoole;

use RuntimeException;
use Swoole\Atomic;

/**
 * Coordinate short key-scoped critical sections across Swoole workers.
 *
 * The locks must be constructed before the server forks so every worker
 * inherits references to the same shared atomics. Every multi-stripe path
 * acquires stripes in ascending index order and releases them in reverse so
 * selected-stripe and all-stripe callers cannot deadlock each other.
 */
class StripedLock
{
    protected const int STRIPE_COUNT = 64;

    // Late-bound so deterministic test subclasses can shorten the spin phase.
    protected const int SPINS_BEFORE_BACKOFF = 64;

    // Late-bound so deterministic test subclasses can shorten the timeout.
    protected const int ACQUIRE_TIMEOUT_NANOSECONDS = 1_000_000_000;

    /**
     * @var list<Atomic>
     */
    protected array $locks;

    /**
     * Create a new striped lock.
     */
    public function __construct()
    {
        $this->locks = array_map(
            static fn (): Atomic => new Atomic(0),
            range(0, static::STRIPE_COUNT - 1),
        );
    }

    /**
     * Run the callback while holding the stripe for the given key.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withLock(string $key, callable $callback): mixed
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
     * Run the callback while holding the stripes for the given keys.
     *
     * @template T
     * @param list<string> $keys
     * @param callable(): T $callback
     * @return T
     */
    public function withLocks(array $keys, callable $callback): mixed
    {
        $stripeIndexes = [];

        foreach ($keys as $key) {
            $stripeIndexes[$this->lockIndexFor($key)] = true;
        }

        $stripeIndexes = array_keys($stripeIndexes);
        sort($stripeIndexes, SORT_NUMERIC);

        $locks = [];

        foreach ($stripeIndexes as $stripeIndex) {
            $locks[] = $this->locks[$stripeIndex];
        }

        return $this->withSelectedLocks($locks, $callback);
    }

    /**
     * Run the callback while holding every stripe.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withAllLocks(callable $callback): mixed
    {
        return $this->withSelectedLocks($this->locks, $callback);
    }

    /**
     * Run the callback while holding the selected stripes in their supplied order.
     *
     * @template T
     * @param list<Atomic> $locks
     * @param callable(): T $callback
     * @return T
     */
    protected function withSelectedLocks(array $locks, callable $callback): mixed
    {
        $acquired = [];

        try {
            foreach ($locks as $lock) {
                $this->acquire($lock);
                $acquired[] = $lock;
            }

            return $callback();
        } finally {
            while (($lock = array_pop($acquired)) !== null) {
                $this->release($lock);
            }
        }
    }

    /**
     * Get the stripe for a key.
     */
    protected function lockFor(string $key): Atomic
    {
        return $this->locks[$this->lockIndexFor($key)];
    }

    /**
     * Get the stripe index for a key.
     */
    protected function lockIndexFor(string $key): int
    {
        return crc32($key) % static::STRIPE_COUNT;
    }

    /**
     * Acquire a stripe.
     */
    protected function acquire(Atomic $lock): void
    {
        $deadline = null;
        $spins = 0;

        while (! $lock->cmpset(0, 1)) {
            $deadline ??= hrtime(true) + static::ACQUIRE_TIMEOUT_NANOSECONDS;

            if (++$spins < static::SPINS_BEFORE_BACKOFF) {
                continue;
            }

            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Timed out acquiring a Swoole striped lock.');
            }

            $spins = 0;
            usleep(1);
        }
    }

    /**
     * Release a stripe.
     */
    protected function release(Atomic $lock): void
    {
        $lock->cmpset(1, 0);
    }
}
