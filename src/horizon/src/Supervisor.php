<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Carbon\CarbonImmutable;
use Closure;
use Exception;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler;
use Hypervel\Horizon\Contracts\HorizonCommandQueue;
use Hypervel\Horizon\Contracts\Pausable;
use Hypervel\Horizon\Contracts\Restartable;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\Contracts\Terminable;
use Hypervel\Horizon\Events\SupervisorLooped;
use Hypervel\Support\Collection;
use Throwable;

class Supervisor implements Pausable, Restartable, Terminable
{
    use ListensForSignals;

    /**
     * The name of this supervisor instance.
     */
    public string $name;

    /**
     * All of the process pools being managed.
     *
     * @var Collection<int, ProcessPool>
     */
    public Collection $processPools;

    /**
     * Indicates if the Supervisor processes are working.
     */
    public bool $working = true;

    /**
     * The time at which auto-scaling last ran for this supervisor.
     */
    public ?CarbonImmutable $lastAutoScaled = null;

    /**
     * The output handler.
     */
    public ?Closure $output = null;

    public bool $shouldExitLoop = false;

    /**
     * Create a new supervisor instance.
     *
     * @param SupervisorOptions $options the SupervisorOptions that should be utilized
     */
    public function __construct(
        public SupervisorOptions $options
    ) {
        $this->name = $options->name;
        $this->processPools = $this->createProcessPools();

        $this->output = function () {
        };

        app(HorizonCommandQueue::class)->flush($this->name);
    }

    /**
     * Create the supervisor's process pools.
     */
    public function createProcessPools(): Collection
    {
        return $this->options->balancing()
                        ? $this->createProcessPoolPerQueue()
                        : $this->createSingleProcessPool();
    }

    /**
     * Create a process pool for each queue.
     */
    protected function createProcessPoolPerQueue(): Collection
    {
        return collect(explode(',', $this->options->queue))->map(function ($queue) {
            return $this->createProcessPool($this->options->withQueue($queue));
        });
    }

    /**
     * Create a single process pool.
     */
    protected function createSingleProcessPool(): Collection
    {
        return collect([$this->createProcessPool($this->options)]);
    }

    /**
     * Create a new process pool with the given options.
     */
    protected function createProcessPool(SupervisorOptions $options): ProcessPool
    {
        return new ProcessPool($options, function ($type, $line) {
            $this->output($type, $line);
        });
    }

    /**
     * Scale the process count.
     */
    public function scale(int $processes): void
    {
        $this->options->maxProcesses = max(
            $this->options->maxProcesses,
            $processes,
            count($this->processPools)
        );

        $this->balance($this->processPools->mapWithKeys(function ($pool) use ($processes) {
            return [$pool->queue() => (int) floor($processes / count($this->processPools))];
        })->all());
    }

    /**
     * Balance the process pool at the given scales.
     */
    public function balance(array $balance): void
    {
        foreach ($balance as $queue => $scale) {
            $this->processPools->first(function ($pool) use ($queue) {
                return $pool->queue() === $queue;
            }, new class {
                public function __call($method, $arguments)
                {
                }
            })->scale($scale);
        }
    }

    /**
     * Terminate all current workers and start fresh ones.
     */
    public function restart(): void
    {
        $this->working = true;

        $this->processPools->each->restart();
    }

    /**
     * Pause all of the worker processes.
     */
    public function pause(): void
    {
        $this->working = false;

        $this->processPools->each->pause();
    }

    /**
     * Instruct all of the worker processes to continue working.
     */
    public function continue(): void
    {
        $this->working = true;

        $this->processPools->each->continue();
    }

    /**
     * Terminate this supervisor process and all of its workers.
     */
    public function terminate(int $status = 0): void
    {
        $this->working = false;

        // We will mark this supervisor as terminating so that any user interface can
        // correctly show the supervisor's status. Then, we will scale the process
        // pools down to zero workers to gracefully terminate them all out here.
        app(SupervisorRepository::class)->forget($this->name);

        $this->processPools->each(function ($pool) {
            $pool->processes()->each(function ($process) {
                $process->terminate();
            });
        });

        if ($this->shouldWait()) {
            while ($this->processPools->map->runningProcesses()->collapse()->count()) {
                sleep(1);
            }
        }

        $this->shouldExitLoop = true;
    }

    /**
     * Check if the supervisor should wait for all its workers to terminate.
     */
    protected function shouldWait(): bool
    {
        // @phpstan-ignore-next-line
        return ! config('horizon.fast_termination') || app(CacheFactory::class)->get('horizon:terminate:wait');
    }

    /**
     * Monitor the worker processes.
     */
    public function monitor(): void
    {
        $this->ensureNoDuplicateSupervisors();

        $this->listenForSignals();

        $this->persist();

        while (true) {
            sleep(1);

            $this->loop();

            if ($this->shouldExitLoop) {
                break;
            }
        }
    }

    /**
     * Ensure no other supervisors are running with the same name.
     *
     * @throws Exception
     */
    public function ensureNoDuplicateSupervisors(): void
    {
        if (app(SupervisorRepository::class)->find($this->name) !== null) {
            throw new Exception("A supervisor with the name [{$this->name}] is already running.");
        }
    }

    /**
     * Perform a monitor loop.
     */
    public function loop(): void
    {
        try {
            $this->ensureParentIsRunning();

            $this->processPendingSignals();

            $this->processPendingCommands();

            // If the supervisor is working, we will perform any needed scaling operations and
            // monitor all of these underlying worker processes to make sure they are still
            // processing queued jobs. If they have died, we will restart them each here.
            if ($this->working) {
                $this->autoScale();

                $this->processPools->each->monitor();
            }

            // Next, we'll persist the supervisor state to storage so that it can be read by a
            // user interface. This contains information on the specific options for it and
            // the current number of worker processes per queue for easy load monitoring.
            go(fn () => $this->persist());

            event(new SupervisorLooped($this));
        } catch (Throwable $e) {
            app(ExceptionHandler::class)->report($e);
        }
    }

    /**
     * Ensure the parent process is still running.
     */
    protected function ensureParentIsRunning(): void
    {
        if ($this->options->parentId > 1 && posix_getppid() <= 1) {
            $this->terminate();
        }
    }

    /**
     * Handle any pending commands for the supervisor.
     */
    protected function processPendingCommands(): void
    {
        foreach (app(HorizonCommandQueue::class)->pending($this->name) as $command) {
            app($command->command)->process($this, $command->options);
        }
    }

    /**
     * Run the auto-scaling routine for the supervisor.
     */
    protected function autoScale(): void
    {
        $this->lastAutoScaled = $this->lastAutoScaled
                    ?: CarbonImmutable::now()->subSeconds($this->options->balanceCooldown + 1);

        if (CarbonImmutable::now()->subSeconds($this->options->balanceCooldown)->gte($this->lastAutoScaled)) {
            $this->lastAutoScaled = CarbonImmutable::now();

            app(AutoScaler::class)->scale($this);
        }
    }

    /**
     * Persist information about this supervisor instance.
     */
    public function persist(): void
    {
        app(SupervisorRepository::class)->update($this);
    }

    /**
     * Prune all terminating processes and return the total process count.
     */
    public function pruneAndGetTotalProcesses(): int
    {
        $this->pruneTerminatingProcesses();

        return $this->totalProcessCount();
    }

    /**
     * Prune any terminating processes that have finished terminating.
     */
    public function pruneTerminatingProcesses(): void
    {
        $this->processPools->each->pruneTerminatingProcesses();
    }

    /**
     * Get all of the current processes as a collection.
     */
    public function processes(): Collection
    {
        return $this->processPools->map->processes()->collapse();
    }

    /**
     * Get the processes that are still terminating.
     */
    public function terminatingProcesses(): Collection
    {
        return $this->processPools->map->terminatingProcesses()->collapse();
    }

    /**
     * Get the total active process count, including processes pending termination.
     */
    public function totalProcessCount(): int
    {
        return $this->processPools->sum->totalProcessCount();
    }

    /**
     * Get the total active process count by asking the OS.
     */
    public function totalSystemProcessCount(): int
    {
        return app(SystemProcessCounter::class)->get($this->name);
    }

    /**
     * Get the process ID for this supervisor.
     */
    public function pid(): int
    {
        return getmypid();
    }

    /**
     * Get the current memory usage (in megabytes).
     */
    public function memoryUsage(): float
    {
        return memory_get_usage() / 1024 / 1024;
    }

    /**
     * Determine if the supervisor is paused.
     */
    public function isPaused(): bool
    {
        return ! $this->working;
    }

    /**
     * Set the output handler.
     */
    public function handleOutputUsing(Closure $callback): static
    {
        $this->output = $callback;

        return $this;
    }

    /**
     * Handle the given output.
     */
    public function output(string $type, string $line): void
    {
        call_user_func($this->output, $type, $line);
    }
}
