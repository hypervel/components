<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\ObjectPool\Strategies\TimeStrategy;

class PoolOption
{
    /**
     * Creates a new PoolOption with the given configuration.
     *
     * @param int $minObjects minimum number of objects to maintain in the pool
     * @param int $maxObjects maximum number of objects to maintain in the pool
     * @param float $waitTimeout timeout for waiting for an object from the pool
     * @param float $maxLifetime maximum lifetime of an object in the pool
     * @param float $recycleRatio ratio of objects to recycle when recycling
     * @param string $recycleStrategy class name of the recycle strategy to use
     */
    public function __construct(
        protected int $minObjects = 1,
        protected int $maxObjects = 10,
        protected float $waitTimeout = 3.0,
        protected float $maxLifetime = 60.0,
        protected float $recycleRatio = 0.2,
        protected string $recycleStrategy = TimeStrategy::class,
    ) {
    }

    /**
     * Get the maximum number of objects the pool can have.
     */
    public function getMaxObjects(): int
    {
        return $this->maxObjects;
    }

    /**
     * Set the maximum number of objects the pool can have.
     */
    public function setMaxObjects(int $maxObjects): static
    {
        $this->maxObjects = $maxObjects;

        return $this;
    }

    /**
     * Get the minimum number of objects the pool should maintain.
     */
    public function getMinObjects(): int
    {
        return $this->minObjects;
    }

    /**
     * Set the minimum number of objects the pool should maintain.
     */
    public function setMinObjects(int $minObjects): static
    {
        $this->minObjects = $minObjects;

        return $this;
    }

    /**
     * Get the timeout when waiting for an object from the pool.
     */
    public function getWaitTimeout(): float
    {
        return $this->waitTimeout;
    }

    /**
     * Set the timeout when waiting for an object from the pool.
     */
    public function setWaitTimeout(float $waitTimeout): static
    {
        $this->waitTimeout = $waitTimeout;

        return $this;
    }

    /**
     * Get the maximum lifetime of an object in the pool.
     */
    public function getMaxLifetime(): float
    {
        return $this->maxLifetime;
    }

    /**
     * Set the maximum lifetime of an object in the pool.
     */
    public function setMaxLifetime(float $maxLifetime): static
    {
        $this->maxLifetime = $maxLifetime;

        return $this;
    }

    /**
     * Get the class name of the recycle strategy to use.
     */
    public function getStrategy(): string
    {
        return $this->recycleStrategy;
    }

    /**
     * Set the class name of the recycle strategy to use.
     */
    public function setStrategy(?string $strategy): static
    {
        $this->recycleStrategy = $strategy;

        return $this;
    }

    /**
     * Get the ratio of objects to recycle when recycling.
     */
    public function getRecycleRatio(): float
    {
        return $this->recycleRatio;
    }

    /**
     * Set the ratio of objects to recycle when recycling.
     */
    public function setRecycleRatio(float $recycleRatio): static
    {
        $this->recycleRatio = $recycleRatio;

        return $this;
    }
}
