<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Contracts;

interface PrunableStore
{
    /**
     * Prune expired rate limiter state.
     */
    public function pruneExpired(int $chunkSize = 1000): int;
}
