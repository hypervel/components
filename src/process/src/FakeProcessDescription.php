<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Hypervel\Process\Contracts\ProcessResult;
use Hypervel\Support\Collection;
use Symfony\Component\Process\Process;

class FakeProcessDescription
{
    /**
     * The process' ID.
     */
    public ?int $processId = 1000;

    /**
     * All of the process' output in the order it was described.
     *
     * @var array<int, array<string, string>>
     */
    public array $output = [];

    /**
     * The process' exit code.
     */
    public int $exitCode = 0;

    /**
     * The number of times the process should indicate that it is "running".
     */
    public int $runIterations = 0;

    /**
     * Specify the process ID that should be assigned to the process.
     */
    public function id(int $processId): static
    {
        $this->processId = $processId;

        return $this;
    }

    /**
     * Describe a line of standard output.
     */
    public function output(array|string $output): static
    {
        if (is_array($output)) {
            (new Collection($output))->each(fn ($line) => $this->output($line));

            return $this;
        }

        $this->output[] = ['type' => 'out', 'buffer' => rtrim($output, "\n") . "\n"];

        return $this;
    }

    /**
     * Describe a line of error output.
     */
    public function errorOutput(array|string $output): static
    {
        if (is_array($output)) {
            (new Collection($output))->each(fn ($line) => $this->errorOutput($line));

            return $this;
        }

        $this->output[] = ['type' => 'err', 'buffer' => rtrim($output, "\n") . "\n"];

        return $this;
    }

    /**
     * Replace the entire output buffer with the given string.
     */
    public function replaceOutput(string $output): static
    {
        $this->output = (new Collection($this->output))->reject(function ($output) {
            return $output['type'] === 'out';
        })->values()->all();

        if (strlen($output) > 0) {
            $this->output[] = [
                'type' => 'out',
                'buffer' => rtrim($output, "\n") . "\n",
            ];
        }

        return $this;
    }

    /**
     * Replace the entire error output buffer with the given string.
     */
    public function replaceErrorOutput(string $output): static
    {
        $this->output = (new Collection($this->output))->reject(function ($output) {
            return $output['type'] === 'err';
        })->values()->all();

        if (strlen($output) > 0) {
            $this->output[] = [
                'type' => 'err',
                'buffer' => rtrim($output, "\n") . "\n",
            ];
        }

        return $this;
    }

    /**
     * Specify the process exit code.
     */
    public function exitCode(int $exitCode): static
    {
        $this->exitCode = $exitCode;

        return $this;
    }

    /**
     * Specify how many times the "isRunning" method should return "true".
     */
    public function iterations(int $iterations): static
    {
        return $this->runsFor(iterations: $iterations);
    }

    /**
     * Specify how many times the "isRunning" method should return "true".
     */
    public function runsFor(int $iterations): static
    {
        $this->runIterations = $iterations;

        return $this;
    }

    /**
     * Turn the fake process description into an actual process.
     */
    public function toSymfonyProcess(string $command): Process
    {
        return Process::fromShellCommandline($command);
    }

    /**
     * Convert the process description into a process result.
     */
    public function toProcessResult(string $command): ProcessResult
    {
        return new FakeProcessResult(
            command: $command,
            exitCode: $this->exitCode,
            output: $this->resolveOutput(),
            errorOutput: $this->resolveErrorOutput(),
        );
    }

    /**
     * Resolve the standard output as a string.
     */
    protected function resolveOutput(): string
    {
        $output = (new Collection($this->output))
            ->filter(fn ($output) => $output['type'] === 'out');

        return $output->isNotEmpty()
                    ? rtrim($output->map->buffer->implode(''), "\n") . "\n"
                    : '';
    }

    /**
     * Resolve the error output as a string.
     */
    protected function resolveErrorOutput(): string
    {
        $output = (new Collection($this->output))
            ->filter(fn ($output) => $output['type'] === 'err');

        return $output->isNotEmpty()
                    ? rtrim($output->map->buffer->implode(''), "\n") . "\n"
                    : '';
    }
}
