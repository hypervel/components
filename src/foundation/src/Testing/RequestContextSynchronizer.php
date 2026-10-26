<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use ArrayAccess;
use Hypervel\Context\CoroutineContext;
use Hypervel\Context\ParentCoroutineContext;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionState;

class RequestContextSynchronizer
{
    /**
     * Copy the retained database sessions' transaction records between test coroutines.
     *
     * @param array<string, ConnectionInterface> $connections
     */
    public function syncTransactionState(
        DatabaseTransactionState $source,
        DatabaseTransactionState $target,
        array $connections,
    ): void {
        $names = [];

        foreach ($connections as $connection) {
            $names[$connection->getNameWithReadWriteType() ?? ''] = true;
        }

        foreach (['pending', 'committed'] as $property) {
            // Keep surviving records in their original order: connection-less callbacks
            // belong to the most recently started applicable transaction.
            $target->{$property} = $target->{$property}->reject(
                fn (DatabaseTransactionRecord $transaction): bool => isset($names[$transaction->connection])
                    && ! $source->{$property}->containsStrict($transaction)
            )->concat($source->{$property}->filter(
                fn (DatabaseTransactionRecord $transaction): bool => isset($names[$transaction->connection])
            ))->uniqueStrict()->values();
        }

        $target->current = array_diff_key($target->current, $names)
            + array_intersect_key($source->current, $names);
    }

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
