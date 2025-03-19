<?php

declare(strict_types=1);

namespace Hypervel\Queue\Failed;

use DateTimeInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Query\Builder;
use Hypervel\Support\Carbon;
use Throwable;

class DatabaseUuidFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    /**
     * Create a new database failed job provider.
     */
    public function __construct(
        protected ConnectionResolverInterface $resolver,
        protected string $table,
        protected ?string $database = null,
    ) {
    }

    /**
     * Log a failed job into storage.
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): null|int|string
    {
        $this->getTable()->insert([
            'uuid' => $uuid = json_decode($payload, true)['uuid'],
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => (string) mb_convert_encoding((string) $exception, 'UTF-8'),
            'failed_at' => Carbon::now(),
        ]);

        return $uuid;
    }

    /**
     * Get the IDs of all of the failed jobs.
     */
    public function ids(?string $queue = null): array
    {
        return $this->getTable()
            ->when(! is_null($queue), fn ($query) => $query->where('queue', $queue))
            ->orderBy('id', 'desc')
            ->pluck('uuid')
            ->all();
    }

    /**
     * Get a list of all of the failed jobs.
     */
    public function all(): array
    {
        return $this->getTable()->orderBy('id', 'desc')->get()->map(function ($record) {
            $record->id = $record->uuid;
            unset($record->uuid);

            return $record;
        })->all();
    }

    /**
     * Get a single failed job.
     */
    public function find(mixed $id): ?object
    {
        if ($record = $this->getTable()->where('uuid', $id)->first()) {
            $record->id = $record->uuid;
            unset($record->uuid);
        }

        return $record;
    }

    /**
     * Delete a single failed job from storage.
     */
    public function forget(mixed $id): bool
    {
        return $this->getTable()->where('uuid', $id)->delete() > 0;
    }

    /**
     * Flush all of the failed jobs from storage.
     */
    public function flush(?int $hours = null): void
    {
        $this->getTable()->when($hours, function ($query, $hours) {
            $query->where('failed_at', '<=', Carbon::now()->subHours($hours));
        })->delete();
    }

    /**
     * Prune all of the entries older than the given date.
     */
    public function prune(DateTimeInterface $before): int
    {
        $query = $this->getTable()->where('failed_at', '<', $before);

        $totalDeleted = 0;

        do {
            $deleted = $query->take(1000)->delete();

            $totalDeleted += $deleted;
        } while ($deleted !== 0);

        return $totalDeleted;
    }

    /**
     * Count the failed jobs.
     */
    public function count(?string $connection = null, ?string $queue = null): int
    {
        return $this->getTable()
            ->when($connection, fn ($builder) => $builder->whereConnection($connection))
            ->when($queue, fn ($builder) => $builder->whereQueue($queue))
            ->count();
    }

    /**
     * Get a new query builder instance for the table.
     */
    protected function getTable(): Builder
    {
        return $this->resolver->connection($this->database)->table($this->table);
    }
}
