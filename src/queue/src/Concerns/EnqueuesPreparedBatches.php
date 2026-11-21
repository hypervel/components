<?php

declare(strict_types=1);

namespace Hypervel\Queue\Concerns;

use Hypervel\Queue\BatchEnqueueAttempt;
use Hypervel\Queue\Queue;
use ReflectionMethod;
use Throwable;

trait EnqueuesPreparedBatches
{
    /**
     * Keep immediate and deferred sends grouped while capturing their payloads at dispatch.
     */
    protected function enqueueBulk(array $jobs, mixed $data, ?string $queue, ?BatchEnqueueAttempt $attempt): mixed
    {
        $jobs = $this->stageOutboxJobs($jobs, $data, $queue, $attempt);
        if ($jobs === []) {
            return null;
        }

        $this->validateBulkJobs($jobs, $queue);
        $transactions = $this->container->has('db.transactions') ? $this->container->make('db.transactions') : null;
        $connection = $attempt?->batchTransactionConnection;
        if ($connection !== null && $this->batchWaitsForOwner($connection)) {
            [$deferred, $immediate] = [$jobs, []];
        } else {
            [$deferred, $immediate] = $this->partitionJobsByAfterCommit($jobs, $transactions);
        }

        if ($attempt === null && $deferred !== []) {
            $immediate = array_values($immediate);
            $deferred = array_values($deferred);
        }

        $result = $immediate === [] ? null : $this->publishPreparedBatch(
            $this->prepareBatchJobsWithOffsets($immediate, $data, $queue),
            $queue,
            $attempt,
        );
        if ($deferred === []) {
            return $result;
        }

        $prepared = $this->prepareBatchJobsWithOffsets($deferred, $data, $queue);
        $later = $attempt === null ? null : new BatchEnqueueAttempt($attempt->onDeferredRejection, null);
        $this->deferBatchEnqueueAfterCommit(
            $transactions,
            $deferred,
            static function (Queue $owner) use ($prepared, $queue, $later) {
                /** @var self $owner */
                return $owner->publishPreparedBatch($prepared, $queue, $later);
            },
            $connection,
            $later === null ? null : static fn (Throwable $failure) => $later->releaseRejected($failure, $deferred),
        );
        $attempt?->attempting(array_keys($deferred));

        return $result;
    }

    /**
     * Identify protected transport hooks that implement their own delivery lifecycle.
     */
    private function usesCustomBatchWriter(string $class, string $method): bool
    {
        return $this::class !== $class && (new ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== $class;
    }

    /**
     * Validate driver-specific delivery constraints before publishing either group.
     */
    protected function validateBulkJobs(array $jobs, ?string $queue): void
    {
    }
}
