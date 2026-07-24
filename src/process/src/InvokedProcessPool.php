<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Countable;
use Hypervel\Contracts\Process\InvokedProcess;
use Hypervel\Support\Collection;
use Throwable;

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
        $running = [];
        $exception = null;

        foreach ($this->invokedProcesses as $process) {
            // An inspection failure leaves ownership uncertain, so still attempt terminal cleanup.
            $shouldStop = true;

            try {
                $shouldStop = $process->running();

                if ($shouldStop) {
                    $running[] = $process;
                }
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }

            if (! $shouldStop) {
                continue;
            }

            try {
                $process->stop($timeout, $signal);
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return new Collection($running);
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
        $results = [];

        try {
            foreach ($this->invokedProcesses as $key => $process) {
                $results[$key] = $process->wait();
            }
        } catch (Throwable $exception) {
            try {
                // Stopping bounds cleanup when a sibling depends on external progress that may never arrive.
                $this->stop(0);
            } catch (Throwable) {
                // Preserve the wait failure.
            }

            throw $exception;
        }

        return new ProcessPoolResults($results);
    }

    /**
     * Get the total number of processes.
     */
    public function count(): int
    {
        return count($this->invokedProcesses);
    }
}
