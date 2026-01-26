<?php

declare(strict_types=1);

namespace Hypervel\Cache\Contracts;

use InvalidArgumentException;

/**
 * A lock that supports refreshing its TTL and inspecting remaining lifetime.
 *
 * Not all lock drivers can implement this interface atomically. Drivers that
 * cannot guarantee atomic refresh operations (like CacheLock) should not
 * implement this interface.
 */
interface RefreshableLock extends Lock
{
    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * This operation is atomic - if the lock has been released or acquired
     * by another process, this will return false without modifying anything.
     *
     * When called without arguments on a permanent lock (one acquired with
     * a TTL of 0), this is a no-op that returns true since there's no TTL
     * to refresh.
     *
     * @param null|int $seconds Seconds to set the TTL to (null = use original TTL from construction)
     * @return bool True if the lock was refreshed (or is permanent), false if not owned or expired
     *
     * @throws InvalidArgumentException If $seconds is explicitly provided and is not positive
     */
    public function refresh(?int $seconds = null): bool;

    /**
     * Get the number of seconds until the lock expires.
     *
     * @return null|float Seconds remaining, or null if lock doesn't exist or has no expiry
     */
    public function getRemainingLifetime(): ?float;
}
