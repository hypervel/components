<?php

declare(strict_types=1);

namespace Hypervel\Process\Contracts;

interface ProcessResult
{
    /**
     * Get the original command executed by the process.
     */
    public function command(): string;

    /**
     * Determine if the process was successful.
     */
    public function successful(): bool;

    /**
     * Determine if the process failed.
     */
    public function failed(): bool;

    /**
     * Get the exit code of the process.
     */
    public function exitCode(): ?int;

    /**
     * Get the standard output of the process.
     */
    public function output(): string;

    /**
     * Determine if the output contains the given string.
     */
    public function seeInOutput(string $output): bool;

    /**
     * Get the error output of the process.
     */
    public function errorOutput(): string;

    /**
     * Determine if the error output contains the given string.
     */
    public function seeInErrorOutput(string $output): bool;

    /**
     * Throw an exception if the process failed.
     */
    public function throw(?callable $callback = null): static;

    /**
     * Throw an exception if the process failed and the given condition is true.
     */
    public function throwIf(bool $condition, ?callable $callback = null): static;
}
