<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use Hypervel\ObjectPool\RecycleStrategies\TimeStrategy;

class ObjectPoolOption
{
    /**
     * Min objects of pool.
     * This means the pool will create $minObjects objects when
     * pool initialization.
     */
    protected int $minObjects;

    /**
     * Max objects of pool.
     */
    protected int $maxObjects;

    /**
     * The timeout of pop an object.
     * Default value is 3 seconds.
     */
    protected float $waitTimeout;

    /**
     * The max lifetime for object.
     */
    protected float $maxLifetime;

    /**
     * The class name of the recycle strategy to use.
     */
    protected ?string $recycledStrategy = null;

    /**
     * Creates a new ObjectPoolOption with the given configuration.
     */
    public function __construct(
        int $minObjects = 1,
        int $maxObjects = 10,
        float $waitTimeout = 3.0,
        float $maxLifetime = 60.0,
        string $recycleStrategy = TimeStrategy::class,
    ) {
        $this->minObjects = $minObjects;
        $this->maxObjects = $maxObjects;
        $this->waitTimeout = $waitTimeout;
        $this->maxLifetime = $maxLifetime;
        $this->recycledStrategy = $recycleStrategy;
    }

    /**
     * Gets the maximum number of objects the pool can have.
     */
    public function getMaxObjects(): int
    {
        return $this->maxObjects;
    }

    /**
     * Sets the maximum number of objects the pool can have.
     */
    public function setMaxObjects(int $maxObjects): static
    {
        $this->maxObjects = $maxObjects;

        return $this;
    }

    /**
     * Gets the minimum number of objects the pool should maintain.
     */
    public function getMinObjects(): int
    {
        return $this->minObjects;
    }

    /**
     * Sets the minimum number of objects the pool should maintain.
     */
    public function setMinObjects(int $minObjects): static
    {
        $this->minObjects = $minObjects;

        return $this;
    }

    /**
     * Gets the timeout when waiting for an object from the pool.
     */
    public function getWaitTimeout(): float
    {
        return $this->waitTimeout;
    }

    /**
     * Sets the timeout when waiting for an object from the pool.
     */
    public function setWaitTimeout(float $waitTimeout): static
    {
        $this->waitTimeout = $waitTimeout;

        return $this;
    }

    /**
     * Gets the maximum lifetime of an object in the pool.
     */
    public function getMaxLifetime(): float
    {
        return $this->maxLifetime;
    }

    /**
     * Sets the maximum lifetime of an object in the pool.
     */
    public function setMaxLifetime(float $maxLifetime): static
    {
        $this->maxLifetime = $maxLifetime;

        return $this;
    }

    /**
     * Gets the class name of the recycle strategy to use.
     */
    public function getStrategy(): ?string
    {
        return $this->recycledStrategy ??= TimeStrategy::class;
    }

    /**
     * Sets the class name of the recycle strategy to use.
     */
    public function setStrategy(?string $strategy): static
    {
        $this->recycledStrategy = $strategy;

        return $this;
    }
}
