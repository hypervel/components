<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use ArrayAccess;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\ParentCoroutineContext;

class RequestContextSynchronizer
{
    /**
     * Sync selected Context keys from the current request coroutine to its parent.
     *
     * @param iterable<int, string> $keys
     */
    public function syncContextKeysToParent(iterable $keys): void
    {
        $context = CoroutineContext::getContainer();

        if ($context === null) {
            return;
        }

        $this->syncSnapshotToParent($context, $keys);
    }

    /**
     * Sync selected Context keys from a snapshot to the current coroutine's parent.
     *
     * @param array<string, mixed>|ArrayAccess<string, mixed> $snapshot
     * @param iterable<int, string> $keys
     */
    public function syncSnapshotToParent(array|ArrayAccess $snapshot, iterable $keys): void
    {
        foreach ($keys as $key) {
            if ($this->hasKey($snapshot, $key)) {
                ParentCoroutineContext::set($key, $snapshot[$key]);

                continue;
            }

            ParentCoroutineContext::forget($key);
        }
    }

    /**
     * Determine whether a snapshot has a key.
     *
     * @param array<string, mixed>|ArrayAccess<string, mixed> $snapshot
     */
    protected function hasKey(array|ArrayAccess $snapshot, string $key): bool
    {
        return $snapshot instanceof ArrayAccess
            ? $snapshot->offsetExists($key)
            : array_key_exists($key, $snapshot);
    }
}
