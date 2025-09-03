<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Carbon\CarbonImmutable;
use Closure;
use Countable;
use Hypervel\Support\Collection;
use Symfony\Component\Process\Process;

class ProcessPool implements Countable
{
    /**
     * All of the active processes.
     *
     * @var array<int, WorkerProcess>
     */
    public array $processes = [];

    /**
     * The processes that are terminating.
     *
     * @var array<int, array{process: WorkerProcess, terminatedAt: CarbonImmutable}>
     */
    public array $terminatingProcesses = [];

    /**
     * Indicates if the process pool is currently running.
     */
    public bool $working = true;

    /**
     * The output handler.
     */
    public ?Closure $output;

    /**
     * Create a new process pool instance.
     */
    public function __construct(
        public SupervisorOptions $options,
        ?Closure $output = null
    ) {
        $this->options = $options;

        $this->output = $output ?: function () {
        };
    }

    /**
     * Scale the process count.
     */
    public function scale(int $processes): void
    {
        $processes = max(0, (int) $processes);

        if ($processes === count($this->processes)) {
            return;
        }

        if ($processes > count($this->processes)) {
            $this->scaleUp($processes);
        } else {
            $this->scaleDown($processes);
        }
    }

    /**
     * Scale up to the given number of processes.
     */
    protected function scaleUp(int $processes): void
    {
        $difference = $processes - count($this->processes);

        for ($i = 0; $i < $difference; ++$i) {
            $this->start();
        }
    }

    /**
     * Scale down to the given number of processes.
     */
    protected function scaleDown(int $processes): void
    {
        $difference = count($this->processes) - $processes;

        // Here we will slice off the correct number of processes that we need to terminate
        // and remove them from the active process array. We'll be adding them the array
        // of terminating processes where they'll run until they are fully terminated.
        $terminatingProcesses = array_slice(
            $this->processes,
            0,
            $difference
        );

        collect($terminatingProcesses)->each(function ($process) {
            $this->markForTermination($process);
        })->all();

        $this->removeProcesses($difference);

        // Finally we will call the terminate method on each of the processes that need get
        // terminated so they can start terminating. Terminating is a graceful operation
        // so any jobs they are already running will finish running before these quit.
        collect($this->terminatingProcesses)
            ->each(function ($process) {
                $process['process']->terminate();
            });
    }

    /**
     * Mark the given worker process for termination.
     */
    public function markForTermination(WorkerProcess $process): void
    {
        $this->terminatingProcesses[] = [
            'process' => $process, 'terminatedAt' => CarbonImmutable::now(),
        ];
    }

    /**
     * Remove the given number of processes from the process array.
     */
    protected function removeProcesses(int $count): void
    {
        array_splice($this->processes, 0, $count);

        $this->processes = array_values($this->processes);
    }

    /**
     * Add a new worker process to the pool.
     */
    protected function start(): static
    {
        $this->processes[] = $this->createProcess()->handleOutputUsing(function ($type, $line) {
            call_user_func($this->output, $type, $line);
        });

        return $this;
    }

    /**
     * Create a new process instance.
     */
    protected function createProcess(): WorkerProcess
    {
        $class = config('horizon.fast_termination')
                    ? BackgroundProcess::class
                    : Process::class;

        return new WorkerProcess($class::fromShellCommandline(
            $this->options->toWorkerCommand(),
            $this->options->directory
        )->setTimeout(null)->disableOutput());
    }

    /**
     * Evaluate the current state of all of the processes.
     */
    public function monitor(): void
    {
        $this->processes()->each->monitor();
    }

    /**
     * Terminate all current workers and start fresh ones.
     */
    public function restart(): void
    {
        $count = count($this->processes);

        $this->scale(0);

        $this->scale($count);
    }

    /**
     * Pause all of the worker processes.
     */
    public function pause(): void
    {
        $this->working = false;

        collect($this->processes)->each->pause();
    }

    /**
     * Instruct all of the worker processes to continue working.
     */
    public function continue(): void
    {
        $this->working = true;

        collect($this->processes)->each->continue();
    }

    /**
     * Get the processes that are still terminating.
     */
    public function terminatingProcesses(): Collection
    {
        $this->pruneTerminatingProcesses();

        return collect($this->terminatingProcesses);
    }

    /**
     * Remove any non-running processes from the terminating process list.
     */
    public function pruneTerminatingProcesses(): void
    {
        $this->stopTerminatingProcessesThatAreHanging();

        $this->terminatingProcesses = collect(
            $this->terminatingProcesses
        )->filter(function ($process) {
            return $process['process']->isRunning();
        })->all();
    }

    /**
     * Stop any terminating processes that are hanging too long.
     */
    protected function stopTerminatingProcessesThatAreHanging(): void
    {
        foreach ($this->terminatingProcesses as $process) {
            $timeout = $this->options->timeout;

            if ($process['terminatedAt']->addSeconds((int) $timeout)->lte(CarbonImmutable::now())) {
                $process['process']->stop();
            }
        }
    }

    /**
     * Get all of the current processes as a collection.
     */
    public function processes(): Collection
    {
        return collect($this->processes);
    }

    /**
     * Get all of the current running processes as a collection.
     */
    public function runningProcesses(): Collection
    {
        $terminatingProcesses = $this->terminatingProcesses()->map(function ($process) {
            return $process['process'];
        });

        return collect($this->processes)->concat($terminatingProcesses)->filter(function ($process) {
            return $process->process->isRunning();
        });
    }

    /**
     * Get the total active process count, including processes pending termination.
     */
    public function totalProcessCount(): int
    {
        return count($this->processes()) + count($this->terminatingProcesses);
    }

    /**
     * The name of the queue(s) being worked by the pool.
     */
    public function queue(): string
    {
        return $this->options->queue;
    }

    /**
     * Count the total number of processes in the pool.
     */
    public function count(): int
    {
        return count($this->processes);
    }
}
