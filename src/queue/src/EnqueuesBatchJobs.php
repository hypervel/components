<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use UnitEnum;

/** @internal */
interface EnqueuesBatchJobs
{
    /**
     * Check the addition against the caller's transaction before enrollment.
     */
    public function ensureBatchCanBeEnqueued(array $jobs, string $connection, bool $ownerHasTransaction): void;

    /**
     * Publish enrolled jobs and report confirmed or uncertain deliveries.
     * Sends that survive a rollback must wait for the named enrollment transaction to commit.
     *
     * @param array<int, object|string> $jobs
     * @param Closure(list<int>): void $onDeferredRejection
     */
    public function bulkForBatch(
        array $jobs,
        Closure $onDeferredRejection,
        mixed $data = '',
        UnitEnum|string|null $queue = null,
        ?string $batchTransactionConnection = null,
    ): BatchEnqueueResult;
}
