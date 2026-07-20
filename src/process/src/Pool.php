<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Closure;
use InvalidArgumentException;
use Throwable;

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
     *
     * The caller must wait for or stop the pool before its owning coroutine exits.
     */
    public function start(?callable $output = null): InvokedProcessPool
    {
        call_user_func($this->callback, $this);

        foreach ($this->pendingProcesses as $pendingProcess) {
            if (! $pendingProcess instanceof PendingProcess) { // @phpstan-ignore instanceof.alwaysTrue (defensive validation)
                throw new InvalidArgumentException('Process pool must only contain pending processes.');
            }
        }

        $invokedProcesses = [];

        try {
            foreach ($this->pendingProcesses as $key => $pendingProcess) {
                $invokedProcesses[$key] = $pendingProcess->start(
                    output: $output ? function ($type, $buffer) use ($key, $output) {
                        $output($type, $buffer, $key);
                    } : null
                );
            }
        } catch (Throwable $exception) {
            try {
                // Keep partial construction transactional instead of abandoning already-started children.
                (new InvokedProcessPool($invokedProcesses))->stop(0);
            } catch (Throwable) {
                // Preserve the process creation failure.
            }

            throw $exception;
        }

        return new InvokedProcessPool($invokedProcesses);
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
