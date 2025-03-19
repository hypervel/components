<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

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
     * The max liftime for object.
     */
    protected float $maxLifetime;

    public function __construct(
        int $minObjects = 1,
        int $maxObjects = 10,
        float $waitTimeout = 3.0,
        float $maxLifetime = 60.0
    ) {
        $this->minObjects = $minObjects;
        $this->maxObjects = $maxObjects;
        $this->waitTimeout = $waitTimeout;
        $this->maxLifetime = $maxLifetime;
    }

    public function getMaxObjects(): int
    {
        return $this->maxObjects;
    }

    public function setMaxObjects(int $maxObjects): static
    {
        $this->maxObjects = $maxObjects;

        return $this;
    }

    public function getMinObjects(): int
    {
        return $this->minObjects;
    }

    public function setMinObjects(int $minObjects): static
    {
        $this->minObjects = $minObjects;

        return $this;
    }

    public function getWaitTimeout(): float
    {
        return $this->waitTimeout;
    }

    public function setWaitTimeout(float $waitTimeout): static
    {
        $this->waitTimeout = $waitTimeout;

        return $this;
    }

    public function getMaxLifetime(): float
    {
        return $this->maxLifetime;
    }

    public function setMaxLifetime(float $maxLifetime): static
    {
        $this->maxLifetime = $maxLifetime;

        return $this;
    }
}
