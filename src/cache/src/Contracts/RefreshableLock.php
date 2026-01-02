<?php

declare(strict_types=1);

namespace Hypervel\Cache\Contracts;

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
     * @param null|int $seconds Seconds to set the TTL to (null = use original TTL from construction)
     * @return bool True if the lock was refreshed, false if not owned or expired
     */
    public function refresh(?int $seconds = null): bool;

    /**
     * Get the number of seconds until the lock expires.
     *
     * @return null|float Seconds remaining, or null if lock doesn't exist or has no expiry
     */
    public function getRemainingLifetime(): ?float;
}
