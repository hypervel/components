<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Coroutine\Locker;
use RuntimeException;

/**
 * Coordinate cleanup for one attempt across its job coroutine and timeout monitor.
 */
class JobProcessingMarker
{
    protected bool $cleared = false;

    protected readonly string $lockKey;

    /**
     * Create the cleanup state for a processing marker.
     */
    public function __construct(
        protected readonly Repository $cache,
        protected readonly string $key,
    ) {
        $this->lockKey = 'queue.processing-marker:' . spl_object_id($this);
    }

    /**
     * Clear the marker before allowing another attempt to become visible.
     */
    public function clear(): void
    {
        while (! $this->cleared) {
            if (! Locker::lock($this->lockKey)) {
                continue;
            }

            try {
                if (! $this->cache->forget($this->key) && $this->cache->has($this->key)) {
                    throw new RuntimeException("Unable to clear the job processing marker [{$this->key}].");
                }

                $this->cleared = true;
            } finally {
                Locker::unlock($this->lockKey);
            }
        }
    }
}
