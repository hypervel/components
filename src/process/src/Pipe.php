<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Closure;
use Hypervel\Process\Contracts\ProcessResult as ProcessResultContract;
use Hypervel\Support\Collection;
use InvalidArgumentException;

/**
 * @mixin \Hypervel\Process\Factory
 * @mixin \Hypervel\Process\PendingProcess
 */
class Pipe
{
    /**
     * The array of pending processes.
     *
     * @var array<int|string, PendingProcess>
     */
    protected array $pendingProcesses = [];

    /**
     * Create a new series of piped processes.
     */
    public function __construct(
        protected Factory $factory,
        protected Closure $callback
    ) {
    }

    /**
     * Add a process to the pipe with a key.
     */
    public function as(string $key): PendingProcess
    {
        return tap($this->factory->newPendingProcess(), function ($pendingProcess) use ($key) {
            $this->pendingProcesses[$key] = $pendingProcess;
        });
    }

    /**
     * Runs the processes in the pipe.
     */
    public function run(?callable $output = null): ProcessResultContract
    {
        call_user_func($this->callback, $this);

        return (new Collection($this->pendingProcesses))
            ->reduce(function ($previousProcessResult, $pendingProcess, $key) use ($output) {
                if (! $pendingProcess instanceof PendingProcess) { // @phpstan-ignore instanceof.alwaysTrue (defensive validation)
                    throw new InvalidArgumentException('Process pipe must only contain pending processes.');
                }

                if ($previousProcessResult && $previousProcessResult->failed()) {
                    return $previousProcessResult;
                }

                return $pendingProcess->when(
                    $previousProcessResult,
                    fn () => $pendingProcess->input($previousProcessResult->output())
                )->run(output: $output ? function ($type, $buffer) use ($key, $output) {
                    $output($type, $buffer, $key);
                } : null);
            });
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
