<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Carbon\CarbonImmutable;
use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Lock;
use Hypervel\Horizon\LuaScripts;
use Hypervel\Horizon\WaitTimeCalculator;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use Hypervel\Support\Str;

class RedisMetricsRepository implements MetricsRepository
{
    /**
     * Create a new repository instance.
     */
    public function __construct(
        public RedisFactory $redis
    ) {
    }

    /**
     * Get all of the class names that have metrics measurements.
     */
    public function measuredJobs(): array
    {
        $classes = (array) $this->connection()->sMembers('measured_jobs');

        return collect($classes)->map(function ($class) {
            return preg_match('/job:(.*)$/', $class, $matches) ? $matches[1] : $class;
        })->sort()->values()->all();
    }

    /**
     * Get all of the queues that have metrics measurements.
     */
    public function measuredQueues(): array
    {
        $queues = (array) $this->connection()->sMembers('measured_queues');

        return collect($queues)->map(function ($class) {
            return preg_match('/queue:(.*)$/', $class, $matches) ? $matches[1] : $class;
        })->sort()->values()->all();
    }

    /**
     * Get the jobs processed per minute since the last snapshot.
     */
    public function jobsProcessedPerMinute(): float
    {
        return round($this->throughput() / $this->minutesSinceLastSnapshot());
    }

    /**
     * Get the application's total throughput since the last snapshot.
     */
    public function throughput(): int
    {
        return collect($this->measuredQueues())->reduce(function ($carry, $queue) {
            return $carry + $this->connection()->hGet('queue:' . $queue, 'throughput');
        }, 0);
    }

    /**
     * Get the throughput for a given job.
     */
    public function throughputForJob(string $job): int
    {
        return $this->throughputFor('job:' . $job);
    }

    /**
     * Get the throughput for a given queue.
     */
    public function throughputForQueue(string $queue): int
    {
        return $this->throughputFor('queue:' . $queue);
    }

    /**
     * Get the throughput for a given key.
     */
    protected function throughputFor(string $key): int
    {
        return (int) $this->connection()->hGet($key, 'throughput');
    }

    /**
     * Get the average runtime for a given job in milliseconds.
     */
    public function runtimeForJob(string $job): float
    {
        return $this->runtimeFor('job:' . $job);
    }

    /**
     * Get the average runtime for a given queue in milliseconds.
     */
    public function runtimeForQueue(string $queue): float
    {
        return $this->runtimeFor('queue:' . $queue);
    }

    /**
     * Get the average runtime for a given key in milliseconds.
     */
    protected function runtimeFor(string $key): float
    {
        return (float) $this->connection()->hGet($key, 'runtime');
    }

    /**
     * Get the queue that has the longest runtime.
     */
    public function queueWithMaximumRuntime(): ?string
    {
        return collect($this->measuredQueues())->sortBy(function ($queue) {
            if ($snapshots = $this->connection()->zRange('snapshot:queue:' . $queue, -1, 1)) {
                return json_decode($snapshots[0])->runtime;
            }
        })->last();
    }

    /**
     * Get the queue that has the most throughput.
     */
    public function queueWithMaximumThroughput(): ?string
    {
        return collect($this->measuredQueues())->sortBy(function ($queue) {
            if ($snapshots = $this->connection()->zRange('snapshot:queue:' . $queue, -1, 1)) {
                return json_decode($snapshots[0])->throughput;
            }
        })->last();
    }

    /**
     * Increment the metrics information for a job.
     */
    public function incrementJob(string $job, ?float $runtime): void
    {
        $this->connection()->eval(
            LuaScripts::updateMetrics(),
            [
                'job:' . $job,
                'measured_jobs',
                str_replace(',', '.', (string) $runtime),
            ],
            2,
        );
    }

    /**
     * Increment the metrics information for a queue.
     */
    public function incrementQueue(string $queue, ?float $runtime): void
    {
        $this->connection()->eval(
            LuaScripts::updateMetrics(),
            [
                'queue:' . $queue,
                'measured_queues',
                str_replace(',', '.', (string) $runtime),
            ],
            2,
        );
    }

    /**
     * Get all of the snapshots for the given job.
     */
    public function snapshotsForJob(string $job): array
    {
        return $this->snapshotsFor('job:' . $job);
    }

    /**
     * Get all of the snapshots for the given queue.
     */
    public function snapshotsForQueue(string $queue): array
    {
        return $this->snapshotsFor('queue:' . $queue);
    }

    /**
     * Get all of the snapshots for the given key.
     */
    protected function snapshotsFor(string $key): array
    {
        return collect($this->connection()->zRange('snapshot:' . $key, 0, -1))
            ->map(function ($snapshot) {
                return (object) json_decode($snapshot, true);
            })->values()->all();
    }

    /**
     * Store a snapshot of the metrics information.
     */
    public function snapshot(): void
    {
        collect($this->measuredJobs())->each(function ($job) {
            $this->storeSnapshotForJob($job);
        });

        collect($this->measuredQueues())->each(function ($queue) {
            $this->storeSnapshotForQueue($queue);
        });

        $this->storeSnapshotTimestamp();
    }

    /**
     * Store a snapshot for the given job.
     */
    protected function storeSnapshotForJob(string $job): void
    {
        $data = $this->baseSnapshotData($key = 'job:' . $job);

        $this->connection()->zadd(
            'snapshot:' . $key,
            $time = CarbonImmutable::now()->getTimestamp(),
            json_encode([
                'throughput' => $data['throughput'],
                'runtime' => $data['runtime'],
                'time' => $time,
            ])
        );

        $this->connection()->zRemRangeByRank(
            'snapshot:' . $key,
            0,
            -abs(1 + config('horizon.metrics.trim_snapshots.job', 24))
        );
    }

    /**
     * Store a snapshot for the given queue.
     */
    protected function storeSnapshotForQueue(string $queue): void
    {
        $data = $this->baseSnapshotData($key = 'queue:' . $queue);

        $this->connection()->zadd(
            'snapshot:' . $key,
            $time = CarbonImmutable::now()->getTimestamp(),
            json_encode([
                'throughput' => $data['throughput'],
                'runtime' => $data['runtime'],
                'wait' => app(WaitTimeCalculator::class)->calculateFor($queue),
                'time' => $time,
            ])
        );

        $this->connection()->zRemRangeByRank(
            'snapshot:' . $key,
            0,
            -abs(1 + config('horizon.metrics.trim_snapshots.queue', 24))
        );
    }

    /**
     * Get the base snapshot data for a given key.
     *
     * @return array{throughput: string, runtime: string}
     */
    protected function baseSnapshotData(string $key): array
    {
        /** @var array{0: array{throughput: string, runtime: string}} $responses */
        $responses = $this->connection()->transaction(function ($trans) use ($key) {
            $trans->hmget($key, ['throughput', 'runtime']);

            $trans->del($key);
        });

        return $responses[0];
    }

    /**
     * Get the number of minutes passed since the last snapshot.
     */
    protected function minutesSinceLastSnapshot(): float
    {
        $lastSnapshotAt = (int) ($this->connection()->get('last_snapshot_at')
                                    ?: $this->storeSnapshotTimestamp());

        return max(
            (CarbonImmutable::now()->getTimestamp() - $lastSnapshotAt) / 60,
            1
        );
    }

    /**
     * Store the current timestamp as the "last snapshot timestamp".
     */
    protected function storeSnapshotTimestamp(): int
    {
        return tap(CarbonImmutable::now()->getTimestamp(), function ($timestamp) {
            $this->connection()->set('last_snapshot_at', $timestamp);
        });
    }

    /**
     * Attempt to acquire a lock to monitor the queue wait times.
     */
    public function acquireWaitTimeMonitorLock(): bool
    {
        return app(Lock::class)->get('monitor:time-to-clear');
    }

    /**
     * Clear the metrics for a key.
     */
    public function forget(string $key): void
    {
        $this->connection()->del($key);
    }

    /**
     * Delete all stored metrics information.
     */
    public function clear(): void
    {
        $this->forget('last_snapshot_at');
        $this->forget('measured_jobs');
        $this->forget('measured_queues');
        $this->forget('metrics:snapshot');

        foreach (['queue:*', 'job:*', 'snapshot:*'] as $pattern) {
            $cursor = null;

            do {
                $keys = $this->connection()->scan(
                    $cursor,
                    config('horizon.prefix') . $pattern
                );

                foreach ($keys ?? [] as $key) {
                    $this->forget(Str::after($key, config('horizon.prefix')));
                }
            } while ($cursor > 0);
        }
    }

    /**
     * Get the Redis connection instance.
     */
    protected function connection(): RedisProxy
    {
        return $this->redis->get('horizon');
    }
}
