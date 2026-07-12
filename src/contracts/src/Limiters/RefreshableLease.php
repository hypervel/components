<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Limiters;

use InvalidArgumentException;

/**
 * A lease that supports refreshing its TTL and inspecting remaining lifetime.
 *
 * Semantics mirror RefreshableLock: refresh() is atomic and owner-checked,
 * refresh(null) re-applies the duration the slot was acquired with as the
 * backend interpreted it, and an explicit non-positive TTL throws.
 */
interface RefreshableLease extends Lease
{
    /**
     * Refresh the lease's TTL if still owned by this lease.
     *
     * @param null|int $seconds Seconds to set the TTL to (null = re-apply the acquisition TTL)
     * @return bool True if the lease was refreshed (or is permanent and still owned), false if not owned or expired
     *
     * @throws InvalidArgumentException If $seconds is explicitly provided and is not positive
     */
    public function refresh(?int $seconds = null): bool;

    /**
     * Get the number of seconds until the lease expires.
     *
     * @return null|float Seconds remaining, or null if the slot doesn't exist or has no expiry
     */
    public function getRemainingLifetime(): ?float;
}
