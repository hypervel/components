<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

interface ObjectPoolInterface
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
     * Close and clear the object pool.
     */
    public function flush(): void;

    public function getOption(): ObjectPoolOption;
}
