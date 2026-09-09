<?php

declare(strict_types=1);

namespace Hypervel\Contracts\ConnectionPool;

interface UsageTracker
{
    /**
     * Record a successful connection borrow.
     */
    public function recordBorrow(): void;

    /**
     * Determine whether the pool should trim excess idle connections.
     */
    public function shouldTrimExcessIdle(): bool;
}
