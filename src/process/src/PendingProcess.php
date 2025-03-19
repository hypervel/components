<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Closure;
use Hyperf\Collection\Collection;
use Hyperf\Conditionable\Conditionable;
use Hypervel\Process\Contracts\InvokedProcess as InvokedProcessContract;
use Hypervel\Process\Contracts\ProcessResult as ProcessResultContract;
use Hypervel\Process\Exceptions\ProcessTimedOutException;
use Hypervel\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process;
use Throwable;
use Traversable;

use function Hyperf\Tappable\tap;

class PendingProcess
{
    use Conditionable;

    /**
     * The process factory instance.
     */
    protected Factory $factory;

    /**
     * The command to invoke the process.
     *
     * @var null|array<array-key, string>|string
     */
    public null|array|string $command = null;

    /**
     * The working directory of the process.
     */
    public ?string $path = null;

    /**
     * The maximum number of seconds the process may run.
     */
    public ?int $timeout = 60;

    /**
     * The maximum number of seconds the process may go without returning output.
     */
    public ?int $idleTimeout = null;

    /**
     * The additional environment variables for the process.
     *
     * @var array<string, string>
     */
    public array $environment = [];

    /**
     * The standard input data that should be piped into the command.
     *
     * @var null|bool|float|int|resource|string|Traversable
     */
    public mixed $input = null;

    /**
     * Indicates whether output should be disabled for the process.
     */
    public bool $quietly = false;

    /**
     * Indicates if TTY mode should be enabled.
     */
    public bool $tty = false;

    /**
     * The options that will be passed to "proc_open".
     *
     * @var array<string, mixed>
     */
    public array $options = [];

    /**
     * The registered fake handler callbacks.
     *
     * @var array<string, callable>
     */
    protected array $fakeHandlers = [];

    /**
     * Create a new pending process instance.
     */
    public function __construct(Factory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Specify the command that will invoke the process.
     */
    public function command(array|string $command): static
    {
        $this->command = $command;

        return $this;
    }

    /**
     * Specify the working directory of the process.
     */
    public function path(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Specify the maximum number of seconds the process may run.
     */
    public function timeout(int $timeout): static
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Specify the maximum number of seconds a process may go without returning output.
     */
    public function idleTimeout(int $timeout): static
    {
        $this->idleTimeout = $timeout;

        return $this;
    }

    /**
     * Indicate that the process may run forever without timing out.
     */
    public function forever(): static
    {
        $this->timeout = null;

        return $this;
    }

    /**
     * Set the additional environment variables for the process.
     *
     * @param array<string, string> $environment
     */
    public function env(array $environment): static
    {
        $this->environment = $environment;

        return $this;
    }

    /**
     * Set the standard input that should be provided when invoking the process.
     *
     * @param null|bool|float|int|resource|string|Traversable $input
     */
    public function input(mixed $input): static
    {
        $this->input = $input;

        return $this;
    }

    /**
     * Disable output for the process.
     */
    public function quietly(): static
    {
        $this->quietly = true;

        return $this;
    }

    /**
     * Enable TTY mode for the process.
     */
    public function tty(bool $tty = true): static
    {
        $this->tty = $tty;

        return $this;
    }

    /**
     * Set the "proc_open" options that should be used when invoking the process.
     *
     * @param array<string, mixed> $options
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Run the process.
     *
     * @throws \Hypervel\Process\Exceptions\ProcessTimedOutException
     * @throws RuntimeException
     */
    public function run(null|array|string $command = null, ?callable $output = null): ProcessResultContract
    {
        $this->command = $command ?: $this->command;

        $process = $this->toSymfonyProcess($command);
        try {
            if ($fake = $this->fakeFor($command = $process->getCommandline())) {
                return tap($this->resolveSynchronousFake($command, $fake), function ($result) {
                    $this->factory->recordIfRecording($this, $result);
                });
            } elseif ($this->factory->isRecording() && $this->factory->preventingStrayProcesses()) {
                throw new RuntimeException('Attempted process [' . $command . '] without a matching fake.');
            }

            return new ProcessResult(tap($process)->run($output));
        } catch (SymfonyTimeoutException $e) {
            throw new ProcessTimedOutException($e, new ProcessResult($process));
        }
    }

    /**
     * Start the process in the background.
     *
     * @throws RuntimeException
     */
    public function start(null|array|string $command = null, ?callable $output = null): InvokedProcessContract
    {
        $this->command = $command ?: $this->command;

        $process = $this->toSymfonyProcess($command);

        if ($fake = $this->fakeFor($command = $process->getCommandline())) {
            return tap($this->resolveAsynchronousFake($command, $output, $fake), function (InvokedProcessContract $process) {
                /** @var \Hypervel\Process\FakeInvokedProcess $process */
                $this->factory->recordIfRecording($this, $process->predictProcessResult());
            });
        } elseif ($this->factory->isRecording() && $this->factory->preventingStrayProcesses()) {
            throw new RuntimeException('Attempted process [' . $command . '] without a matching fake.');
        }

        return new InvokedProcess(tap($process)->start($output));
    }

    /**
     * Get a Symfony Process instance from the current pending command.
     */
    protected function toSymfonyProcess(null|array|string $command): Process
    {
        $command = $command ?? $this->command;

        $process = is_iterable($command)
                ? new Process($command, null, $this->environment)
                : Process::fromShellCommandline((string) $command, null, $this->environment);

        $process->setWorkingDirectory((string) ($this->path ?? getcwd()));
        $process->setTimeout($this->timeout);

        if ($this->idleTimeout) {
            $process->setIdleTimeout($this->idleTimeout);
        }

        if ($this->input) {
            $process->setInput($this->input);
        }

        if ($this->quietly) {
            $process->disableOutput();
        }

        if ($this->tty) {
            $process->setTty(true);
        }

        if (! empty($this->options)) {
            $process->setOptions($this->options);
        }

        return $process;
    }

    /**
     * Specify the fake process result handlers for the pending process.
     *
     * @param array<string, callable> $fakeHandlers
     */
    public function withFakeHandlers(array $fakeHandlers): static
    {
        $this->fakeHandlers = $fakeHandlers;

        return $this;
    }

    /**
     * Get the fake handler for the given command, if applicable.
     */
    protected function fakeFor(string $command): ?callable
    {
        return (new Collection($this->fakeHandlers))
            ->first(fn ($handler, $pattern) => $pattern === '*' || Str::is($pattern, $command));
    }

    /**
     * Resolve the given fake handler for a synchronous process.
     *
     * @throws LogicException
     */
    protected function resolveSynchronousFake(string $command, Closure $fake): ProcessResultContract
    {
        $result = $fake($this);

        if (is_int($result)) {
            return (new FakeProcessResult(exitCode: $result))->withCommand($command);
        }

        if (is_string($result) || is_array($result)) {
            return (new FakeProcessResult(output: $result))->withCommand($command);
        }

        return match (true) {
            $result instanceof ProcessResult => $result,
            $result instanceof FakeProcessResult => $result->withCommand($command),
            $result instanceof FakeProcessDescription => $result->toProcessResult($command),
            $result instanceof FakeProcessSequence => $this->resolveSynchronousFake($command, fn () => $result()),
            $result instanceof Throwable => throw $result,
            default => throw new LogicException('Unsupported synchronous process fake result provided.'),
        };
    }

    /**
     * Resolve the given fake handler for an asynchronous process.
     *
     * @throws LogicException
     */
    protected function resolveAsynchronousFake(string $command, ?callable $output, Closure $fake): InvokedProcessContract
    {
        $result = $fake($this);

        if (is_string($result) || is_array($result)) {
            $result = new FakeProcessResult(output: $result);
        }

        if ($result instanceof ProcessResult) {
            return (new FakeInvokedProcess(
                $command,
                (new FakeProcessDescription())
                    ->replaceOutput($result->output())
                    ->replaceErrorOutput($result->errorOutput())
                    ->runsFor(iterations: 0)
                    ->exitCode($result->exitCode())
            ))->withOutputHandler($output);
        }
        if ($result instanceof FakeProcessResult) {
            return (new FakeInvokedProcess(
                $command,
                (new FakeProcessDescription())
                    ->replaceOutput($result->output())
                    ->replaceErrorOutput($result->errorOutput())
                    ->runsFor(iterations: 0)
                    ->exitCode($result->exitCode())
            ))->withOutputHandler($output);
        }
        if ($result instanceof FakeProcessDescription) {
            return (new FakeInvokedProcess($command, $result))->withOutputHandler($output);
        }
        if ($result instanceof FakeProcessSequence) {
            return $this->resolveAsynchronousFake($command, $output, fn () => $result());
        }

        throw new LogicException('Unsupported asynchronous process fake result provided.');
    }
}
