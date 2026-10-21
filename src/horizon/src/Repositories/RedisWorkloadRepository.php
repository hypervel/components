<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Repositories;

use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Contracts\WorkloadRepository;
use Hypervel\Horizon\WaitTimeCalculator;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class RedisWorkloadRepository implements WorkloadRepository
{
    /**
     * Create a new repository instance.
     *
     * @param QueueFactory $queue the queue factory implementation
     * @param WaitTimeCalculator $waitTime the wait time calculator instance
     * @param SupervisorRepository $supervisors the supervisor repository implementation
     */
    public function __construct(
        public QueueFactory $queue,
        public WaitTimeCalculator $waitTime,
        private SupervisorRepository $supervisors
    ) {
    }

    /**
     * Get the current workload of each queue.
     *
     * @return array<int, array{
     *    "name": string,
     *    "length": int,
     *    "wait": float,
     *    "processes": int,
     *    "split_queues": null|Collection<array-key, array{"name": string, "wait": float, "length": int}>
     *  }>
     */
    public function get(): array
    {
        $processes = $this->processes();

        return collect($this->waitTime->calculate())
            ->map(function ($waitTime, $queue) use ($processes) {
                [$connection, $queueName] = explode(':', $queue, 2);

                $totalProcesses = $processes[$queue] ?? 0;

                $length = ! Str::contains($queue, ',')
                    ? collect([$queueName => $this->queue->connection($connection)->readyNow($queueName)]) // @phpstan-ignore method.notFound
                    : collect(explode(',', $queueName))->mapWithKeys(function ($queueName) use ($connection) {
                        // @phpstan-ignore method.notFound
                        return [$queueName => $this->queue->connection($connection)->readyNow($queueName)];
                    });

                $splitQueues = Str::contains($queue, ',') ? $length->map(function ($length, $queueName) use ($connection, $totalProcesses, &$wait) {
                    // Numeric queue names become integer collection keys.
                    $queueName = (string) $queueName;

                    return [
                        'name' => $queueName,
                        'length' => $length,
                        'wait' => $wait += $this->waitTime->calculateTimeToClear($connection, $queueName, $totalProcesses),
                    ];
                }) : null;

                return [
                    'name' => $queueName,
                    'length' => $length->sum(),
                    'wait' => $waitTime,
                    'processes' => $totalProcesses,
                    'split_queues' => $splitQueues,
                ];
            })->values()->toArray();
    }

    /**
     * Get the number of processes of each queue.
     *
     * @return array<string, int>
     */
    private function processes(): array
    {
        return collect($this->supervisors->all())->pluck('processes')->reduce(function ($final, $queues) {
            foreach ($queues as $queue => $processes) {
                $final[$queue] = isset($final[$queue]) ? $final[$queue] + $processes : $processes;
            }

            return $final;
        }, []);
    }
}
