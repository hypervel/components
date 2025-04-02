<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

use DateTime;
use Hypervel\ObjectPool\PoolOption;

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
     * Flush excess objects from the pool down to the minimum.
     */
    public function flush(): void;

    /**
     * Flush a single object from the pool if it meets removal criteria.
     */
    public function flushOne(bool $force = false): void;

    /**
     * Return the current number of objects managed by the pool.
     */
    public function getCurrentObjectNumber(): int;

    /**
     * Return the number of objects currently available in the pool.
     */
    public function getObjectNumberInPool(): int;

    /**
     * Get the pool's configuration options.
     */
    public function getOption(): PoolOption;

    /**
     * Get the recycle strategy instance for this pool.
     */
    public function getRecycleStrategy(): RecycleStrategy;

    /**
     * Get the last time the pool was recycled.
     */
    public function getLastRecycledAt(): null|DateTime|int;

    /**
     * Set the last time the pool was recycled.
     */
    public function setLastRecycledAt(null|DateTime|int $lastRecycledAt): static;
}
