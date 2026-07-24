<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Closure;
use Hypervel\Contracts\Process\InvokedProcess as InvokedProcessContract;
use Hypervel\Contracts\Process\ProcessResult as ProcessResultContract;
use Throwable;

class FakeInvokedProcess implements InvokedProcessContract
{
    /**
     * The signals that have been received.
     *
     * @var array<int, int>
     */
    protected array $receivedSignals = [];

    /**
     * The number of times the process should indicate that it is "running".
     */
    protected ?int $remainingRunIterations = null;

    /**
     * The general output handler callback.
     */
    protected ?Closure $outputHandler = null;

    /**
     * Indicates that the output handler has failed.
     */
    protected bool $outputHandlerFailed = false;

    /**
     * The current output's index.
     */
    protected int $nextOutputIndex = 0;

    /**
     * The current error output's index.
     */
    protected int $nextErrorOutputIndex = 0;

    /**
     * Create a new invoked process instance.
     */
    public function __construct(
        protected string $command,
        protected FakeProcessDescription $process
    ) {
    }

    /**
     * Get the process ID if the process is still running.
     */
    public function id(): ?int
    {
        $this->invokeOutputHandlerWithNextLineOfOutput();

        $this->remainingRunIterations ??= $this->process->runIterations;

        return $this->remainingRunIterations === 0 ? null : $this->process->processId;
    }

    /**
     * Get the command line for the process.
     */
    public function command(): string
    {
        return $this->command;
    }

    /**
     * Send a signal to the process.
     */
    public function signal(int $signal): static
    {
        $this->invokeOutputHandlerWithNextLineOfOutput();

        $this->receivedSignals[] = $signal;

        return $this;
    }

    /**
     * Stop the process if it is still running.
     */
    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        $this->remainingRunIterations = 0;

        return null;
    }

    /**
     * Determine if the process has received the given signal.
     */
    public function hasReceivedSignal(int $signal): bool
    {
        return in_array($signal, $this->receivedSignals, true);
    }

    /**
     * Determine if the process is still running.
     */
    public function running(): bool
    {
        $this->invokeOutputHandlerWithNextLineOfOutput();

        $this->remainingRunIterations = is_null($this->remainingRunIterations)
                ? $this->process->runIterations
                : $this->remainingRunIterations;

        if ($this->remainingRunIterations === 0) {
            while ($this->invokeOutputHandlerWithNextLineOfOutput());

            return false;
        }

        $this->remainingRunIterations = $this->remainingRunIterations - 1;

        return true;
    }

    /**
     * Invoke the asynchronous output handler with the next single line of output if necessary.
     *
     * @return array<string, string>|false
     */
    protected function invokeOutputHandlerWithNextLineOfOutput(): array|false
    {
        $outputHandler = $this->outputHandler;

        if ($outputHandler === null || $this->outputHandlerFailed) {
            return false;
        }

        [$outputCount, $outputStartingPoint] = [
            count($this->process->output),
            min($this->nextOutputIndex, $this->nextErrorOutputIndex),
        ];

        for ($i = $outputStartingPoint; $i < $outputCount; ++$i) {
            $currentOutput = $this->process->output[$i];

            if ($currentOutput['type'] === 'out' && $i >= $this->nextOutputIndex) {
                $this->callOutputHandler($outputHandler, 'out', $currentOutput['buffer']);
                $this->nextOutputIndex = $i + 1;

                return $currentOutput;
            }
            if ($currentOutput['type'] === 'err' && $i >= $this->nextErrorOutputIndex) {
                $this->callOutputHandler($outputHandler, 'err', $currentOutput['buffer']);
                $this->nextErrorOutputIndex = $i + 1;

                return $currentOutput;
            }
        }

        return false;
    }

    /**
     * Invoke the output handler for the given output.
     */
    protected function callOutputHandler(Closure $outputHandler, string $type, string $buffer): void
    {
        try {
            $outputHandler($type, $buffer);
        } catch (Throwable $exception) {
            // Match the real process's terminal stop and suppress delivery after callback failure.
            $this->outputHandlerFailed = true;
            $this->remainingRunIterations = 0;

            throw $exception;
        }
    }

    /**
     * Get the standard output for the process.
     */
    public function output(): string
    {
        $this->latestOutput();

        $output = [];

        for ($i = 0; $i < $this->nextOutputIndex; ++$i) {
            if ($this->process->output[$i]['type'] === 'out') {
                $output[] = $this->process->output[$i]['buffer'];
            }
        }

        return $output === [] ? '' : rtrim(implode('', $output), "\n") . "\n";
    }

    /**
     * Get the error output for the process.
     */
    public function errorOutput(): string
    {
        $this->latestErrorOutput();

        $output = [];

        for ($i = 0; $i < $this->nextErrorOutputIndex; ++$i) {
            if ($this->process->output[$i]['type'] === 'err') {
                $output[] = $this->process->output[$i]['buffer'];
            }
        }

        return $output === [] ? '' : rtrim(implode('', $output), "\n") . "\n";
    }

    /**
     * Get the latest standard output for the process.
     */
    public function latestOutput(): string
    {
        $outputCount = count($this->process->output);

        for ($i = $this->nextOutputIndex; $i < $outputCount; ++$i) {
            if ($this->process->output[$i]['type'] === 'out') {
                $output = $this->process->output[$i]['buffer'];
                $this->nextOutputIndex = $i + 1;

                break;
            }

            $this->nextOutputIndex = $i + 1;
        }

        return $output ?? '';
    }

    /**
     * Get the latest error output for the process.
     */
    public function latestErrorOutput(): string
    {
        $outputCount = count($this->process->output);

        for ($i = $this->nextErrorOutputIndex; $i < $outputCount; ++$i) {
            if ($this->process->output[$i]['type'] === 'err') {
                $output = $this->process->output[$i]['buffer'];
                $this->nextErrorOutputIndex = $i + 1;

                break;
            }

            $this->nextErrorOutputIndex = $i + 1;
        }

        return $output ?? '';
    }

    /**
     * Ensure that the process has not timed out.
     */
    public function ensureNotTimedOut(): void
    {
    }

    /**
     * Wait for the process to finish.
     */
    public function wait(?callable $output = null): ProcessResultContract
    {
        if ($output !== null) {
            $this->outputHandler = Closure::fromCallable($output);
        }

        if (! $this->outputHandler) {
            $this->remainingRunIterations = 0;

            return $this->predictProcessResult();
        }

        while ($this->invokeOutputHandlerWithNextLineOfOutput());

        $this->remainingRunIterations = 0;

        return $this->process->toProcessResult($this->command);
    }

    /**
     * Wait until the given callback returns true.
     */
    public function waitUntil(?callable $output = null): ProcessResultContract
    {
        if ($output === null) {
            return $this->wait();
        }

        $shouldStop = false;
        $outputHandler = $this->outputHandler;

        $this->outputHandler = function ($type, $buffer) use ($output, &$shouldStop) {
            return $shouldStop = (bool) call_user_func($output, $type, $buffer);
        };

        try {
            while ($this->running() && ! $shouldStop);

            return $this->process->toProcessResult($this->command);
        } finally {
            $this->outputHandler = $outputHandler;
        }
    }

    /**
     * Get the ultimate process result that will be returned by this "process".
     */
    public function predictProcessResult(): ProcessResultContract
    {
        return $this->process->toProcessResult($this->command);
    }

    /**
     * Set the general output handler for the fake invoked process.
     */
    public function withOutputHandler(?callable $outputHandler): static
    {
        $this->outputHandler = $outputHandler === null
            ? null
            : Closure::fromCallable($outputHandler);

        return $this;
    }
}
