<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Configuration;

use Closure;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Foundation\Exceptions\ReportableHandler;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use Throwable;

class Exceptions
{
    /**
     * Create a new exception handling configuration instance.
     */
    public function __construct(public Handler $handler)
    {
    }

    /**
     * Register a reportable callback.
     *
     * Boot-only. The callback persists on the shared handler and is considered
     * for every subsequently reported exception in the worker.
     */
    public function report(callable $using): ReportableHandler
    {
        return $this->handler->reportable($using);
    }

    /**
     * Register a reportable callback.
     *
     * Boot-only. The callback persists on the shared handler and is considered
     * for every subsequently reported exception in the worker.
     */
    public function reportable(callable $reportUsing): ReportableHandler
    {
        return $this->handler->reportable($reportUsing);
    }

    /**
     * Register a renderable callback.
     *
     * Boot-only. The callback persists on the shared handler and is considered
     * for every subsequently rendered exception in the worker.
     */
    public function render(callable $using): static
    {
        $this->handler->renderable($using);

        return $this;
    }

    /**
     * Register a renderable callback.
     *
     * Boot-only. The callback persists on the shared handler and is considered
     * for every subsequently rendered exception in the worker.
     */
    public function renderable(callable $renderUsing): static
    {
        $this->handler->renderable($renderUsing);

        return $this;
    }

    /**
     * Register a callback to prepare the final, rendered exception response.
     *
     * Boot-only. The callback replaces the shared handler's response callback for
     * every subsequently rendered exception in the worker.
     */
    public function respond(callable $using): static
    {
        $this->handler->respondUsing($using);

        return $this;
    }

    /**
     * Specify the callback that should be used to throttle reportable exceptions.
     *
     * Boot-only. The callback persists on the shared handler and is considered
     * for every subsequently reported exception in the worker.
     */
    public function throttle(callable $throttleUsing): static
    {
        $this->handler->throttleUsing($throttleUsing);

        return $this;
    }

    /**
     * Register a new exception mapping.
     *
     * Boot-only. The mapping persists on the shared handler and is considered for
     * every subsequently reported or rendered exception in the worker.
     *
     * @throws InvalidArgumentException
     */
    public function map(Closure|string $from, Closure|string|null $to = null): static
    {
        $this->handler->map($from, $to);

        return $this;
    }

    /**
     * Set the log level for the given exception type.
     *
     * Boot-only. The level persists on the shared handler and applies to every
     * subsequently reported exception of that type in the worker.
     *
     * @param class-string<Throwable> $type
     * @param LogLevel::* $level
     */
    public function level(string $type, string $level): static
    {
        $this->handler->level($type, $level);

        return $this;
    }

    /**
     * Register a closure that should be used to build exception context data.
     *
     * Boot-only. The closure persists on the shared handler and runs for every
     * subsequently logged exception in the worker.
     */
    public function context(Closure $contextCallback): static
    {
        $this->handler->buildContextUsing($contextCallback);

        return $this;
    }

    /**
     * Indicate that the given exception type should not be reported.
     *
     * Boot-only. The exception types persist on the shared handler and affect
     * exception reporting for every subsequent request and job in the worker.
     */
    public function dontReport(array|string $class): static
    {
        foreach (Arr::wrap($class) as $exceptionClass) {
            $this->handler->dontReport($exceptionClass);
        }

        return $this;
    }

    /**
     * Indicate that the given exception type should stop job retries.
     *
     * Boot-only. The exception types persist on the shared handler and affect
     * every subsequently failed job in the worker.
     */
    public function dontRetry(array|string $class): static
    {
        foreach (Arr::wrap($class) as $exceptionClass) {
            $this->handler->dontRetry($exceptionClass);
        }

        return $this;
    }

    /**
     * Register a callback to determine if an exception should not be reported.
     *
     * Boot-only. The callback persists on the shared handler and is considered
     * for every subsequently reported exception in the worker.
     *
     * @param (Closure(Throwable): bool) $dontReportWhen
     */
    public function dontReportWhen(Closure $dontReportWhen): static
    {
        $this->handler->dontReportWhen($dontReportWhen);

        return $this;
    }

    /**
     * Register a callback to determine if an exception should stop job retries.
     *
     * Boot-only. The callback persists on the shared handler and is considered for
     * every subsequently failed job in the worker.
     *
     * @template TException of Throwable
     *
     * @param Closure(TException): bool $dontRetryWhen
     */
    public function dontRetryWhen(Closure $dontRetryWhen): static
    {
        $this->handler->dontRetryWhen($dontRetryWhen);

        return $this;
    }

    /**
     * Do not report duplicate exceptions.
     *
     * Boot-only. The setting persists on the shared handler and applies to every
     * subsequent request and job in the worker.
     */
    public function dontReportDuplicates(): static
    {
        $this->handler->dontReportDuplicates();

        return $this;
    }

    /**
     * Indicate that the given attributes should never be flashed to the session on validation errors.
     *
     * Boot-only. The attributes persist on the shared handler and are omitted
     * from every subsequent validation redirect in the worker.
     */
    public function dontFlash(array|string $attributes): static
    {
        $this->handler->dontFlash($attributes);

        return $this;
    }

    /**
     * Register the callable that determines if the exception handler response should be JSON.
     *
     * Boot-only. The callable replaces the shared handler's JSON check for every
     * subsequently rendered exception in the worker.
     */
    public function shouldRenderJsonWhen(callable $callback): static
    {
        $this->handler->shouldRenderJsonWhen($callback);

        return $this;
    }

    /**
     * Indicate that the given exception class should not be ignored.
     *
     * Boot-only. The exception lists persist on the shared handler and affect
     * exception reporting for every subsequent request and job in the worker.
     *
     * @param array<int, class-string<Throwable>>|class-string<Throwable> $class
     */
    public function stopIgnoring(array|string $class): static
    {
        $this->handler->stopIgnoring($class);

        return $this;
    }

    /**
     * Set the truncation length for request exception messages.
     *
     * Boot-only. The global default persists for the worker lifetime across all
     * coroutines; per-request truncation settings take precedence.
     */
    public function truncateRequestExceptionsAt(int $length): static
    {
        RequestException::truncateAt($length);

        return $this;
    }

    /**
     * Disable truncation of request exception messages.
     *
     * Boot-only. The global default persists for the worker lifetime across all
     * coroutines; per-request truncation settings take precedence.
     */
    public function dontTruncateRequestExceptions(): static
    {
        RequestException::dontTruncate();

        return $this;
    }
}
