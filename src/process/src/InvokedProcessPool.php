<?php

declare(strict_types=1);

namespace Hypervel\Process;

use ArrayIterator;
use Countable;
use Hypervel\Contracts\Process\InvokedProcess;
use Hypervel\Support\Collection;
use IteratorAggregate;
use Swoole\Coroutine\CanceledException;
use Throwable;
use Traversable;

/**
 * @implements IteratorAggregate<int|string, InvokedProcess>
 */
class InvokedProcessPool implements Countable, IteratorAggregate
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
                if ($exception === null
                    || ($throwable instanceof CanceledException && ! $exception instanceof CanceledException)) {
                    $exception = $throwable;
                }
            }

            if (! $shouldStop) {
                continue;
            }

            try {
                $process->stop($timeout, $signal);
            } catch (Throwable $throwable) {
                if ($exception === null
                    || ($throwable instanceof CanceledException && ! $exception instanceof CanceledException)) {
                    $exception = $throwable;
                }
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
        /** @var Collection<int|string, InvokedProcess> $running */
        $running = (new Collection($this->invokedProcesses))->filter->running();

        return $running->values();
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
            } catch (Throwable $cleanupException) {
                if ($cleanupException instanceof CanceledException && ! $exception instanceof CanceledException) {
                    throw $cleanupException;
                }
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

    /**
     * Get an iterator for the invoked processes.
     *
     * @return ArrayIterator<int|string, InvokedProcess>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->invokedProcesses);
    }
}
