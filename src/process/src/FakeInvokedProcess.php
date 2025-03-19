<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Closure;
use Hypervel\Process\Contracts\InvokedProcess as InvokedProcessContract;
use Hypervel\Process\Contracts\ProcessResult as ProcessResultContract;

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

        return $this->process->processId;
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
     * Determine if the process has received the given signal.
     */
    public function hasReceivedSignal(int $signal): bool
    {
        return in_array($signal, $this->receivedSignals);
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
        if (! $this->outputHandler) {
            return false;
        }

        [$outputCount, $outputStartingPoint] = [
            count($this->process->output),
            min($this->nextOutputIndex, $this->nextErrorOutputIndex),
        ];

        for ($i = $outputStartingPoint; $i < $outputCount; ++$i) {
            $currentOutput = $this->process->output[$i];

            if ($currentOutput['type'] === 'out' && $i >= $this->nextOutputIndex) {
                call_user_func($this->outputHandler, 'out', $currentOutput['buffer']);
                $this->nextOutputIndex = $i + 1;

                return $currentOutput;
            }
            if ($currentOutput['type'] === 'err' && $i >= $this->nextErrorOutputIndex) {
                call_user_func($this->outputHandler, 'err', $currentOutput['buffer']);
                $this->nextErrorOutputIndex = $i + 1;

                return $currentOutput;
            }
        }

        return false;
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

        return rtrim(implode('', $output), "\n") . "\n";
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

        return rtrim(implode('', $output), "\n") . "\n";
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

        return isset($output) ? $output : '';
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

        return isset($output) ? $output : '';
    }

    /**
     * Wait for the process to finish.
     */
    public function wait(?callable $output = null): ProcessResultContract
    {
        $this->outputHandler = $output ?: $this->outputHandler;

        if (! $this->outputHandler) {
            $this->remainingRunIterations = 0;

            return $this->predictProcessResult();
        }

        while ($this->invokeOutputHandlerWithNextLineOfOutput());

        $this->remainingRunIterations = 0;

        return $this->process->toProcessResult($this->command);
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
        $this->outputHandler = $outputHandler;

        return $this;
    }
}
