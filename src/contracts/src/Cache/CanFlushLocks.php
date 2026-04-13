<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cache;

interface CanFlushLocks
{
    /**
     * Flush all locks managed by the store.
     */
    public function flushLocks(): bool;

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool;
}
