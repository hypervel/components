<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Hypervel\Support\Collection;
use Hypervel\Process\Contracts\ProcessResult as ProcessResultContract;
use Hypervel\Process\Exceptions\ProcessFailedException;

class FakeProcessResult implements ProcessResultContract
{
    /**
     * The process output.
     */
    protected string $output;

    /**
     * The process error output.
     */
    protected string $errorOutput;

    /**
     * Create a new process result instance.
     *
     * @param string $command the command string
     * @param int $exitCode the process exit code
     * @param array|string $output the process output
     * @param array|string $errorOutput the process error output
     */
    public function __construct(
        protected string $command = '',
        protected int $exitCode = 0,
        array|string $output = '',
        array|string $errorOutput = ''
    ) {
        $this->output = $this->normalizeOutput($output);
        $this->errorOutput = $this->normalizeOutput($errorOutput);
    }

    /**
     * Normalize the given output into a string with newlines.
     */
    protected function normalizeOutput(array|string $output): string
    {
        if (empty($output)) {
            return '';
        }
        if (is_string($output)) {
            return rtrim($output, "\n") . "\n";
        }
        return rtrim(
            (new Collection($output))
                ->map(fn ($line) => rtrim($line, "\n") . "\n")
                ->implode(''),
            "\n"
        ) . "\n";
    }

    /**
     * Get the original command executed by the process.
     */
    public function command(): string
    {
        return $this->command;
    }

    /**
     * Create a new fake process result with the given command.
     */
    public function withCommand(string $command): self
    {
        return new FakeProcessResult($command, $this->exitCode, $this->output, $this->errorOutput);
    }

    /**
     * Determine if the process was successful.
     */
    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Determine if the process failed.
     */
    public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * Get the exit code of the process.
     */
    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * Get the standard output of the process.
     */
    public function output(): string
    {
        return $this->output;
    }

    /**
     * Determine if the output contains the given string.
     */
    public function seeInOutput(string $output): bool
    {
        return str_contains($this->output(), $output);
    }

    /**
     * Get the error output of the process.
     */
    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * Determine if the error output contains the given string.
     */
    public function seeInErrorOutput(string $output): bool
    {
        return str_contains($this->errorOutput(), $output);
    }

    /**
     * Throw an exception if the process failed.
     *
     * @throws \Hypervel\Process\Exceptions\ProcessFailedException
     */
    public function throw(?callable $callback = null): static
    {
        if ($this->successful()) {
            return $this;
        }

        $exception = new ProcessFailedException($this);

        if ($callback) {
            $callback($this, $exception);
        }

        throw $exception;
    }

    /**
     * Throw an exception if the process failed and the given condition is true.
     *
     * @throws \Hypervel\Process\Exceptions\ProcessFailedException
     */
    public function throwIf(bool $condition, ?callable $callback = null): static
    {
        if ($condition) {
            return $this->throw($callback);
        }

        return $this;
    }
}
