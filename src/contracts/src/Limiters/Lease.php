<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Limiters;

/**
 * A held concurrency-limiter slot.
 *
 * A lease is acquired from a funnel limiter and held by the caller across
 * operations, coroutines, or requests until it is explicitly released or its
 * releaseAfter TTL reclaims it after a crash. Leases that support TTL
 * extension implement RefreshableLease.
 */
interface Lease
{
    /**
     * Release the held slot if still owned by this lease.
     */
    public function release(): bool;

    /**
     * Get the owner identifier of this lease.
     */
    public function owner(): string;
}
