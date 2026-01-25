<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Closure;
use Hypervel\Support\Collection;
use InvalidArgumentException;

/**
 * @mixin \Hypervel\Process\Factory
 * @mixin \Hypervel\Process\PendingProcess
 */
class Pool
{
    /**
     * The array of pending processes.
     *
     * @var array<int|string, PendingProcess>
     */
    protected array $pendingProcesses = [];

    /**
     * Create a new process pool.
     *
     * @param Factory $factory the process factory instance
     * @param Closure $callback the callback that resolves the pending processes
     */
    public function __construct(
        protected Factory $factory,
        protected Closure $callback
    ) {
    }

    /**
     * Add a process to the pool with a key.
     */
    public function as(string $key): PendingProcess
    {
        return tap($this->factory->newPendingProcess(), function ($pendingProcess) use ($key) {
            $this->pendingProcesses[$key] = $pendingProcess;
        });
    }

    /**
     * Start all of the processes in the pool.
     */
    public function start(?callable $output = null): InvokedProcessPool
    {
        call_user_func($this->callback, $this);

        return new InvokedProcessPool(
            (new Collection($this->pendingProcesses))
                ->each(function ($pendingProcess) {
                    if (! $pendingProcess instanceof PendingProcess) { // @phpstan-ignore instanceof.alwaysTrue (defensive validation)
                        throw new InvalidArgumentException('Process pool must only contain pending processes.');
                    }
                })->mapWithKeys(function ($pendingProcess, $key) use ($output) {
                    return [$key => $pendingProcess->start(output: $output ? function ($type, $buffer) use ($key, $output) {
                        $output($type, $buffer, $key);
                    } : null)];
                })
                ->all()
        );
    }

    /**
     * Start and wait for the processes to finish.
     */
    public function run(): ProcessPoolResults
    {
        return $this->wait();
    }

    /**
     * Start and wait for the processes to finish.
     */
    public function wait(): ProcessPoolResults
    {
        return $this->start()->wait();
    }

    /**
     * Dynamically proxy methods calls to a new pending process.
     */
    public function __call(string $method, array $parameters): PendingProcess
    {
        return tap($this->factory->{$method}(...$parameters), function ($pendingProcess) {
            $this->pendingProcesses[] = $pendingProcess;
        });
    }
}
