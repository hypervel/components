<?php

declare(strict_types=1);

namespace Hypervel\Queue\Failed;

use Closure;
use DateTimeInterface;
use Hyperf\Collection\Collection;
use Hypervel\Support\Carbon;
use Throwable;

class FileFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    /**
     * Create a new file failed job provider.
     */
    public function __construct(
        protected string $path,
        protected int $limit = 100,
        protected ?Closure $lockProviderResolver = null
    ) {
    }

    /**
     * Log a failed job into storage.
     */
    public function log(string $connection, string $queue, string $payload, Throwable $exception): null|int|string
    {
        return $this->lock(function () use ($connection, $queue, $payload, $exception) {
            $id = json_decode($payload, true)['uuid'];

            $jobs = $this->read();

            $failedAt = Carbon::now();

            array_unshift($jobs, [
                'id' => $id,
                'connection' => $connection,
                'queue' => $queue,
                'payload' => $payload,
                'exception' => (string) mb_convert_encoding((string) $exception, 'UTF-8'),
                'failed_at' => $failedAt->format('Y-m-d H:i:s'),
                'failed_at_timestamp' => $failedAt->getTimestamp(),
            ]);

            $this->write(array_slice($jobs, 0, $this->limit));

            return $id;
        });
    }

    /**
     * Get the IDs of all of the failed jobs.
     */
    public function ids(?string $queue = null): array
    {
        return Collection::make($this->all())
            ->when(! is_null($queue), fn ($collect) => $collect->where('queue', $queue))
            ->pluck('id')
            ->all();
    }

    /**
     * Get a list of all of the failed jobs.
     */
    public function all(): array
    {
        return $this->read();
    }

    /**
     * Get a single failed job.
     */
    public function find(mixed $id): ?object
    {
        return Collection::make($this->read())
            ->first(fn ($job) => $job->id === $id);
    }

    /**
     * Delete a single failed job from storage.
     */
    public function forget(mixed $id): bool
    {
        return $this->lock(function () use ($id) {
            $this->write($pruned = Collection::make($jobs = $this->read())
                ->reject(fn ($job) => $job->id === $id)
                ->values()
                ->all());

            return count($jobs) !== count($pruned);
        });
    }

    /**
     * Flush all of the failed jobs from storage.
     */
    public function flush(?int $hours = null): void
    {
        $this->prune(Carbon::now()->subHours($hours ?: 0));
    }

    /**
     * Prune all of the entries older than the given date.
     */
    public function prune(DateTimeInterface $before): int
    {
        return $this->lock(function () use ($before) {
            $jobs = $this->read();

            $this->write($prunedJobs = Collection::make($jobs)->reject(function ($job) use ($before) {
                return $job->failed_at_timestamp <= $before->getTimestamp();
            })->values()->all());

            return count($jobs) - count($prunedJobs);
        });
    }

    /**
     * Execute the given callback while holding a lock.
     */
    protected function lock(Closure $callback): mixed
    {
        if (! $this->lockProviderResolver) {
            return $callback();
        }

        return ($this->lockProviderResolver)()
            ->lock('laravel-failed-jobs', 5)
            ->block(10, function () use ($callback) {
                return $callback();
            });
    }

    /**
     * Read the failed jobs file.
     */
    protected function read(): array
    {
        if (! file_exists($this->path)) {
            return [];
        }

        $content = file_get_contents($this->path);

        if (empty(trim($content))) {
            return [];
        }

        $content = json_decode($content);

        return is_array($content) ? $content : [];
    }

    /**
     * Write the given array of jobs to the failed jobs file.
     */
    protected function write(array $jobs): void
    {
        file_put_contents(
            $this->path,
            json_encode($jobs, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Count the failed jobs.
     */
    public function count(?string $connection = null, ?string $queue = null): int
    {
        if (($connection ?? $queue) === null) {
            return count($this->read());
        }

        return Collection::make($this->read())
            ->filter(fn ($job) => $job->connection === ($connection ?? $job->connection) && $job->queue === ($queue ?? $job->queue))
            ->count();
    }
}
