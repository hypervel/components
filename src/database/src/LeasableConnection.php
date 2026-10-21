<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Closure;

/**
 * Let a pooled driver return its physical connection to the pool between operations.
 *
 * The pool keeps the connection the driver creates as the physical connection and
 * gives each caller its own logical connection built from it, so idle physical
 * connections can be returned early just like PDO sessions.
 */
interface LeasableConnection
{
    /**
     * Create a caller-owned connection that uses this driver's physical connections on demand.
     *
     * The returned connection keeps the caller's state and never stores the physical
     * connection's resources. Each operation calls $physical to get the physical
     * connection currently held for the caller, which borrows one from the pool when
     * none is held. Work that must keep the same physical connection across a
     * suspension point pins it with withPinnedSession().
     *
     * @param Closure(): static $physical
     */
    public function newLeasedConnection(Closure $physical): static;
}
