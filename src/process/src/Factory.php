<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Closure;
use Hyperf\Collection\Collection;
use Hyperf\Macroable\Macroable;
use Hypervel\Process\Contracts\ProcessResult as ProcessResultContract;
use PHPUnit\Framework\Assert as PHPUnit;

class Factory
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * Indicates if the process factory has faked process handlers.
     */
    protected bool $recording = false;

    /**
     * All of the recorded processes.
     *
     * @var array<int, array>
     */
    protected array $recorded = [];

    /**
     * The registered fake handler callbacks.
     *
     * @var array<string, Closure>
     */
    protected array $fakeHandlers = [];

    /**
     * Indicates that an exception should be thrown if any process is not faked.
     */
    protected bool $preventStrayProcesses = false;

    /**
     * Create a new fake process response for testing purposes.
     */
    public function result(array|string $output = '', array|string $errorOutput = '', int $exitCode = 0): FakeProcessResult
    {
        return new FakeProcessResult(
            output: $output,
            errorOutput: $errorOutput,
            exitCode: $exitCode,
        );
    }

    /**
     * Begin describing a fake process lifecycle.
     */
    public function describe(): FakeProcessDescription
    {
        return new FakeProcessDescription();
    }

    /**
     * Begin describing a fake process sequence.
     *
     * @param array<int, mixed> $processes
     */
    public function sequence(array $processes = []): FakeProcessSequence
    {
        return new FakeProcessSequence($processes);
    }

    /**
     * Indicate that the process factory should fake processes.
     */
    public function fake(array|Closure|null $callback = null): static
    {
        $this->recording = true;

        if (is_null($callback)) {
            $this->fakeHandlers = ['*' => fn () => new FakeProcessResult()];

            return $this;
        }

        if ($callback instanceof Closure) {
            $this->fakeHandlers = ['*' => $callback];

            return $this;
        }

        foreach ($callback as $command => $handler) {
            $this->fakeHandlers[is_numeric($command) ? '*' : $command] = $handler instanceof Closure
                    ? $handler
                    : fn () => $handler;
        }

        return $this;
    }

    /**
     * Determine if the process factory has fake process handlers and is recording processes.
     */
    public function isRecording(): bool
    {
        return $this->recording;
    }

    /**
     * Record the given process if processes should be recorded.
     */
    public function recordIfRecording(PendingProcess $process, ProcessResultContract $result): static
    {
        if ($this->isRecording()) {
            $this->record($process, $result);
        }

        return $this;
    }

    /**
     * Record the given process.
     */
    public function record(PendingProcess $process, ProcessResultContract $result): static
    {
        $this->recorded[] = [$process, $result];

        return $this;
    }

    /**
     * Indicate that an exception should be thrown if any process is not faked.
     */
    public function preventStrayProcesses(bool $prevent = true): static
    {
        $this->preventStrayProcesses = $prevent;

        return $this;
    }

    /**
     * Determine if stray processes are being prevented.
     */
    public function preventingStrayProcesses(): bool
    {
        return $this->preventStrayProcesses;
    }

    /**
     * Assert that a process was recorded matching a given truth test.
     */
    public function assertRan(Closure|string $callback): static
    {
        $callback = is_string($callback) ? fn ($process) => $process->command === $callback : $callback;

        PHPUnit::assertTrue(
            collect($this->recorded)->filter(function ($pair) use ($callback) {
                return $callback($pair[0], $pair[1]);
            })->count() > 0,
            'An expected process was not invoked.'
        );

        return $this;
    }

    /**
     * Assert that a process was recorded a given number of times matching a given truth test.
     */
    public function assertRanTimes(Closure|string $callback, int $times = 1): static
    {
        $callback = is_string($callback) ? fn ($process) => $process->command === $callback : $callback;

        $count = collect($this->recorded)->filter(function ($pair) use ($callback) {
            return $callback($pair[0], $pair[1]);
        })->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "An expected process ran {$count} times instead of {$times} times."
        );

        return $this;
    }

    /**
     * Assert that a process was not recorded matching a given truth test.
     */
    public function assertNotRan(Closure|string $callback): static
    {
        $callback = is_string($callback) ? fn ($process) => $process->command === $callback : $callback;

        PHPUnit::assertTrue(
            collect($this->recorded)->filter(function ($pair) use ($callback) {
                return $callback($pair[0], $pair[1]);
            })->count() === 0,
            'An unexpected process was invoked.'
        );

        return $this;
    }

    /**
     * Assert that a process was not recorded matching a given truth test.
     */
    public function assertDidntRun(Closure|string $callback): static
    {
        return $this->assertNotRan($callback);
    }

    /**
     * Assert that no processes were recorded.
     */
    public function assertNothingRan(): static
    {
        PHPUnit::assertEmpty(
            $this->recorded,
            'An unexpected process was invoked.'
        );

        return $this;
    }

    /**
     * Start defining a pool of processes.
     */
    public function pool(callable $callback): Pool
    {
        return new Pool($this, $callback);
    }

    /**
     * Start defining a series of piped processes.
     */
    public function pipe(array|callable $callback, ?callable $output = null): ProcessResultContract
    {
        return is_array($callback)
            ? (new Pipe($this, fn ($pipe) => (new Collection($callback))->each(
                fn ($command) => $pipe->command($command)
            )))->run(output: $output)
            : (new Pipe($this, $callback))->run(output: $output);
    }

    /**
     * Run a pool of processes and wait for them to finish executing.
     */
    public function concurrently(callable $callback, ?callable $output = null): ProcessPoolResults
    {
        return (new Pool($this, $callback))->start($output)->wait();
    }

    /**
     * Create a new pending process associated with this factory.
     */
    public function newPendingProcess(): PendingProcess
    {
        return (new PendingProcess($this))->withFakeHandlers($this->fakeHandlers);
    }

    /**
     * Dynamically proxy methods to a new pending process instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->newPendingProcess()->{$method}(...$parameters);
    }
}
