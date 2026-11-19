<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use DateTimeInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Expression;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Str;
use RuntimeException;
use Throwable;

class DatabaseBatchRepository implements PrunableBatchRepository
{
    /**
     * Create a new batch repository instance.
     */
    public function __construct(
        protected BatchFactory $factory,
        protected ConnectionResolverInterface $resolver,
        protected string $table,
        protected ?string $connection = null,
    ) {
    }

    /**
     * Retrieve a list of batches.
     *
     * @return Batch[]
     */
    public function get(int $limit = 50, mixed $before = null): array
    {
        return $this->getConnection()->table($this->table)
            ->orderByDesc('id')
            ->limit($limit)
            ->when($before !== null && $before !== '', fn ($q) => $q->where('id', '<', $before))
            ->get()
            ->map(function ($batch) {
                return $this->toBatch($batch);
            })
            ->all();
    }

    /**
     * Retrieve information about an existing batch.
     */
    public function find(string $batchId): ?Batch
    {
        $batch = $this->getConnection()->table($this->table)
            ->useWritePdo()
            ->where('id', $batchId)
            ->first();

        return $batch ? $this->toBatch($batch) : null;
    }

    /**
     * Store a new pending batch.
     */
    public function store(PendingBatch $batch): Batch
    {
        $id = (string) Str::orderedUuid();

        $this->getConnection()->table($this->table)->insert([
            'id' => $id,
            'name' => $batch->name,
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => $this->serialize($batch->options),
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ]);

        $storedBatch = $this->find($id);

        if ($storedBatch === null) {
            throw new RuntimeException("Failed to retrieve stored batch [{$id}].");
        }

        return $storedBatch;
    }

    /**
     * Increment the total number of jobs within the batch.
     */
    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        $this->getConnection()->table($this->table)->where('id', $batchId)->update([
            'total_jobs' => new Expression('total_jobs + ' . $amount),
            'pending_jobs' => new Expression('pending_jobs + ' . $amount),
            'finished_at' => null,
        ]);
    }

    /**
     * Decrement the total number of pending jobs for the batch.
     */
    public function decrementPendingJobs(string $batchId, string $jobId): ?UpdatedBatchJobCounts
    {
        $completedFirstPass = false;

        $values = $this->updateAtomicValues($batchId, function ($batch) use ($jobId, &$completedFirstPass) {
            $failedJobIds = (array) json_decode($batch->failed_job_ids, true);
            $remainingFailedJobIds = array_values(array_diff($failedJobIds, [$jobId]));
            $pendingJobs = $batch->pending_jobs - 1;

            $completedFirstPass = $this->completesFirstPass(
                $batch->pending_jobs - count($failedJobIds),
                $pendingJobs - count($remainingFailedJobIds),
            );

            return [
                'pending_jobs' => $pendingJobs,
                'failed_jobs' => $batch->failed_jobs,
                'failed_job_ids' => json_encode($remainingFailedJobIds),
            ] + $this->finishWhenOnlyFailuresRemain($batch, $pendingJobs, $completedFirstPass);
        });

        return $values === null ? null : new UpdatedBatchJobCounts(
            $values['pending_jobs'],
            $values['failed_jobs'],
            $completedFirstPass,
        );
    }

    /**
     * Increment the total number of failed jobs for the batch.
     */
    public function incrementFailedJobs(string $batchId, string $jobId): ?UpdatedBatchJobCounts
    {
        $completedFirstPass = false;

        $values = $this->updateAtomicValues($batchId, function ($batch) use ($jobId, &$completedFirstPass) {
            $failedJobIds = (array) json_decode($batch->failed_job_ids, true);
            $updatedFailedJobIds = array_values(array_unique(array_merge($failedJobIds, [$jobId])));

            $completedFirstPass = $this->completesFirstPass(
                $batch->pending_jobs - count($failedJobIds),
                $batch->pending_jobs - count($updatedFailedJobIds),
            );

            return [
                'pending_jobs' => $batch->pending_jobs,
                'failed_jobs' => $batch->failed_jobs + 1,
                'failed_job_ids' => json_encode($updatedFailedJobIds),
            ] + $this->finishWhenOnlyFailuresRemain($batch, $batch->pending_jobs, $completedFirstPass);
        });

        return $values === null ? null : new UpdatedBatchJobCounts(
            $values['pending_jobs'],
            $values['failed_jobs'],
            $completedFirstPass,
        );
    }

    /**
     * Determine if an update ran the batch's last job that had not yet run.
     *
     * Failed jobs stay pending until they succeed, so the jobs that have not yet run are the pending
     * jobs minus the distinct failed jobs. A job that fails again on retry is only counted once.
     */
    protected function completesFirstPass(int $notYetRunBefore, int $notYetRunAfter): bool
    {
        return $notYetRunBefore > 0 && $notYetRunAfter <= 0;
    }

    /**
     * Get the finish time to store when only failed jobs remain in a batch that allows failures.
     *
     * Unlike Laravel, the batch is finished in the same locked update instead of after the job's callbacks, so a job
     * added to the batch at the same moment reopens it instead of being stranded in a finished batch. An existing
     * finish time, such as a cancellation's, is kept.
     *
     * @return array{finished_at?: int}
     */
    protected function finishWhenOnlyFailuresRemain(object $batch, int $pendingJobs, bool $completedFirstPass): array
    {
        if (! $completedFirstPass || $pendingJobs <= 0 || $batch->finished_at !== null) {
            return [];
        }

        return ($this->unserialize($batch->options)['allowFailures'] ?? false) === true
            ? ['finished_at' => time()]
            : [];
    }

    /**
     * Update an atomic value within the batch.
     */
    protected function updateAtomicValues(string $batchId, Closure $callback): ?array
    {
        return $this->getConnection()->transaction(function () use ($batchId, $callback) {
            $batch = $this->getConnection()->table($this->table)->where('id', $batchId)
                ->lockForUpdate()
                ->first();

            return is_null($batch) ? null : tap($callback($batch), function ($values) use ($batchId) {
                $this->getConnection()->table($this->table)->where('id', $batchId)->update($values);
            });
        });
    }

    /**
     * Mark the batch that has the given ID as finished.
     */
    public function markAsFinished(string $batchId): void
    {
        $this->getConnection()->table($this->table)->where('id', $batchId)->update([
            'finished_at' => time(),
        ]);
    }

    /**
     * Cancel the batch that has the given ID.
     */
    public function cancel(string $batchId): void
    {
        $this->getConnection()->table($this->table)->where('id', $batchId)->update([
            'cancelled_at' => time(),
            'finished_at' => time(),
        ]);
    }

    /**
     * Delete the batch that has the given ID.
     */
    public function delete(string $batchId): void
    {
        $this->getConnection()->table($this->table)->where('id', $batchId)->delete();
    }

    /**
     * Prune all of the entries older than the given date.
     */
    public function prune(DateTimeInterface $before): int
    {
        $query = $this->getConnection()->table($this->table)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $before->getTimestamp());

        $totalDeleted = 0;

        do {
            $deleted = $query->limit(1000)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Prune all of the unfinished entries older than the given date.
     */
    public function pruneUnfinished(DateTimeInterface $before): int
    {
        $query = $this->getConnection()->table($this->table)
            ->whereNull('finished_at')
            ->where('created_at', '<', $before->getTimestamp());

        $totalDeleted = 0;

        do {
            $deleted = $query->limit(1000)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Prune all of the cancelled entries older than the given date.
     */
    public function pruneCancelled(DateTimeInterface $before): int
    {
        $query = $this->getConnection()->table($this->table)
            ->whereNotNull('cancelled_at')
            ->where('created_at', '<', $before->getTimestamp());

        $totalDeleted = 0;

        do {
            $deleted = $query->limit(1000)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Execute the given Closure within a storage specific transaction.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function transaction(Closure $callback): mixed
    {
        return $this->getConnection()->transaction(fn () => $callback());
    }

    /**
     * Rollback the last database transaction for the connection.
     */
    public function rollBack(): void
    {
        $this->getConnection()->rollBack();
    }

    /**
     * Serialize the given value.
     */
    protected function serialize(mixed $value): string
    {
        $serialized = serialize($value);

        return $this->getConnection() instanceof PostgresConnection
            ? base64_encode($serialized)
            : $serialized;
    }

    /**
     * Unserialize the given value.
     */
    protected function unserialize(string $serialized): mixed
    {
        if ($this->getConnection() instanceof PostgresConnection
            && ! Str::contains($serialized, [':', ';'])
        ) {
            $serialized = base64_decode($serialized);
        }

        try {
            return unserialize($serialized);
        } catch (Throwable $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

            return [];
        }
    }

    /**
     * Convert the given raw batch to a Batch object.
     */
    protected function toBatch(object $batch): Batch
    {
        return $this->factory->make(
            $this,
            $batch->id,
            $batch->name,
            (int) $batch->total_jobs,
            (int) $batch->pending_jobs,
            (int) $batch->failed_jobs,
            (array) json_decode($batch->failed_job_ids, true),
            $this->unserialize($batch->options),
            CarbonImmutable::createFromTimestamp($batch->created_at, date_default_timezone_get()),
            $batch->cancelled_at ? CarbonImmutable::createFromTimestamp($batch->cancelled_at, date_default_timezone_get()) : $batch->cancelled_at,
            $batch->finished_at ? CarbonImmutable::createFromTimestamp($batch->finished_at, date_default_timezone_get()) : $batch->finished_at
        );
    }

    /**
     * Get the underlying database connection.
     */
    public function getConnection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    // REMOVED: A mutable connection override would race across coroutines on this worker singleton.
}
