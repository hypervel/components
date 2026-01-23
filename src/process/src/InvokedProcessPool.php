<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Countable;
use Hypervel\Support\Collection;
use Hypervel\Process\Contracts\InvokedProcess;

class InvokedProcessPool implements Countable
{
    /**
     * Create a new invoked process pool.
     *
     * @param array<int, InvokedProcess> $invokedProcesses the array of invoked processes
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
