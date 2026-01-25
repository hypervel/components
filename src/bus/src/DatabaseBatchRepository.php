<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Hypervel\Support\Str;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Query\Expression;
use Hypervel\Contracts\Bus\PrunableBatchRepository;
use Throwable;

class DatabaseBatchRepository implements PrunableBatchRepository
{
    /**
     * Create a new batch repository instance.
     *
     * @param BatchFactory $factory the batch factory instance
     * @param ConnectionResolverInterface $resolver the database connection resolver
     * @param string $connection the connection name of database to use to store batch information
     * @param string $table the database table to use to store batch information
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
        return $this->connection()->table($this->table)
            ->orderByDesc('id')
            ->take($limit)
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->get()
            ->map(function ($batch) {
                return $this->toBatch($batch);
            })->all();
    }

    /**
     * Retrieve information about an existing batch.
     */
    public function find(int|string $batchId): ?Batch
    {
        $batch = $this->connection()->table($this->table)
            ->useWritePdo()
            ->where('id', $batchId)
            ->first();

        return $batch ? $this->toBatch($batch) : null;
    }

    /**
     * Store a new pending batch.
     */
    public function store(PendingBatch $batch): ?Batch
    {
        $id = (string) Str::orderedUuid();

        $this->connection()->table($this->table)->insert([
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

        return $this->find($id);
    }

    /**
     * Increment the total number of jobs within the batch.
     */
    public function incrementTotalJobs(int|string $batchId, int $amount): void
    {
        $this->connection()->table($this->table)->where('id', $batchId)->update([
            'total_jobs' => new Expression('total_jobs + ' . $amount),
            'pending_jobs' => new Expression('pending_jobs + ' . $amount),
            'finished_at' => null,
        ]);
    }

    /**
     * Decrement the total number of pending jobs for the batch.
     */
    public function decrementPendingJobs(int|string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        $values = $this->updateAtomicValues($batchId, function ($batch) use ($jobId) {
            return [
                'pending_jobs' => $batch->pending_jobs - 1,
                'failed_jobs' => $batch->failed_jobs,
                'failed_job_ids' => json_encode(array_values(array_diff((array) json_decode($batch->failed_job_ids, true), [$jobId]))),
            ];
        });

        return new UpdatedBatchJobCounts(
            $values['pending_jobs'],
            $values['failed_jobs']
        );
    }

    /**
     * Increment the total number of failed jobs for the batch.
     */
    public function incrementFailedJobs(int|string $batchId, string $jobId): UpdatedBatchJobCounts
    {
        $values = $this->updateAtomicValues($batchId, function ($batch) use ($jobId) {
            return [
                'pending_jobs' => $batch->pending_jobs,
                'failed_jobs' => $batch->failed_jobs + 1,
                'failed_job_ids' => json_encode(array_values(array_unique(array_merge((array) json_decode($batch->failed_job_ids, true), [$jobId])))),
            ];
        });

        return new UpdatedBatchJobCounts(
            $values['pending_jobs'],
            $values['failed_jobs']
        );
    }

    /**
     * Update an atomic value within the batch.
     */
    protected function updateAtomicValues(int|string $batchId, Closure $callback): ?array
    {
        return $this->connection()->transaction(function () use ($batchId, $callback) {
            $batch = $this->connection()->table($this->table)->where('id', $batchId)
                ->lockForUpdate()
                ->first();

            return is_null($batch) ? [] : tap($callback($batch), function ($values) use ($batchId) {
                $this->connection()->table($this->table)->where('id', $batchId)->update($values);
            });
        });
    }

    /**
     * Mark the batch that has the given ID as finished.
     */
    public function markAsFinished(int|string $batchId): void
    {
        $this->connection()->table($this->table)->where('id', $batchId)->update([
            'finished_at' => time(),
        ]);
    }

    /**
     * Cancel the batch that has the given ID.
     */
    public function cancel(int|string $batchId): void
    {
        $this->connection()->table($this->table)->where('id', $batchId)->update([
            'cancelled_at' => time(),
            'finished_at' => time(),
        ]);
    }

    /**
     * Delete the batch that has the given ID.
     */
    public function delete(int|string $batchId): void
    {
        $this->connection()->table($this->table)->where('id', $batchId)->delete();
    }

    /**
     * Prune all of the entries older than the given date.
     */
    public function prune(DateTimeInterface $before): int
    {
        $query = $this->connection()->table($this->table)
            ->whereNotNull('finished_at')
            ->where('finished_at', '<', $before->getTimestamp());

        $totalDeleted = 0;

        do {
            $deleted = $query->take(1000)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Prune all of the unfinished entries older than the given date.
     */
    public function pruneUnfinished(DateTimeInterface $before): int
    {
        $query = $this->connection()->table($this->table)
            ->whereNull('finished_at')
            ->where('created_at', '<', $before->getTimestamp());

        $totalDeleted = 0;

        do {
            $deleted = $query->take(1000)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Prune all of the cancelled entries older than the given date.
     */
    public function pruneCancelled(DateTimeInterface $before): int
    {
        $query = $this->connection()->table($this->table)
            ->whereNotNull('cancelled_at')
            ->where('created_at', '<', $before->getTimestamp());

        $totalDeleted = 0;

        do {
            $deleted = $query->take(1000)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Execute the given Closure within a storage specific transaction.
     */
    public function transaction(Closure $callback): mixed
    {
        return $this->connection()->transaction(fn () => $callback());
    }

    /**
     * Rollback the last database transaction for the connection.
     */
    public function rollBack(): void
    {
        $this->connection()->rollBack();
    }

    /**
     * Serialize the given value.
     */
    protected function serialize(mixed $value): string
    {
        $serialized = serialize($value);

        return $this->isPostgresConnection()
            ? base64_encode($serialized)
            : $serialized;
    }

    /**
     * Check if the connection is a Postgres connection.
     */
    protected function isPostgresConnection(): bool
    {
        /* @phpstan-ignore-next-line */
        return in_array($this->connection()->getDriverName(), ['pgsql', 'pgsql-swoole']);
    }

    /**
     * Unserialize the given value.
     */
    protected function unserialize(string $serialized): mixed
    {
        if ($this->isPostgresConnection()
            && ! Str::contains($serialized, [':', ';'])
        ) {
            $serialized = base64_decode($serialized);
        }

        try {
            return unserialize($serialized);
        } catch (Throwable) {
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
    public function connection(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }

    /**
     * Set the connection name to be used.
     */
    public function setConnection(string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }
}
