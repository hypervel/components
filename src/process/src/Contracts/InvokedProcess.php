<?php

declare(strict_types=1);

namespace Hypervel\Process\Contracts;

interface InvokedProcess
{
    /**
     * Get the process ID if the process is still running.
     */
    public function id(): ?int;

    /**
     * Send a signal to the process.
     */
    public function signal(int $signal): static;

    /**
     * Determine if the process is still running.
     */
    public function running(): bool;

    /**
     * Get the standard output for the process.
     */
    public function output(): string;

    /**
     * Get the error output for the process.
     */
    public function errorOutput(): string;

    /**
     * Get the latest standard output for the process.
     */
    public function latestOutput(): string;

    /**
     * Get the latest error output for the process.
     */
    public function latestErrorOutput(): string;

    /**
     * Wait for the process to finish.
     */
    public function wait(?callable $output = null): ProcessResult;
}
