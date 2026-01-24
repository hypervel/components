<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Horizon\Contracts\MetricsRepository;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Support\Collection;

class AutoScaler
{
    /**
     * Create a new auto-scaler instance.
     *
     * @param QueueFactory $queue the queue factory implementation
     * @param MetricsRepository $metrics the metrics repository implementation
     */
    public function __construct(
        public QueueFactory $queue,
        public MetricsRepository $metrics
    ) {
    }

    /**
     * Balance the workers on the given supervisor.
     */
    public function scale(Supervisor $supervisor): void
    {
        $pools = $this->poolsByQueue($supervisor);

        $workers = $this->numberOfWorkersPerQueue(
            $supervisor,
            $this->timeToClearPerQueue($supervisor, $pools)
        );

        $workers->each(function ($workers, $queue) use ($supervisor, $pools) {
            $this->scalePool($supervisor, $pools[$queue], $workers);
        });
    }

    /**
     * Get the process pools keyed by their queue name.
     *
     * @return Collection<string, ProcessPool>
     */
    protected function poolsByQueue(Supervisor $supervisor): Collection
    {
        return $supervisor->processPools->mapWithKeys(function (ProcessPool $pool) {
            return [$pool->queue() => $pool];
        });
    }

    /**
     * Get the times in milliseconds needed to clear the queues.
     */
    protected function timeToClearPerQueue(Supervisor $supervisor, Collection $pools): Collection
    {
        return $pools->mapWithKeys(function ($pool, $queue) use ($supervisor) {
            $queues = collect(explode(',', $queue))->map(function ($_queue) use ($supervisor) { // @phpstan-ignore argument.unresolvableType
                // @phpstan-ignore-next-line RedisQueue has readyNow method
                $size = $this->queue->connection($supervisor->options->connection)->readyNow($_queue);

                return [
                    'size' => $size,
                    'time' => ($size * $this->metrics->runtimeForQueue($_queue)),
                ];
            });

            return [$queue => [
                'size' => $queues->sum('size'), // @phpstan-ignore argument.unresolvableType
                'time' => $queues->sum('time'), // @phpstan-ignore argument.unresolvableType
            ]];
        });
    }

    /**
     * Get the number of workers needed per queue for proper balance.
     *
     * @return Collection<string, float>
     */
    protected function numberOfWorkersPerQueue(Supervisor $supervisor, Collection $queues): Collection
    {
        /** @var float $timeToClearAll */
        $timeToClearAll = $queues->sum('time');
        $totalJobs = $queues->sum('size');

        return $queues->mapWithKeys(function ($timeToClear, $queue) use ($supervisor, $timeToClearAll, $totalJobs) {
            if (! $supervisor->options->balancing()) {
                $targetProcesses = min(
                    $supervisor->options->maxProcesses,
                    max($supervisor->options->minProcesses, $timeToClear['size'])
                );

                return [$queue => $targetProcesses];
            }

            if ($timeToClearAll > 0
                && $supervisor->options->autoScaling()
            ) {
                $numberOfProcesses = $supervisor->options->autoScaleByNumberOfJobs()
                    ? ($timeToClear['size'] / $totalJobs)
                    : ($timeToClear['time'] / $timeToClearAll);

                return [$queue => $numberOfProcesses *= $supervisor->options->maxProcesses];
            }

            if ($timeToClearAll == 0
                && $supervisor->options->autoScaling()
            ) {
                return [
                    $queue => $timeToClear['size']
                                ? $supervisor->options->maxProcesses
                                : $supervisor->options->minProcesses,
                ];
            }

            return [$queue => $supervisor->options->maxProcesses / count($supervisor->processPools)];
        })->sort();
    }

    /**
     * Scale the given pool to the recommended number of workers.
     */
    protected function scalePool(Supervisor $supervisor, ProcessPool $pool, float $workers): void
    {
        $supervisor->pruneTerminatingProcesses();

        $totalProcessCount = $pool->totalProcessCount();

        $desiredProcessCount = ceil($workers);

        if ($desiredProcessCount > $totalProcessCount) {
            $maxUpShift = min(
                max(0, $supervisor->options->maxProcesses - $supervisor->totalProcessCount()),
                $supervisor->options->balanceMaxShift
            );

            $pool->scale(
                min(
                    $totalProcessCount + $maxUpShift,
                    max($supervisor->options->minProcesses, $supervisor->options->maxProcesses - (($supervisor->processPools->count() - 1) * $supervisor->options->minProcesses)),
                    $desiredProcessCount
                )
            );
        } elseif ($desiredProcessCount < $totalProcessCount) {
            $maxDownShift = min(
                $supervisor->totalProcessCount() - $supervisor->options->minProcesses,
                $supervisor->options->balanceMaxShift
            );

            $pool->scale(
                max(
                    $totalProcessCount - $maxDownShift,
                    $supervisor->options->minProcesses,
                    $desiredProcessCount
                )
            );
        }
    }
}
