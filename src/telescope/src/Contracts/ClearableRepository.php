<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Contracts;

interface ClearableRepository
{
    /**
     * Clear all of the entries.
     */
    public function clear(): void;
}
