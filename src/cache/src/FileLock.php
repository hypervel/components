<?php

declare(strict_types=1);

namespace Hypervel\Cache;

class FileLock extends CacheLock
{
    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        /* @phpstan-ignore-next-line */
        return $this->store->add($this->name, $this->owner, $this->seconds);
    }
}
