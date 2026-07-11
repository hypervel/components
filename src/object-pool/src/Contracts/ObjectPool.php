<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use Hypervel\ObjectPool\PoolOptions;

interface ObjectPool
{
    /**
     * Get an object from the object pool.
     */
    public function get(): object;

    /**
     * Release an object back to the object pool.
     */
    public function release(object $object): void;

    /**
     * Destroy a checked-out object instead of returning it to the pool.
     */
    public function discard(object $object): void;

    /**
     * Destroy idle objects that exceed the maximum lifetime.
     */
    public function sweepExpired(): void;

    /**
     * Destroy idle objects past the maximum idle time down to the retention floor.
     */
    public function trimIdle(): void;

    /**
     * Close the pool and destroy all idle objects.
     */
    public function close(): void;

    /**
     * Determine if the pool is closed.
     */
    public function isClosed(): bool;

    /**
     * Determine if the entire pool has exceeded its idle TTL.
     */
    public function isIdle(): bool;

    /**
     * Return the number of objects currently checked out.
     */
    public function getBorrowedObjectNumber(): int;

    /**
     * Return the current number of objects managed by the pool.
     */
    public function getCurrentObjectNumber(): int;

    /**
     * Return the number of objects currently available in the pool.
     */
    public function getObjectNumberInPool(): int;

    /**
     * Get the normalized pool options.
     */
    public function getOptions(): PoolOptions;

    /**
     * Return statistics about the pool's current state.
     *
     * @return array{total: int, idle: int, borrowed: int, closed: bool}
     */
    public function getStats(): array;
}
