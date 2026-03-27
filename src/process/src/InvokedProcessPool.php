<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Countable;
use Hypervel\Contracts\Process\InvokedProcess;
use Hypervel\Support\Collection;

class InvokedProcessPool implements Countable
{
    /**
     * Create a new invoked process pool.
     *
     * @param array<int|string, InvokedProcess> $invokedProcesses the array of invoked processes
     */
    public function __construct(protected array $invokedProcesses)
    {
    }

    /**
     * Send a signal to each running process in the pool, returning the processes that were signalled.
     */
    public function signal(int $signal): Collection
    {
        return $this->running()->each->signal($signal);
    }

    /**
     * Stop all processes that are still running.
     */
    public function stop(float $timeout = 10, ?int $signal = null): Collection
    {
        return $this->running()->each->stop($timeout, $signal);
    }

    /**
     * Get the processes in the pool that are still currently running.
     */
    public function running(): Collection
    {
        /* @phpstan-ignore-next-line */
        return (new Collection($this->invokedProcesses))->filter->running()->values();
    }

    /**
     * Wait for the processes to finish.
     */
    public function wait(): ProcessPoolResults
    {
        return new ProcessPoolResults(
            /* @phpstan-ignore-next-line */
            (new Collection($this->invokedProcesses))->map->wait()->all()
        );
    }

    /**
     * Get the total number of processes.
     */
    public function count(): int
    {
        return count($this->invokedProcesses);
    }
}
