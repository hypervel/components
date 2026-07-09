<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\RefreshableLock;
use InvalidArgumentException;

class FileLock extends CacheLock implements RefreshableLock
{
    /**
     * Attempt to acquire the lock.
     */
    public function acquire(): bool
    {
        return $this->fileStore()->add($this->name, $this->owner, $this->seconds);
    }

    /**
     * Refresh the lock's TTL if still owned by this process.
     *
     * @throws InvalidArgumentException If an explicit non-positive TTL is provided
     */
    public function refresh(?int $seconds = null): bool
    {
        if ($seconds === null && $this->seconds <= 0) {
            return $this->isOwnedByCurrentProcess();
        }

        $seconds ??= $this->seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('Refresh requires a positive TTL.');
        }

        return $this->fileStore()->refreshIfOwned($this->name, $this->owner, $seconds);
    }

    /**
     * Get the number of seconds until the lock expires.
     */
    public function getRemainingLifetime(): ?float
    {
        return $this->fileStore()->remainingSeconds($this->name);
    }

    /**
     * Get the file store backing this lock.
     */
    protected function fileStore(): FileStore
    {
        /** @var FileStore */
        return $this->store;
    }
}
