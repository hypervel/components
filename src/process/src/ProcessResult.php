<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Hypervel\Process\Contracts\ProcessResult as ProcessResultContract;
use Hypervel\Process\Exceptions\ProcessFailedException;
use Symfony\Component\Process\Process;

class ProcessResult implements ProcessResultContract
{
    /**
     * Create a new process result instance.
     *
     * @param Process $process the underlying process instance
     */
    public function __construct(protected Process $process)
    {
    }

    /**
     * Get the original command executed by the process.
     */
    public function command(): string
    {
        return $this->process->getCommandLine();
    }

    /**
     * Determine if the process was successful.
     */
    public function successful(): bool
    {
        return $this->process->isSuccessful();
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
    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    /**
     * Get the standard output of the process.
     */
    public function output(): string
    {
        return $this->process->getOutput();
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
        return $this->process->getErrorOutput();
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
