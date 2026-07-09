<?php

declare(strict_types=1);

namespace Hypervel\Cache\Limiters;

use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Limiters\Lease;

class ConcurrencyLease implements Lease
{
    /**
     * Create a new lease instance.
     */
    public function __construct(
        protected Lock $lock,
    ) {
    }

    /**
     * Release the held slot if still owned by this lease.
     */
    public function release(): bool
    {
        return $this->lock->release();
    }

    /**
     * Get the owner identifier of this lease.
     */
    public function owner(): string
    {
        return $this->lock->owner();
    }
}
