<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Process;

use BadMethodCallException;
use Closure;
use Hypervel\Process\Exceptions\ProcessFailedException;
use Hypervel\Process\ProcessResult as BaseProcessResult;
use Hypervel\Support\Traits\ForwardsCalls;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Process result with additional passthrough methods for testbench.
 *
 * @internal
 */
final class ProcessResult
{
    use ForwardsCalls;

    /**
     * The methods that should be forwarded to the process instance.
     *
     * @var array<int, string>
     */
    protected array $passthru = [
        'getCommandLine',
        'getErrorOutput',
        'getExitCode',
        'getOutput',
        'isSuccessful',
    ];

    /**
     * Create a new process result instance.
     *
     * @param Process $process The underlying Symfony process
     * @param array<int, string>|Closure|string $command The original command
     */
    public function __construct(
        protected Process $process,
        protected Closure|array|string $command,
    ) {
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
     *
     * @throws JsonException
     */
    public function output(): mixed
    {
        $output = $this->process->getOutput();

        if (! $this->command instanceof Closure) {
            return $output;
        }

        /** @var array{
         *     successful: bool,
         *     result?: string,
         *     exception?: class-string<Throwable>,
         *     message?: string,
         *     parameters?: array<string, mixed>
         * } $result
         */
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        if ($result['successful'] === false) {
            $exception = $result['exception'] ?? RuntimeException::class;
            $parameters = array_values(array_filter(
                $result['parameters'] ?? [],
                static fn (mixed $value): bool => $value !== null
            ));

            if ($parameters === []) {
                $parameters = [$result['message'] ?? 'Serialized closure execution failed.'];
            }

            throw new $exception(...$parameters);
        }

        return unserialize($result['result'] ?? serialize(null));
    }

    /**
     * Determine if the output contains the given string.
     */
    public function seeInOutput(string $output): bool
    {
        return str_contains($this->process->getOutput(), $output);
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
     */
    public function throw(?callable $callback = null): static
    {
        if ($this->successful()) {
            return $this;
        }

        $exception = new ProcessFailedException(new BaseProcessResult($this->process));

        if ($callback !== null) {
            $callback($this, $exception);
        }

        throw $exception;
    }

    /**
     * Throw an exception if the process failed and the given condition is true.
     */
    public function throwIf(bool $condition, ?callable $callback = null): static
    {
        if ($condition) {
            return $this->throw($callback);
        }

        return $this;
    }

    /**
     * Handle dynamic calls to the process instance.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (! in_array($method, $this->passthru)) {
            self::throwBadMethodCallException($method);
        }

        return $this->forwardDecoratedCallTo($this->process, $method, $parameters);
    }
}
