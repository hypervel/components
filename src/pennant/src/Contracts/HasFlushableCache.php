<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Contracts;

interface HasFlushableCache
{
    /**
     * Flush the cache.
     */
    public function flushCache(): void;
}
