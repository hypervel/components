<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Carbon\CarbonImmutable;
use Exception;
use Hypervel\Horizon\Contracts\JobRepository;
use Hypervel\Horizon\JobPayload;
use Hypervel\Horizon\LuaScripts;
use Hypervel\Redis\RedisFactory;
use Hypervel\Redis\RedisProxy;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use stdClass;

class RedisJobRepository implements JobRepository
{
    /**
     * The keys stored on the job hashes.
     */
    public array $keys = [
        'id', 'connection', 'queue', 'name', 'status', 'payload',
        'exception', 'context', 'failed_at', 'completed_at', 'retried_by',
        'reserved_at',
    ];

    /**
     * The number of minutes until recently failed jobs should be purged.
     */
    public int $recentFailedJobExpires;

    /**
     * The number of minutes until recent jobs should be purged.
     */
    public int $recentJobExpires;

    /**
     * The number of minutes until pending jobs should be purged.
     */
    public int $pendingJobExpires;

    /**
     * The number of minutes until completed and silenced jobs should be purged.
     */
    public int $completedJobExpires;

    /**
     * The number of minutes until failed jobs should be purged.
     */
    public int $failedJobExpires;

    /**
     * The number of minutes until monitored jobs should be purged.
     */
    public int $monitoredJobExpires;

    /**
     * Create a new repository instance.
     */
    public function __construct(
        public RedisFactory $redis
    ) {
        $this->recentJobExpires = config('horizon.trim.recent', 60);
        $this->pendingJobExpires = config('horizon.trim.pending', 60);
        $this->completedJobExpires = config('horizon.trim.completed', 60);
        $this->failedJobExpires = config('horizon.trim.failed', 10080);
        $this->recentFailedJobExpires = config('horizon.trim.recent_failed', $this->failedJobExpires);
        $this->monitoredJobExpires = config('horizon.trim.monitored', 10080);
    }

    /**
     * Get the next job ID that should be assigned.
     */
    public function nextJobId(): string
    {
        return (string) $this->connection()->incr('job_id');
    }

    /**
     * Get the total count of recent jobs.
     */
    public function totalRecent(): int
    {
        return $this->connection()->zCard('recent_jobs');
    }

    /**
     * Get the total count of failed jobs.
     */
    public function totalFailed(): int
    {
        return $this->connection()->zCard('failed_jobs');
    }

    /**
     * Get a chunk of recent jobs.
     */
    public function getRecent(?string $afterIndex = null): Collection
    {
        return $this->getJobsByType('recent_jobs', $afterIndex);
    }

    /**
     * Get a chunk of failed jobs.
     */
    public function getFailed(?string $afterIndex = null): Collection
    {
        return $this->getJobsByType('failed_jobs', $afterIndex);
    }

    /**
     * Get a chunk of pending jobs.
     */
    public function getPending(?string $afterIndex = null): Collection
    {
        return $this->getJobsByType('pending_jobs', $afterIndex);
    }

    /**
     * Get a chunk of completed jobs.
     */
    public function getCompleted(?string $afterIndex = null): Collection
    {
        return $this->getJobsByType('completed_jobs', $afterIndex);
    }

    /**
     * Get a chunk of silenced jobs.
     */
    public function getSilenced(?string $afterIndex = null): Collection
    {
        return $this->getJobsByType('silenced_jobs', $afterIndex);
    }

    /**
     * Get the count of recent jobs.
     */
    public function countRecent(): int
    {
        return $this->countJobsByType('recent_jobs');
    }

    /**
     * Get the count of failed jobs.
     */
    public function countFailed(): int
    {
        return $this->countJobsByType('failed_jobs');
    }

    /**
     * Get the count of pending jobs.
     */
    public function countPending(): int
    {
        return $this->countJobsByType('pending_jobs');
    }

    /**
     * Get the count of completed jobs.
     */
    public function countCompleted(): int
    {
        return $this->countJobsByType('completed_jobs');
    }

    /**
     * Get the count of silenced jobs.
     */
    public function countSilenced(): int
    {
        return $this->countJobsByType('silenced_jobs');
    }

    /**
     * Get the count of the recently failed jobs.
     */
    public function countRecentlyFailed(): int
    {
        return $this->countJobsByType('recent_failed_jobs');
    }

    /**
     * Get a chunk of jobs from the given type set.
     */
    protected function getJobsByType(string $type, ?string $afterIndex): Collection
    {
        $afterIndex = $afterIndex === null ? -1 : $afterIndex;

        return $this->getJobs($this->connection()->zRange(
            $type,
            $afterIndex + 1,
            $afterIndex + 50
        ), $afterIndex + 1);
    }

    /**
     * Get the number of jobs in a given type set.
     */
    protected function countJobsByType(string $type): int
    {
        $minutes = $this->minutesForType($type);

        return $this->connection()->zcount(
            $type,
            '-inf',
            CarbonImmutable::now()->subMinutes($minutes)->getTimestamp() * -1
        );
    }

    /**
     * Get the number of minutes to count for a given type set.
     */
    protected function minutesForType(string $type): int
    {
        return match ($type) {
            'failed_jobs' => $this->failedJobExpires,
            'recent_failed_jobs' => $this->recentFailedJobExpires,
            'pending_jobs' => $this->pendingJobExpires,
            'completed_jobs' => $this->completedJobExpires,
            'silenced_jobs' => $this->completedJobExpires,
            default => $this->recentJobExpires,
        };
    }

    /**
     * Retrieve the jobs with the given IDs.
     */
    public function getJobs(array $ids, mixed $indexFrom = 0): Collection
    {
        $jobs = $this->connection()->pipeline(function ($pipe) use ($ids) {
            foreach ($ids as $id) {
                $pipe->hmget($id, $this->keys);
            }
        });

        return $this->indexJobs(collect($jobs)->filter(function ($job) {
            $job = is_array($job) ? array_values($job) : null;

            return is_array($job) && $job[0] !== null && $job[0] !== false;
        })->values(), $indexFrom);
    }

    /**
     * Index the given jobs from the given index.
     */
    protected function indexJobs(Collection $jobs, int $indexFrom): Collection
    {
        return $jobs->map(function ($job) use (&$indexFrom) {
            $job = (object) array_combine($this->keys, $job);

            $job->index = $indexFrom;

            ++$indexFrom;

            return $job;
        });
    }

    /**
     * Insert the job into storage.
     */
    public function pushed(string $connection, string $queue, JobPayload $payload): void
    {
        $this->connection()->pipeline(function ($pipe) use ($connection, $queue, $payload) {
            $this->storeJobReference($pipe, 'recent_jobs', $payload);
            $this->storeJobReference($pipe, 'pending_jobs', $payload);

            $time = str_replace(',', '.', microtime(true));

            $pipe->hmset($payload->id(), [
                'id' => $payload->id(),
                'connection' => $connection,
                'queue' => $queue,
                'name' => $payload->decoded['displayName'],
                'status' => 'pending',
                'payload' => $payload->value,
                'created_at' => $time,
                'updated_at' => $time,
            ]);

            $pipe->expireat(
                $payload->id(),
                CarbonImmutable::now()->addMinutes($this->pendingJobExpires)->getTimestamp()
            );
        });
    }

    /**
     * Mark the job as reserved.
     */
    public function reserved(string $connection, string $queue, JobPayload $payload): void
    {
        $time = str_replace(',', '.', microtime(true));

        $this->connection()->hmset(
            $payload->id(),
            [
                'status' => 'reserved',
                'payload' => $payload->value,
                'updated_at' => $time,
                'reserved_at' => $time,
            ]
        );
    }

    /**
     * Mark the job as released / pending.
     */
    public function released(string $connection, string $queue, JobPayload $payload): void
    {
        $this->connection()->hmset(
            $payload->id(),
            [
                'status' => 'pending',
                'payload' => $payload->value,
                'updated_at' => str_replace(',', '.', microtime(true)),
            ]
        );
    }

    /**
     * Mark the job as completed and monitored.
     */
    public function remember(string $connection, string $queue, JobPayload $payload): void
    {
        $this->connection()->pipeline(function ($pipe) use ($connection, $queue, $payload) {
            $this->storeJobReference($pipe, 'monitored_jobs', $payload);

            $pipe->hmset(
                $payload->id(),
                [
                    'id' => $payload->id(),
                    'connection' => $connection,
                    'queue' => $queue,
                    'name' => $payload->decoded['displayName'],
                    'status' => 'completed',
                    'payload' => $payload->value,
                    'completed_at' => str_replace(',', '.', microtime(true)),
                ]
            );

            $pipe->expireat(
                $payload->id(),
                CarbonImmutable::now()->addMinutes($this->monitoredJobExpires)->getTimestamp()
            );
        });
    }

    /**
     * Mark the given jobs as released / pending.
     */
    public function migrated(string $connection, string $queue, Collection $payloads): void
    {
        $this->connection()->pipeline(function ($pipe) use ($payloads) {
            foreach ($payloads as $payload) {
                $pipe->hmset(
                    $payload->id(),
                    [
                        'status' => 'pending',
                        'payload' => $payload->value,
                        'updated_at' => str_replace(',', '.', microtime(true)),
                    ]
                );
            }
        });
    }

    /**
     * Handle the storage of a completed job.
     */
    public function completed(JobPayload $payload, bool $failed = false, bool $silenced = false): void
    {
        if ($payload->isRetry()) {
            $this->updateRetryInformationOnParent($payload, $failed);
        }

        $this->connection()->pipeline(function ($pipe) use ($payload, $silenced) {
            $this->storeJobReference($pipe, $silenced ? 'silenced_jobs' : 'completed_jobs', $payload);
            $this->removeJobReference($pipe, 'pending_jobs', $payload);

            $pipe->hmset(
                $payload->id(),
                [
                    'status' => 'completed',
                    'completed_at' => str_replace(',', '.', microtime(true)),
                ]
            );

            $pipe->expireat($payload->id(), CarbonImmutable::now()->addMinutes($this->completedJobExpires)->getTimestamp());
        });
    }

    /**
     * Update the retry status of a job's parent.
     */
    protected function updateRetryInformationOnParent(JobPayload $payload, bool $failed): void
    {
        if ($retries = $this->connection()->hget($payload->retryOf(), 'retried_by')) {
            $retries = $this->updateRetryStatus(
                $payload,
                json_decode($retries, true),
                $failed
            );

            $this->connection()->hset(
                $payload->retryOf(),
                'retried_by',
                json_encode($retries)
            );
        }
    }

    /**
     * Update the retry status of a job in a retry array.
     */
    protected function updateRetryStatus(JobPayload $payload, array $retries, bool $failed): array
    {
        return collect($retries)->map(function ($retry) use ($payload, $failed) {
            return $retry['id'] === $payload->id()
                    ? Arr::set($retry, 'status', $failed ? 'failed' : 'completed')
                    : $retry;
        })->all();
    }

    /**
     * Delete the given monitored jobs by IDs.
     */
    public function deleteMonitored(array $ids): void
    {
        $this->connection()->pipeline(function ($pipe) use ($ids) {
            foreach ($ids as $id) {
                $pipe->expireat($id, CarbonImmutable::now()->addDays(7)->getTimestamp());
            }
        });
    }

    /**
     * Trim the recent job list.
     */
    public function trimRecentJobs(): void
    {
        $this->connection()->pipeline(function ($pipe) {
            $pipe->zRemRangeByScore(
                'recent_jobs',
                CarbonImmutable::now()->subMinutes($this->recentJobExpires)->getTimestamp() * -1,
                '+inf'
            );

            $pipe->zRemRangeByScore(
                'recent_failed_jobs',
                CarbonImmutable::now()->subMinutes($this->recentFailedJobExpires)->getTimestamp() * -1,
                '+inf'
            );

            $pipe->zRemRangeByScore(
                'pending_jobs',
                CarbonImmutable::now()->subMinutes($this->pendingJobExpires)->getTimestamp() * -1,
                '+inf'
            );

            $pipe->zRemRangeByScore(
                'completed_jobs',
                CarbonImmutable::now()->subMinutes($this->completedJobExpires)->getTimestamp() * -1,
                '+inf'
            );

            $pipe->zRemRangeByScore(
                'silenced_jobs',
                CarbonImmutable::now()->subMinutes($this->completedJobExpires)->getTimestamp() * -1,
                '+inf'
            );
        });
    }

    /**
     * Trim the failed job list.
     */
    public function trimFailedJobs(): void
    {
        $this->connection()->zRemRangeByScore(
            'failed_jobs',
            CarbonImmutable::now()->subMinutes($this->failedJobExpires)->getTimestamp() * -1,
            '+inf'
        );
    }

    /**
     * Trim the monitored job list.
     */
    public function trimMonitoredJobs(): void
    {
        $this->connection()->zRemRangeByScore(
            'monitored_jobs',
            CarbonImmutable::now()->subMinutes($this->monitoredJobExpires)->getTimestamp() * -1,
            '+inf'
        );
    }

    /**
     * Find a failed job by ID.
     */
    public function findFailed(string $id): ?stdClass
    {
        $attributes = $this->connection()->hmget(
            $id,
            $this->keys
        );

        $job = is_array($attributes) && $attributes[$this->keys[0]] ? (object) $attributes : null;

        if ($job && $job->status !== 'failed') {
            return null;
        }

        return $job;
    }

    /**
     * Mark the job as failed.
     */
    public function failed(Exception $exception, string $connection, string $queue, JobPayload $payload): void
    {
        $this->connection()->pipeline(function ($pipe) use ($exception, $connection, $queue, $payload) {
            $this->storeJobReference($pipe, 'failed_jobs', $payload);
            $this->storeJobReference($pipe, 'recent_failed_jobs', $payload);
            $this->removeJobReference($pipe, 'pending_jobs', $payload);
            $this->removeJobReference($pipe, 'completed_jobs', $payload);
            $this->removeJobReference($pipe, 'silenced_jobs', $payload);

            $pipe->hmset(
                $payload->id(),
                [
                    'id' => $payload->id(),
                    'connection' => $connection,
                    'queue' => $queue,
                    'name' => $payload->decoded['displayName'],
                    'status' => 'failed',
                    'payload' => $payload->value,
                    'exception' => (string) $exception,
                    'context' => method_exists($exception, 'context')
                        ? json_encode($exception->context())
                        : null,
                    'failed_at' => str_replace(',', '.', microtime(true)),
                ]
            );

            $pipe->expireat(
                $payload->id(),
                CarbonImmutable::now()->addMinutes($this->failedJobExpires)->getTimestamp()
            );
        });
    }

    /**
     * Store the look-up references for a job.
     */
    protected function storeJobReference(mixed $pipe, string $key, JobPayload $payload): void
    {
        $pipe->zadd($key, str_replace(',', '.', microtime(true) * -1), $payload->id());
    }

    /**
     * Remove the look-up references for a job.
     */
    protected function removeJobReference(mixed $pipe, string $key, JobPayload $payload): void
    {
        $pipe->zrem($key, $payload->id());
    }

    /**
     * Store the retry job ID on the original job record.
     */
    public function storeRetryReference(string $id, string $retryId): void
    {
        $retries = json_decode($this->connection()->hget($id, 'retried_by') ?: '[]');

        $retries[] = [
            'id' => $retryId,
            'status' => 'pending',
            'retried_at' => CarbonImmutable::now()->getTimestamp(),
        ];

        $this->connection()->hmset($id, ['retried_by' => json_encode($retries)]);
    }

    /**
     * Delete a failed job by ID.
     */
    public function deleteFailed(string $id): int
    {
        /* @phpstan-ignore-next-line */
        return $this->connection()->zrem('failed_jobs', $id) != 1
            ? 0
            : $this->connection()->del($id);
    }

    /**
     * Delete pending and reserved jobs for a queue.
     */
    public function purge(string $queue): int
    {
        return $this->connection()->eval(
            LuaScripts::purge(),
            2,
            'recent_jobs',
            'pending_jobs',
            config('horizon.prefix'),
            $queue
        );
    }

    /**
     * Get the Redis connection instance.
     */
    protected function connection(): RedisProxy
    {
        return $this->redis->get('horizon');
    }
}
