<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Cache;

interface CanFlushLocks
{
    /**
     * Determine if the store can currently flush locks.
     *
     * Composite stores may implement this interface while delegating to a
     * layer that cannot flush locks; this probe reports the real capability.
     */
    public function supportsFlushingLocks(): bool;

    /**
     * Flush all locks managed by the store.
     */
    public function flushLocks(): bool;

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool;
}
