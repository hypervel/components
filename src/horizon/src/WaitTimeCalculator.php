<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class WaitTimeCalculator
{
    /**
     * Create a new calculator instance.
     */
    public function __construct(
        public QueueFactory $queue,
        public SupervisorRepository $supervisors,
        public MetricsRepository $metrics
    ) {
    }

    /**
     * Calculate the time to clear a given queue in seconds.
     */
    public function calculateFor(string $queue): float
    {
        return array_values($this->calculate($queue))[0] ?? 0;
    }

    /**
     * Calculate the time to clear per queue in seconds.
     *
     * @return array<string, float>
     */
    public function calculate(?string $queue = null): array
    {
        $queues = $this->queueNames(
            $supervisors = collect($this->supervisors->all()),
            $queue
        );

        return $queues->mapWithKeys(function ($queue) use ($supervisors) {
            $totalProcesses = $this->totalProcessesFor($supervisors, $queue);

            [$connection, $queueName] = explode(':', $queue, 2);

            return [$queue => $this->calculateTimeToClear($connection, $queueName, $totalProcesses)];
        })->sort()->reverse()->all();
    }

    /**
     * Get all of the queue names.
     */
    protected function queueNames(Collection $supervisors, ?string $queue = null): Collection
    {
        $queues = $supervisors->map(function ($supervisor) {
            return array_keys($supervisor->processes);
        })->collapse()->unique()->values();

        return $queue ? $queues->intersect([$queue]) : $queues; // @phpstan-ignore argument.type
    }

    /**
     * Get the total process count for a given queue.
     */
    protected function totalProcessesFor(Collection $allSupervisors, string $queue): int
    {
        return $allSupervisors->sum(function ($supervisor) use ($queue) {
            return $supervisor->processes[$queue] ?? 0;
        });
    }

    /**
     * Calculate the time to clear for the given queue in seconds distributed over the given amount of processes.
     */
    public function calculateTimeToClear(string $connection, string $queue, int $totalProcesses): float
    {
        $timeToClear = ! Str::contains($queue, ',')
            ? $this->timeToClearFor($connection, $queue)
            : collect(explode(',', $queue))->sum(function ($queueName) use ($connection) {
                return $this->timeToClearFor($connection, $queueName);
            });

        return $totalProcesses === 0
            ? round($timeToClear / 1000)
            : round(($timeToClear / $totalProcesses) / 1000);
    }

    /**
     * Get the total time to clear (in milliseconds) for a given queue.
     */
    protected function timeToClearFor(string $connection, string $queue): float
    {
        // @phpstan-ignore-next-line RedisQueue has readyNow method
        $size = $this->queue->connection($connection)->readyNow($queue);

        return $size * $this->metrics->runtimeForQueue($queue);
    }
}
