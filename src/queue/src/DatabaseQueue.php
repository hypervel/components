<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue as QueueContract;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder;
use Hypervel\Queue\Concerns\InsertsDatabaseRows;
use Hypervel\Queue\Jobs\DatabaseJob;
use Hypervel\Queue\Jobs\DatabaseJobRecord;
use Hypervel\Queue\Jobs\InspectedJob;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Swoole\Coroutine\CanceledException;
use Throwable;

class DatabaseQueue extends Queue implements QueueContract, ClearableQueue
{
    use InsertsDatabaseRows;

    public const int DEFAULT_RETRY_AFTER = 60;

    /**
     * Create a new database queue instance.
     *
     * @param ConnectionResolverInterface $resolver the database connection resolver instance
     * @param null|string $connection the database connection that holds the jobs
     * @param string $table the database table that holds the jobs
     * @param string $default the name of the default queue
     * @param int $retryAfter the expiration time of a job
     */
    public function __construct(
        protected ConnectionResolverInterface $resolver,
        protected ?string $connection,
        protected string $table,
        protected string $default = 'default',
        protected int $retryAfter = self::DEFAULT_RETRY_AFTER,
        protected bool $dispatchAfterCommit = false
    ) {
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->count();
    }

    /**
     * Get the number of pending jobs.
     */
    public function pendingSize(?string $queue = null): int
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $this->currentTime())
            ->count();
    }

    /**
     * Get the number of delayed jobs.
     */
    public function delayedSize(?string $queue = null): int
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNull('reserved_at')
            ->where('available_at', '>', $this->currentTime())
            ->count();
    }

    /**
     * Get the number of reserved jobs.
     */
    public function reservedSize(?string $queue = null): int
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNotNull('reserved_at')
            ->count();
    }

    /**
     * Get the number of jobs across every queue.
     */
    public function totalSize(): int
    {
        return $this->getDatabase()->table($this->table)->count();
    }

    /**
     * Get the number of pending jobs across every queue.
     */
    public function totalPendingSize(): int
    {
        return $this->getDatabase()->table($this->table)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $this->currentTime())
            ->count();
    }

    /**
     * Get the number of delayed jobs across every queue.
     */
    public function totalDelayedSize(): int
    {
        return $this->getDatabase()->table($this->table)
            ->whereNull('reserved_at')
            ->where('available_at', '>', $this->currentTime())
            ->count();
    }

    /**
     * Get the number of reserved jobs across every queue.
     */
    public function totalReservedSize(): int
    {
        return $this->getDatabase()->table($this->table)
            ->whereNotNull('reserved_at')
            ->count();
    }

    /**
     * Get the pending jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function pendingJobs(?string $queue = null): Collection
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $this->currentTime())
            ->get()
            ->map(fn ($record) => InspectedJob::fromPayload(
                $record->payload,
                $record->attempts,
                $record->queue,
                $record->id,
            ));
    }

    /**
     * Get the delayed jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function delayedJobs(?string $queue = null): Collection
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNull('reserved_at')
            ->where('available_at', '>', $this->currentTime())
            ->get()
            ->map(fn ($record) => InspectedJob::fromPayload(
                $record->payload,
                $record->attempts,
                $record->queue,
                $record->id,
            ));
    }

    /**
     * Get the reserved jobs for the given queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function reservedJobs(?string $queue = null): Collection
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNotNull('reserved_at')
            ->get()
            ->map(fn ($record) => InspectedJob::fromPayload(
                $record->payload,
                $record->attempts,
                $record->queue,
                $record->id,
            ));
    }

    /**
     * Get all pending jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allPendingJobs(): Collection
    {
        return $this->getDatabase()->table($this->table)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $this->currentTime())
            ->get()
            ->map(fn ($record) => InspectedJob::fromPayload(
                $record->payload,
                $record->attempts,
                $record->queue,
                $record->id,
            ));
    }

    /**
     * Get all delayed jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allDelayedJobs(): Collection
    {
        return $this->getDatabase()->table($this->table)
            ->whereNull('reserved_at')
            ->where('available_at', '>', $this->currentTime())
            ->get()
            ->map(fn ($record) => InspectedJob::fromPayload(
                $record->payload,
                $record->attempts,
                $record->queue,
                $record->id,
            ));
    }

    /**
     * Get all reserved jobs across every queue.
     *
     * @return Collection<int, InspectedJob>
     */
    public function allReservedJobs(): Collection
    {
        return $this->getDatabase()->table($this->table)
            ->whereNotNull('reserved_at')
            ->get()
            ->map(fn ($record) => InspectedJob::fromPayload(
                $record->payload,
                $record->attempts,
                $record->queue,
                $record->id,
            ));
    }

    /**
     * Get the creation timestamp of the oldest pending job, excluding delayed jobs.
     */
    public function creationTimeOfOldestPendingJob(?string $queue = null): ?int
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $this->currentTime())
            ->oldest('available_at')
            ->value('available_at');
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            static function (DatabaseQueue $owner, string $payload, ?string $queue) {
                return $owner->pushToDatabase($queue, $payload);
            }
        );
    }

    /**
     * Push a raw payload onto the queue.
     */
    public function pushRaw(string $payload, ?string $queue = null, array $options = []): mixed
    {
        return $this->pushToDatabase($queue, $payload);
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            $queue,
            $delay,
            static function (
                DatabaseQueue $owner,
                string $payload,
                ?string $queue,
                DateInterval|DateTimeInterface|int $delay
            ) {
                return $owner->pushToDatabase($queue, $payload, $delay);
            }
        );
    }

    /**
     * Push an array of jobs onto the queue.
     *
     * Immediate and after-commit jobs use one bulk insert per attempted group.
     * Deferred groups reacquire the queue through the after-commit dispatcher,
     * and the return value remains null when every job is deferred.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        $jobs = array_values($jobs);

        if ($jobs === []) {
            return null;
        }

        $transactions = null;

        if ($this->container->has('db.transactions')) {
            /** @var DatabaseTransactionsManager $transactions */
            $transactions = $this->container->make('db.transactions');
        }

        [$afterCommit, $immediate] = $this->partitionJobsByAfterCommit($jobs, $transactions);

        $result = null;

        if ($immediate !== []) {
            $result = $this->enqueueBatch($this->prepareBatchJobs($immediate, $data, $queue), $queue);
        }

        if ($afterCommit === []) {
            return $result;
        }

        $preparedJobs = $this->prepareBatchJobs($afterCommit, $data, $queue);
        /** @var DatabaseTransactionsManager $transactions */
        $this->deferBatchEnqueueAfterCommit(
            $transactions,
            $afterCommit,
            static function (Queue $owner) use ($preparedJobs, $queue): mixed {
                /** @var DatabaseQueue $owner */
                return $owner->enqueueBatch($preparedJobs, $queue);
            },
        );

        return $result;
    }

    /**
     * Prepare the payload and delay for each of the given jobs.
     *
     * @return array<int, array{job: object|string, delay: null|DateInterval|DateTimeInterface|int, payload: string}>
     */
    protected function prepareBatchJobs(array $jobs, mixed $data, ?string $queue): array
    {
        return Collection::make($jobs)
            ->map(function (object|string $job) use ($data, $queue): array {
                $delay = $this->getJobDelay($job);

                return [
                    'job' => $job,
                    'delay' => $delay,
                    'payload' => $this->createPayload($job, $this->getQueue($queue), $data, $delay),
                ];
            })
            ->all();
    }

    /**
     * Insert a prepared batch and raise its queue lifecycle events.
     */
    protected function enqueueBatch(array $jobs, ?string $queue): mixed
    {
        try {
            foreach ($jobs as $index => $job) {
                $jobs[$index]['payload'] = $this->finalizePayloadForQueueing(
                    $queue,
                    $job['job'],
                    $job['payload'],
                    $job['delay'],
                );
            }

            // Every payload must be final before any batch member begins its
            // existing JobQueueing lifecycle or the database write.
            foreach ($jobs as $job) {
                $this->raiseJobQueueingEvent($queue, $job['job'], $job['payload'], $job['delay']);
            }

            $now = $this->availableAt();
            $connection = $this->getDatabase();
            $maxBindings = $connection instanceof PdoConnection
                ? $connection->maxBindings()
                : PdoConnection::DEFAULT_MAX_BINDINGS;

            $this->insertDatabaseRows(
                $connection,
                $this->table,
                Collection::make($jobs)
                    ->map(fn (array $job): array => $this->buildDatabaseRecord(
                        $this->getQueue($queue),
                        $job['payload'],
                        $job['delay'] !== null ? $this->availableAt($job['delay']) : $now,
                    ))->all(),
                $maxBindings,
            );
            $result = true;
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            foreach ($jobs as $job) {
                $this->raiseJobQueueingFailedEvent($queue, $job['job'], $job['payload'], $job['delay'], $exception);
            }

            throw $exception;
        }

        foreach ($jobs as $job) {
            $this->acceptDispatchLocks($job['job']);
        }

        foreach ($jobs as $job) {
            $this->raiseJobQueuedEvent($queue, null, $job['job'], $job['payload'], $job['delay']);
        }

        return $result;
    }

    /**
     * Release a reserved job back onto the queue after (n) seconds.
     */
    public function release(string $queue, DatabaseJobRecord $job, int $delay): mixed
    {
        return $this->pushToDatabase($queue, $job->payload, $delay, $job->attempts);
    }

    /**
     * Push a raw payload to the database with a given delay of (n) seconds.
     */
    protected function pushToDatabase(?string $queue, string $payload, DateInterval|DateTimeInterface|int $delay = 0, int $attempts = 0): mixed
    {
        return $this->getDatabase()->table($this->table)->insertGetId($this->buildDatabaseRecord(
            $this->getQueue($queue),
            $payload,
            $this->availableAt($delay),
            $attempts
        ));
    }

    /**
     * Create an array to insert for the given job.
     */
    protected function buildDatabaseRecord(?string $queue, string $payload, int $availableAt, int $attempts = 0): array
    {
        return [
            'queue' => $queue,
            'attempts' => $attempts,
            'reserved_at' => null,
            'available_at' => $availableAt,
            'created_at' => $this->currentTime(),
            'payload' => $payload,
        ];
    }

    /**
     * Pop the next job off of the queue.
     *
     * @throws Throwable
     */
    public function pop(?string $queue = null): ?Job
    {
        $queue = $this->getQueue($queue);

        return $this->getDatabase()->transaction(function () use ($queue) {
            if ($job = $this->getNextAvailableJob($queue)) {
                return $this->marshalJob($queue, $job);
            }
        });
    }

    /**
     * Get the next available job for the queue.
     */
    protected function getNextAvailableJob(?string $queue): ?DatabaseJobRecord
    {
        $job = $this->getDatabase()->table($this->table)
            ->lock($this->getLockForPopping())
            ->where('queue', $this->getQueue($queue))
            ->where(function ($query) {
                $this->isAvailable($query);
                $this->isReservedButExpired($query);
            })
            ->orderBy('id', 'asc')
            ->first();

        return $job ? new DatabaseJobRecord((object) $job) : null;
    }

    /**
     * Get the lock required for popping the next job.
     */
    protected function getLockForPopping(): bool|string
    {
        $connection = $this->getDatabase();

        return $connection instanceof PdoConnection
            ? $connection->lockForPopping()
            : true;
    }

    /**
     * Modify the query to check for available jobs.
     */
    protected function isAvailable(Builder $query): void
    {
        $query->where(function ($query) {
            $query->whereNull('reserved_at')
                ->where('available_at', '<=', $this->currentTime());
        });
    }

    /**
     * Modify the query to check for jobs that are reserved but have expired.
     */
    protected function isReservedButExpired(Builder $query): void
    {
        $expiration = CarbonImmutable::now()->subSeconds($this->retryAfter)->getTimestamp();

        $query->orWhere(function ($query) use ($expiration) {
            $query->where('reserved_at', '<=', $expiration);
        });
    }

    /**
     * Marshal the reserved job into a DatabaseJob instance.
     */
    protected function marshalJob(string $queue, DatabaseJobRecord $job): DatabaseJob
    {
        $job = $this->markJobAsReserved($job);

        return new DatabaseJob(
            $this->container,
            $this,
            $job,
            $this->connectionName,
            $queue
        );
    }

    /**
     * Mark the given job ID as reserved.
     */
    protected function markJobAsReserved(DatabaseJobRecord $job): DatabaseJobRecord
    {
        $this->getDatabase()->table($this->table)->where('id', $job->id)->update([
            'reserved_at' => $job->touch(),
            'attempts' => $job->increment(),
        ]);

        return $job;
    }

    /**
     * Delete a reserved job from the queue.
     *
     * @throws Throwable
     */
    public function deleteReserved(string $queue, string $id): void
    {
        $this->getDatabase()->transaction(function () use ($id) {
            if ($this->getDatabase()->table($this->table)->lockForUpdate()->find($id)) {
                $this->getDatabase()->table($this->table)->where('id', $id)->delete();
            }
        });
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     */
    public function deleteAndRelease(string $queue, DatabaseJob $job, int $delay): void
    {
        $this->getDatabase()->transaction(function () use ($queue, $job, $delay) {
            if ($this->getDatabase()->table($this->table)->lockForUpdate()->find($job->getJobId())) {
                $this->getDatabase()->table($this->table)->where('id', $job->getJobId())->delete();
            }

            $this->release($queue, $job->getJobRecord(), $delay);
        });
    }

    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(?string $queue): int
    {
        return $this->getDatabase()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->delete();
    }

    /**
     * Get the queue or return the default.
     */
    public function getQueue(?string $queue): string
    {
        return $queue === null || $queue === '' ? $this->default : $queue;
    }

    /**
     * Get the underlying database connection.
     */
    public function getDatabase(): ConnectionInterface
    {
        return $this->resolver->connection($this->connection);
    }
}
