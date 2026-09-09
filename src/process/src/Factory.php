<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Closure;
use Hypervel\Contracts\Process\ProcessResult as ProcessResultContract;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Macroable;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @mixin PendingProcess
 */
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
        return new FakeProcessDescription;
    }

    /**
     * Begin describing a fake process sequence.
     *
     * @param array<int, array|FakeProcessDescription|ProcessResultContract|string> $processes
     */
    public function sequence(array $processes = []): FakeProcessSequence
    {
        return new FakeProcessSequence($processes);
    }

    /**
     * Indicate that the process factory should fake processes.
     *
     * Tests only. Fake handlers and recordings persist on the container-resolved factory for the worker lifetime and affect later calls.
     */
    public function fake(array|Closure|null $callback = null): static
    {
        $this->recording = true;

        if (is_null($callback)) {
            $this->fakeHandlers = ['*' => fn () => new FakeProcessResult];

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
     *
     * Tests only. Recorded processes are retained on the worker factory and affect later assertions and memory retention.
     */
    public function record(PendingProcess $process, ProcessResultContract $result): static
    {
        $this->recorded[] = [$process, $result];

        return $this;
    }

    /**
     * Indicate that an exception should be thrown if any process is not faked.
     *
     * Tests only. This setting persists on the worker factory and can reject every later unmatched process.
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
     *
     * @param array<array-key, string>|Closure|string $callback
     */
    public function assertRan(Closure|array|string $callback): static
    {
        $callback = $callback instanceof Closure ? $callback : fn ($process) => $process->command === $callback;

        PHPUnit::assertTrue(
            (new Collection($this->recorded))->contains(function ($pair) use ($callback) {
                return $callback($pair[0], $pair[1]);
            }),
            'An expected process was not invoked.'
        );

        return $this;
    }

    /**
     * Assert that a process was recorded a given number of times matching a given truth test.
     *
     * @param array<array-key, string>|Closure|string $callback
     */
    public function assertRanTimes(Closure|array|string $callback, int $times = 1): static
    {
        $callback = $callback instanceof Closure ? $callback : fn ($process) => $process->command === $callback;

        $count = (new Collection($this->recorded))
            ->filter(fn ($pair) => $callback($pair[0], $pair[1]))
            ->count();

        PHPUnit::assertSame(
            $times,
            $count,
            "An expected process ran {$count} times instead of {$times} times."
        );

        return $this;
    }

    /**
     * Assert that the given processes were run in the given order.
     *
     * @param list<array<array-key, string>|Closure|string> $callbacks
     */
    public function assertRanInOrder(array $callbacks): static
    {
        $this->assertRanCount(count($callbacks));

        foreach ($callbacks as $index => $callback) {
            $callback = $callback instanceof Closure
                ? $callback
                : fn ($process) => $process->command === $callback;

            PHPUnit::assertTrue(
                $callback($this->recorded[$index][0], $this->recorded[$index][1]),
                'An expected process (#' . ($index + 1) . ') was not invoked.'
            );
        }

        return $this;
    }

    /**
     * Assert how many processes have been recorded.
     */
    protected function assertRanCount(int $count): static
    {
        PHPUnit::assertCount($count, $this->recorded);

        return $this;
    }

    /**
     * Assert that a process was not recorded matching a given truth test.
     *
     * @param array<array-key, string>|Closure|string $callback
     */
    public function assertNotRan(Closure|array|string $callback): static
    {
        $callback = $callback instanceof Closure ? $callback : fn ($process) => $process->command === $callback;

        PHPUnit::assertTrue(
            (new Collection($this->recorded))->doesntContain(function ($pair) use ($callback) {
                return $callback($pair[0], $pair[1]);
            }),
            'An unexpected process was invoked.'
        );

        return $this;
    }

    /**
     * Assert that a process was not recorded matching a given truth test.
     *
     * @param array<array-key, string>|Closure|string $callback
     */
    public function assertDidntRun(Closure|array|string $callback): static
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
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
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
