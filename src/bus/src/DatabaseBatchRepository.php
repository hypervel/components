<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Closure;
use DateTimeInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Throwable;

class DatabaseBatchRepository implements PrunableBatchRepository
{
    protected const string DRIVER = 'database';

    public const string DEFAULT_RESULTS_TABLE = 'job_batch_results';

    public const string DEFAULT_DELETIONS_TABLE = 'job_batch_deletions';

    /**
     * Create a new batch repository instance.
     */
    public function __construct(
        protected BatchFactory $factory,
        protected ConnectionResolverInterface $resolver,
        protected string $table,
        protected ?string $connection = null,
        protected string $resultsTable = self::DEFAULT_RESULTS_TABLE,
        protected string $deletionsTable = self::DEFAULT_DELETIONS_TABLE,
    ) {
    }

    /**
     * Retrieve a list of batches.
     *
     * @return Batch[]
     */
    public function get(int $limit = 50, mixed $before = null): array
    {
        $rows = $this->getConnection()->table($this->table)
            ->orderByDesc('id')
            ->limit($limit)
            ->when($before !== null && $before !== '', fn ($q) => $q->where('id', '<', $before))
            ->get();

        return array_values($this->toBatches($rows->all()));
    }

    /**
     * Hydrate a page of batches keyed by ID.
     *
     * @param array<array-key, object> $rows
     * @return array<string, Batch>
     */
    protected function toBatches(array $rows): array
    {
        $batches = [];
        foreach ($rows as $row) {
            $batches[$row->id] = $this->toBatch($row);
        }

        return $batches;
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
     * Load current progress for the requested batches in descending ID order.
     *
     * @param list<string> $ids
     * @return list<Batch>
     */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->getConnection()->table($this->table)->useWritePdo()
            ->whereIn('id', $ids)->orderByDesc('id')->get();

        return array_values($this->toBatches($rows->all()));
    }

    /**
     * Get the retry lookup IDs for jobs that have failed and have yet to succeed.
     */
    public function getFailedJobIds(string $batchId): array
    {
        return $this->getConnection()->table($this->resultsTable)->useWritePdo()
            ->where('batch_id', $batchId)->whereNotNull('retry_id')->pluck('retry_id')->all();
    }

    /**
     * Determine if this logical job has an outstanding failure.
     */
    public function hasFailedJob(string $batchId, string $jobId): bool
    {
        return $this->getConnection()->table($this->resultsTable)->useWritePdo()
            ->where('batch_id', $batchId)->where('job_id_hash', hash('xxh128', $jobId))
            ->whereNotNull('retry_id')->exists();
    }

    /**
     * Store a new pending batch.
     */
    public function store(PendingBatch $batch): Batch
    {
        return $this->toBatch($this->insertBatch($batch));
    }

    /**
     * Insert the batch and return its initial values.
     */
    protected function insertBatch(PendingBatch $batch): object
    {
        $row = [
            'id' => (string) Str::orderedUuid(),
            'driver' => static::DRIVER,
            'name' => $batch->name,
            'total_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'outstanding_failed_jobs' => 0,
            'options' => $this->serialize($batch->options),
            'created_at' => time(),
            'cancelled_at' => null,
            'finished_at' => null,
        ];
        $this->getConnection()->table($this->table)->insert($row);

        return (object) $row;
    }

    /**
     * Increment the total number of jobs within the batch.
     */
    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        $this->updateEnrollment($batchId, $amount, [
            'previous_first_pass_total' => new Expression('case when total_jobs > 0 and pending_jobs = outstanding_failed_jobs then total_jobs else previous_first_pass_total end'),
            'previous_success_total' => new Expression('case when total_jobs > 0 and pending_jobs = 0 then total_jobs else previous_success_total end'),
            'pending_jobs' => new Expression('pending_jobs + ' . $amount),
        ]);
    }

    /**
     * Preserve the finish and previously reached callback totals so a rejected addition can be undone.
     */
    protected function updateEnrollment(string $batchId, int $amount, array $values): void
    {
        // MySQL and MariaDB read earlier assignments in SET, so sample the old row first.
        $updated = $this->getConnection()->table($this->table)->where('id', $batchId)->update([
            'previous_finished_total' => new Expression('case when finished_at is not null then total_jobs else previous_finished_total end'),
            'previous_finished_at' => new Expression('coalesce(finished_at, previous_finished_at)'),
            ...$values,
            'total_jobs' => new Expression('total_jobs + ' . $amount),
            'finished_at' => null,
        ]);

        if ($amount > 0 && $updated === 0) {
            throw new BatchNotFoundException("Batch [{$batchId}] no longer exists.");
        }
    }

    /**
     * Reserve the SQLite writer before reading, as its grammar omits FOR UPDATE.
     */
    protected function lockParents(array $ids): Builder
    {
        $connection = $this->getConnection();
        $query = $connection->table($this->table)->whereIn('id', $ids);
        if ($connection instanceof SQLiteConnection) {
            (clone $query)->update(['total_jobs' => new Expression('total_jobs')]);
        }

        return $query->orderBy('id')->lockForUpdate();
    }

    /**
     * Withdraw jobs the queue definitely rejected and report newly reached completion conditions.
     */
    public function releaseJobs(string $batchId, int $count): ?UpdatedBatchJobCounts
    {
        $connection = $this->getConnection();

        return $connection->transaction(function () use ($batchId, $count) {
            $parent = $this->lockParents([$batchId]);
            $row = (clone $parent)->first();

            return $row === null ? null : $this->releaseFromBatch($parent, $row, $count);
        });
    }

    /**
     * Correct enrollment against results read under the parent lock.
     */
    protected function releaseFromBatch(Builder $parent, object $row, int $count): ?UpdatedBatchJobCounts
    {
        $total = (int) $row->total_jobs - $count;
        $pending = (int) $row->pending_jobs - $count;
        $outstanding = (int) $row->outstanding_failed_jobs;
        $firstPass = $total > 0 && $pending === $outstanding && (int) $row->previous_first_pass_total !== $total;
        $successful = $total > 0 && $pending === 0 && (int) $row->previous_success_total !== $total;

        $values = ['total_jobs' => $total];
        if (static::DRIVER === 'database') {
            $values['pending_jobs'] = $pending;
        }

        if ($firstPass) {
            $values['previous_first_pass_total'] = $total;
        }
        if ($successful) {
            $values['previous_success_total'] = $total;
        }

        $finish = $row->finished_at;
        if ($finish === null && $row->previous_finished_at !== null && (int) $row->previous_finished_total === $total) {
            $finish = $values['finished_at'] = $row->previous_finished_at;
        }
        if ($finish === null && ($successful || ($firstPass && ($this->unserialize($row->options)['allowFailures'] ?? false) === true))) {
            $finish = $values['finished_at'] = time();
        }

        $parent->update($values);

        return $firstPass || $successful ? new UpdatedBatchJobCounts(
            $pending,
            (int) $row->failed_jobs,
            $firstPass,
            $total,
            $outstanding,
            $finish === null ? null : CarbonImmutable::createFromTimestamp((int) $finish, date_default_timezone_get()),
        ) : null;
    }

    /**
     * Record a job's first success, clearing any outstanding failure for the same position.
     */
    public function decrementPendingJobs(string $batchId, string $jobId): ?UpdatedBatchJobCounts
    {
        return $this->recordJobResult($batchId, $jobId, null);
    }

    /**
     * Count each failure up to the first success, retaining the latest retry lookup ID.
     */
    public function incrementFailedJobs(string $batchId, string $jobId, ?string $retryId = null): ?UpdatedBatchJobCounts
    {
        return $this->recordJobResult($batchId, $jobId, $retryId ?? $jobId);
    }

    /**
     * Claim the job result before locking its batch so every delivery uses the same lock order.
     */
    protected function recordJobResult(string $batchId, string $jobId, ?string $retryId): ?UpdatedBatchJobCounts
    {
        $connection = $this->getConnection();
        $key = ['batch_id' => $batchId, 'job_id_hash' => hash('xxh128', $jobId)];

        return $connection->transaction(function () use ($connection, $key, $batchId, $retryId) {
            if ($retryId === null && $connection instanceof PostgresConnection) {
                return $this->recordPostgresSuccess($connection, $key);
            }

            $result = $connection->table($this->resultsTable);
            $inserted = $result->insertOrIgnore($key + ['retry_id' => $retryId]) === 1;
            $result->where($key);

            if (! $inserted) {
                if ($retryId === null) {
                    if ($result->whereNotNull('retry_id')->update(['retry_id' => null]) === 0) {
                        return null;
                    }
                } else {
                    $previousRetryId = $result->lockForUpdate()->value('retry_id');
                    if ($previousRetryId === null) {
                        return null;
                    }
                    if ($previousRetryId !== $retryId) {
                        $result->update(['retry_id' => $retryId]);
                    }
                }
            }

            $parent = $connection->table($this->table)->where('id', $batchId);
            $batch = (clone $parent)->lockForUpdate()->first([
                'total_jobs', 'pending_jobs', 'failed_jobs', 'outstanding_failed_jobs', 'finished_at',
            ]);

            if ($batch === null) {
                $connection->table($this->resultsTable)->where($key)->delete();
                return null;
            }

            $pending = (int) $batch->pending_jobs;
            $failed = (int) $batch->failed_jobs;
            $outstanding = (int) $batch->outstanding_failed_jobs;

            if ($retryId === null) {
                $values = ['pending_jobs' => --$pending];
                if (! $inserted) {
                    $values['outstanding_failed_jobs'] = --$outstanding;
                }
            } else {
                $values = ['failed_jobs' => ++$failed];
                if ($inserted) {
                    $values['outstanding_failed_jobs'] = ++$outstanding;
                }
            }

            $completedFirstPass = $inserted && $pending === $outstanding;
            if ($batch->finished_at === null && ($pending === 0 || ($completedFirstPass && $this->allowsFailures($parent)))) {
                $values['finished_at'] = time();
            }
            $parent->update($values);

            return new UpdatedBatchJobCounts($pending, $failed, $completedFirstPass, (int) $batch->total_jobs);
        }, attempts: 3);
    }

    /**
     * Claim an ordinary success and update its parent in one PostgreSQL statement.
     *
     * @param array{batch_id: string, job_id_hash: string} $key
     */
    protected function recordPostgresSuccess(PostgresConnection $connection, array $key): ?UpdatedBatchJobCounts
    {
        $grammar = $connection->getQueryGrammar();
        $results = $grammar->wrapTable($this->resultsTable);
        $batches = $grammar->wrapTable($this->table);
        $finish = 'case when pending_jobs = 1 then coalesce(finished_at, ?) else finished_at end';
        $columns = 'total_jobs, pending_jobs, failed_jobs, outstanding_failed_jobs, finished_at';

        // A conflict returns no row. A new result whose parent was deleted returns null counters.
        $row = $connection->selectOne(
            "with inserted as (
                insert into {$results} (batch_id, job_id_hash) values (?, ?)
                on conflict do nothing returning batch_id
            ), updated as (
                update {$batches} as parent set pending_jobs = pending_jobs - 1, finished_at = {$finish}
                from inserted where parent.id = inserted.batch_id returning {$columns}
            ) select updated.* from inserted left join updated on true",
            [$key['batch_id'], $key['job_id_hash'], time()],
            false,
        );
        $inserted = $row !== null;
        $connection->recordsHaveBeenModified($inserted);

        if (! $inserted) {
            if ($connection->table($this->resultsTable)->where($key)->whereNotNull('retry_id')->update(['retry_id' => null]) === 0) {
                return null;
            }

            $row = $connection->selectOne(
                "update {$batches} set pending_jobs = pending_jobs - 1,
                    outstanding_failed_jobs = outstanding_failed_jobs - 1, finished_at = {$finish}
                where id = ? returning {$columns}",
                [time(), $key['batch_id']],
                false,
            );
        }

        if (($row->pending_jobs ?? null) === null) {
            $connection->table($this->resultsTable)->where($key)->delete();
            return null;
        }

        $pending = (int) $row->pending_jobs;
        $completedFirstPass = $inserted && $pending === (int) $row->outstanding_failed_jobs;

        if ($completedFirstPass && $pending > 0 && $row->finished_at === null) {
            $parent = $connection->table($this->table)->where('id', $key['batch_id']);
            if ($this->allowsFailures($parent)) {
                $parent->update(['finished_at' => time()]);
            }
        }

        return new UpdatedBatchJobCounts($pending, (int) $row->failed_jobs, $completedFirstPass, (int) $row->total_jobs);
    }

    /**
     * Read batch options only when a first pass could finish with outstanding failures.
     */
    protected function allowsFailures(Builder $parent): bool
    {
        return ($this->unserialize($parent->lockForUpdate()->value('options'))['allowFailures'] ?? false) === true;
    }

    // REMOVED: updateAtomicValues() locked only the parent. Accounting now claims the job and updates both rows in one transaction.

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
     * Delete the batch and its recorded results.
     */
    public function delete(string $batchId): void
    {
        $this->deleteBatches($this->getConnection()->table($this->table), [$batchId]);
    }

    /**
     * Remove a failed dispatch only while it has no enrolled jobs.
     */
    public function deleteIfEmpty(string $batchId): void
    {
        $this->deleteBatches($this->getConnection()->table($this->table)->where('total_jobs', 0), [$batchId]);
    }

    /**
     * Prune all of the entries older than the given date.
     */
    public function prune(DateTimeInterface $before): int
    {
        return $this->pruneBatches($this->getConnection()->table($this->table)
            ->where('finished_at', '<', $before->getTimestamp()));
    }

    /**
     * Prune all of the unfinished entries older than the given date.
     */
    public function pruneUnfinished(DateTimeInterface $before): int
    {
        return $this->pruneBatches($this->getConnection()->table($this->table)
            ->whereNull('finished_at')->where('created_at', '<', $before->getTimestamp()));
    }

    /**
     * Prune all of the cancelled entries older than the given date.
     */
    public function pruneCancelled(DateTimeInterface $before): int
    {
        return $this->pruneBatches($this->getConnection()->table($this->table)
            ->whereNotNull('cancelled_at')->where('created_at', '<', $before->getTimestamp()));
    }

    /**
     * Bound each deletion transaction by the number of result rows its batches can own.
     */
    protected function pruneBatches(Builder $query): int
    {
        $total = 0;
        $query->where('driver', static::DRIVER);
        $page = (clone $query)->select(['id', 'total_jobs'])->useWritePdo();
        $nested = $query->getConnection()->transactionLevel() > 0;
        $prunePage = function (Collection $batches) use ($query, $nested, &$total): void {
            $ids = [];
            $jobs = 0;
            foreach ($batches as $batch) {
                if ($ids !== [] && $jobs + $batch->total_jobs > 10000) {
                    $total += $this->deleteBatches($query, $ids, $nested);
                    $ids = [];
                    $jobs = 0;
                }
                $ids[] = $batch->id;
                $jobs += $batch->total_jobs;
            }
            $total += $this->deleteBatches($query, $ids, $nested);
        };

        if ($nested) {
            // A repeatable-read snapshot can keep returning a batch another writer reopened or deleted.
            $page->chunkById(1000, $prunePage);
        } else {
            $page->limit(1000);
            while (($batches = $page->get())->isNotEmpty()) {
                $prunePage($batches);
            }
        }

        return $total;
    }

    /**
     * Remove results in the same child-first lock order as accounting.
     *
     * @param list<string> $ids
     */
    protected function deleteBatches(Builder $query, array $ids, bool $retryIndividually = false): int
    {
        $connection = $query->getConnection();

        try {
            return $connection->transaction(function () use ($query, $ids) {
                $this->deleteJobResults($ids);

                $deleted = (clone $query)->whereIn('id', $ids)->delete();
                if ($deleted !== count($ids)) {
                    // Roll back the first sweep too: a reopened batch still needs its results.
                    throw new StaleBatchSelectionException;
                }

                // A result may have committed while the parent delete waited.
                $this->deleteJobResults($ids);

                return $deleted;
            }, attempts: 3);
        } catch (StaleBatchSelectionException) {
            $deleted = 0;
            if ($retryIndividually && count($ids) > 1) {
                foreach ($ids as $id) {
                    $deleted += $this->deleteBatches($query, [$id]);
                }
            }

            return $deleted;
        }
    }

    /**
     * Record external cleanup in the transaction that deletes the parent.
     */
    protected function recordResultDeletions(array $ids): void
    {
        $connection = $this->resolver->connection($this->connection);
        $connection->table($this->deletionsTable)->insert(array_map(
            static fn ($id) => ['batch_id' => $id, 'driver' => static::DRIVER],
            $ids,
        ));
        $connection->afterCommit(fn () => $this->completeResultDeletions($ids));
    }

    /**
     * Recover this driver's committed cleanup work on a normal prune.
     */
    protected function drainResultDeletions(): void
    {
        // A prune inside a transaction can see its own uncommitted deletions.
        $this->resolver->connection($this->connection)->afterCommitOrNow(function () {
            $this->getConnection()->table($this->deletionsTable)->useWritePdo()
                ->where('driver', static::DRIVER)->chunkById(100, function (Collection $rows) {
                    $this->completeResultDeletions($rows->pluck('batch_id')->all());
                }, 'batch_id');
        });
    }

    /**
     * Clear the cleanup records only after the driver has deleted the results.
     */
    private function completeResultDeletions(array $ids): void
    {
        $this->deleteJobResults($ids);
        $this->getConnection()->table($this->deletionsTable)->where('driver', static::DRIVER)
            ->whereIn('batch_id', $ids)->delete();
    }

    /**
     * Delete this driver's results for the given batches.
     */
    protected function deleteJobResults(array $ids): void
    {
        $this->getConnection()->table($this->resultsTable)->whereIn('batch_id', $ids)->delete();
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
            $batch->pending_jobs === null ? null : (int) $batch->pending_jobs,
            $batch->failed_jobs === null ? null : (int) $batch->failed_jobs,
            $batch->outstanding_failed_jobs !== null && (int) $batch->outstanding_failed_jobs === 0 ? [] : null,
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
