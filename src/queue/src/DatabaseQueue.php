<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hyperf\Collection\Collection;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Query\Builder;
use Hyperf\Stringable\Str;
use Hypervel\Queue\Contracts\ClearableQueue;
use Hypervel\Queue\Contracts\Job;
use Hypervel\Queue\Contracts\Queue as QueueContract;
use Hypervel\Queue\Jobs\DatabaseJob;
use Hypervel\Queue\Jobs\DatabaseJobRecord;
use Hypervel\Support\Carbon;
use PDO;
use Throwable;

class DatabaseQueue extends Queue implements QueueContract, ClearableQueue
{
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
        protected ?int $retryAfter = 60,
        protected bool $dispatchAfterCommit = false
    ) {
    }

    /**
     * Get the size of the queue.
     */
    public function size(?string $queue = null): int
    {
        return $this->connection()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->count();
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
            function ($payload, $queue) {
                return $this->pushToDatabase($queue, $payload);
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
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            $delay,
            function ($payload, $queue, $delay) {
                return $this->pushToDatabase($queue, $payload, $delay);
            }
        );
    }

    /**
     * Push an array of jobs onto the queue.
     */
    public function bulk(array $jobs, mixed $data = '', ?string $queue = null): mixed
    {
        $queue = $this->getQueue($queue);

        $now = $this->availableAt();

        return $this->connection()->table($this->table)->insert(Collection::make((array) $jobs)->map(
            function ($job) use ($queue, $data, $now) {
                return $this->buildDatabaseRecord(
                    $queue,
                    $this->createPayload($job, $this->getQueue($queue), $data),
                    isset($job->delay) ? $this->availableAt($job->delay) : $now,
                );
            }
        )->all());
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
        return $this->connection()->table($this->table)->insertGetId($this->buildDatabaseRecord(
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

        return $this->connection()->transaction(function () use ($queue) {
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
        $job = $this->connection()->table($this->table)
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
        /* @phpstan-ignore-next-line */
        $databaseEngine = $this->connection()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        /* @phpstan-ignore-next-line */
        $databaseVersion = $this->connection()->getConfig('version') ?? $this->connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        if (Str::of($databaseVersion)->contains('MariaDB')) {
            $databaseEngine = 'mariadb';
            $databaseVersion = Str::before(Str::after($databaseVersion, '5.5.5-'), '-');
        } elseif (Str::of($databaseVersion)->contains(['vitess', 'PlanetScale'])) {
            $databaseEngine = 'vitess';
            $databaseVersion = Str::before($databaseVersion, '-');
        }

        if (($databaseEngine === 'mysql' && version_compare($databaseVersion, '8.0.1', '>='))
            || ($databaseEngine === 'mariadb' && version_compare($databaseVersion, '10.6.0', '>='))
            || ($databaseEngine === 'pgsql' && version_compare($databaseVersion, '9.5', '>='))
            || ($databaseEngine === 'vitess' && version_compare($databaseVersion, '19.0', '>='))
        ) {
            return 'FOR UPDATE SKIP LOCKED';
        }

        if ($databaseEngine === 'sqlsrv') {
            return 'with(rowlock,updlock,readpast)';
        }

        return true;
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
        $expiration = Carbon::now()->subSeconds($this->retryAfter)->getTimestamp();

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
        $this->connection()->table($this->table)->where('id', $job->id)->update([
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
        $this->connection()->transaction(function () use ($id) {
            if ($this->connection()->table($this->table)->lockForUpdate()->find($id)) {
                $this->connection()->table($this->table)->where('id', $id)->delete();
            }
        });
    }

    /**
     * Delete a reserved job from the reserved queue and release it.
     */
    public function deleteAndRelease(string $queue, DatabaseJob $job, int $delay): void
    {
        $this->connection()->transaction(function () use ($queue, $job, $delay) {
            if ($this->connection()->table($this->table)->lockForUpdate()->find($job->getJobId())) {
                $this->connection()->table($this->table)->where('id', $job->getJobId())->delete();
            }

            $this->release($queue, $job->getJobRecord(), $delay);
        });
    }

    /**
     * Delete all of the jobs from the queue.
     */
    public function clear(string $queue): int
    {
        return $this->connection()->table($this->table)
            ->where('queue', $this->getQueue($queue))
            ->delete();
    }

    /**
     * Get the queue or return the default.
     */
    public function getQueue(?string $queue): string
    {
        return $queue ?: $this->default;
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
    public function setConnection(?string $connection): static
    {
        $this->connection = $connection;

        return $this;
    }
}
