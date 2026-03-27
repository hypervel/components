<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Hypervel\Contracts\Process\InvokedProcess as InvokedProcessContract;
use Hypervel\Contracts\Process\ProcessResult as ProcessResultContract;
use Hypervel\Process\Exceptions\ProcessTimedOutException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process;

class InvokedProcess implements InvokedProcessContract
{
    /**
     * Create a new invoked process instance.
     *
     * @param Process $process the underlying process instance
     */
    public function __construct(protected Process $process)
    {
    }

    /**
     * Get the process ID if the process is still running.
     */
    public function id(): ?int
    {
        return $this->process->getPid();
    }

    /**
     * Get the command line for the process.
     */
    public function command(): string
    {
        return $this->process->getCommandLine();
    }

    /**
     * Send a signal to the process.
     */
    public function signal(int $signal): static
    {
        $this->process->signal($signal);

        return $this;
    }

    /**
     * Stop the process if it is still running.
     */
    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        return $this->process->stop($timeout, $signal);
    }

    /**
     * Determine if the process is still running.
     */
    public function running(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Get the standard output for the process.
     */
    public function output(): string
    {
        return $this->process->getOutput();
    }

    /**
     * Get the error output for the process.
     */
    public function errorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    /**
     * Get the latest standard output for the process.
     */
    public function latestOutput(): string
    {
        return $this->process->getIncrementalOutput();
    }

    /**
     * Get the latest error output for the process.
     */
    public function latestErrorOutput(): string
    {
        return $this->process->getIncrementalErrorOutput();
    }

    /**
     * Ensure that the process has not timed out.
     *
     * @throws ProcessTimedOutException
     */
    public function ensureNotTimedOut(): void
    {
        try {
            $this->process->checkTimeout();
        } catch (SymfonyTimeoutException $e) {
            throw new ProcessTimedOutException($e, new ProcessResult($this->process));
        }
    }

    /**
     * Wait for the process to finish.
     *
     * @throws ProcessTimedOutException
     */
    public function wait(?callable $output = null): ProcessResultContract
    {
        try {
            $this->process->wait($output);

            return new ProcessResult($this->process);
        } catch (SymfonyTimeoutException $e) {
            throw new ProcessTimedOutException($e, new ProcessResult($this->process));
        }
    }

    /**
     * Wait until the given callback returns true.
     *
     * @throws ProcessTimedOutException
     */
    public function waitUntil(?callable $output = null): ProcessResultContract
    {
        try {
            $this->process->waitUntil($output);

            return new ProcessResult($this->process);
        } catch (SymfonyTimeoutException $e) {
            throw new ProcessTimedOutException($e, new ProcessResult($this->process));
        }
    }
}
