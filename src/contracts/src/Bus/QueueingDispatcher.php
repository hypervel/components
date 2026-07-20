<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Bus;

use Hypervel\Bus\Batch;
use Hypervel\Bus\PendingBatch;

interface QueueingDispatcher extends Dispatcher
{
    /**
     * Attempt to find the batch with the given ID.
     */
    public function findBatch(string $batchId): ?Batch;

    /**
     * Create a new batch of queueable jobs.
     */
    public function batch(mixed $jobs): PendingBatch;

    /**
     * Dispatch an iterable of jobs in bulk.
     */
    public function bulk(iterable $jobs): void;

    /**
     * Dispatch a command to its appropriate handler behind a queue.
     */
    public function dispatchToQueue(mixed $command): mixed;
}
