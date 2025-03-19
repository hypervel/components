<?php

declare(strict_types=1);

namespace Hypervel\Cache;

trait HasCacheLock
{
    /**
     * Get a lock instance.
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): CacheLock
    {
        return new CacheLock($this, $name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     */
    public function restoreLock(string $name, string $owner): CacheLock
    {
        return $this->lock($name, 0, $owner);
    }
}
