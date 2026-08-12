<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool\Contracts;

interface InvalidatesPool
{
    /**
     * Remove and close the current shared pool.
     */
    public function invalidatePool(): bool;
}
