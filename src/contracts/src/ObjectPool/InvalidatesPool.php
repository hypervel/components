<?php

declare(strict_types=1);

namespace Hypervel\Contracts\ObjectPool;

interface InvalidatesPool
{
    /**
     * Remove and close the current shared pool.
     */
    public function invalidatePool(): bool;
}
