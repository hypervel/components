<?php

declare(strict_types=1);

namespace Hypervel\Queue\Failed;

use Closure;
use DateTimeInterface;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use RuntimeException;
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
    public function log(string $connection, string $queue, string $payload, Throwable $exception): int|string|null
    {
        return $this->lock(function () use ($connection, $queue, $payload, $exception) {
            $decoded = json_decode($payload, true);
            $uuid = is_array($decoded) ? ($decoded['uuid'] ?? null) : null;
            $id = is_string($uuid) && $uuid !== '' ? $uuid : (string) Str::uuid();

            $jobs = $this->read();

            $failedAt = Date::now();

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
        $this->prune(Date::now()->subHours($hours ?: 0));
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
            // IMPORTANT: Uses Laravel's key for cross-framework queue interoperability.
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

        $content = @file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("Unable to read the failed jobs file [{$this->path}].");
        }

        if (trim($content) === '') {
            return [];
        }

        $jobs = json_decode($content, flags: JSON_THROW_ON_ERROR);

        if (! is_array($jobs)) {
            throw new RuntimeException("The failed jobs file [{$this->path}] does not contain a JSON array.");
        }

        return $jobs;
    }

    /**
     * Write the given array of jobs to the failed jobs file.
     */
    protected function write(array $jobs): void
    {
        $contents = json_encode($jobs, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $directory = realpath(dirname($this->path));

        if ($directory === false) {
            throw new RuntimeException("The failed jobs directory for [{$this->path}] does not exist.");
        }

        $permissions = is_file($this->path) ? @fileperms($this->path) : false;
        $mode = $permissions === false ? 0666 & ~umask() : $permissions & 0777;
        $temporaryPath = @tempnam($directory, basename($this->path) . '.');

        if ($temporaryPath === false || dirname($temporaryPath) !== $directory) {
            if (is_string($temporaryPath)) {
                @unlink($temporaryPath);
            }

            throw new RuntimeException("Unable to create a temporary failed jobs file in [{$directory}].");
        }

        $published = false;

        try {
            $written = @file_put_contents($temporaryPath, $contents);

            if ($written !== strlen($contents)) {
                throw new RuntimeException("Unable to write the complete failed jobs file [{$temporaryPath}].");
            }

            // Permission metadata may be unavailable even when atomic publication is supported.
            @chmod($temporaryPath, $mode);

            if (! @rename($temporaryPath, $this->path)) {
                throw new RuntimeException("Unable to publish the failed jobs file [{$this->path}].");
            }

            $published = true;
        } finally {
            if (! $published && file_exists($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
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
