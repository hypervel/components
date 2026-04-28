<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Configuration;

use Closure;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Foundation\Exceptions\ReportableHandler;
use Hypervel\Http\Client\RequestException;
use Hypervel\Support\Arr;
use InvalidArgumentException;
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
     */
    public function report(callable $using): ReportableHandler
    {
        return $this->handler->reportable($using);
    }

    /**
     * Register a reportable callback.
     */
    public function reportable(callable $reportUsing): ReportableHandler
    {
        return $this->handler->reportable($reportUsing);
    }

    /**
     * Register a renderable callback.
     */
    public function render(callable $using): static
    {
        $this->handler->renderable($using);

        return $this;
    }

    /**
     * Register a renderable callback.
     */
    public function renderable(callable $renderUsing): static
    {
        $this->handler->renderable($renderUsing);

        return $this;
    }

    /**
     * Register a callback to prepare the final, rendered exception response.
     */
    public function respond(callable $using): static
    {
        $this->handler->respondUsing($using);

        return $this;
    }

    /**
     * Specify the callback that should be used to throttle reportable exceptions.
     */
    public function throttle(callable $throttleUsing): static
    {
        $this->handler->throttleUsing($throttleUsing);

        return $this;
    }

    /**
     * Register a new exception mapping.
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
     * @param class-string<Throwable> $type
     * @param \Psr\Log\LogLevel::* $level
     */
    public function level(string $type, string $level): static
    {
        $this->handler->level($type, $level);

        return $this;
    }

    /**
     * Register a closure that should be used to build exception context data.
     */
    public function context(Closure $contextCallback): static
    {
        $this->handler->buildContextUsing($contextCallback);

        return $this;
    }

    /**
     * Indicate that the given exception type should not be reported.
     */
    public function dontReport(array|string $class): static
    {
        foreach (Arr::wrap($class) as $exceptionClass) {
            $this->handler->dontReport($exceptionClass);
        }

        return $this;
    }

    /**
     * Register a callback to determine if an exception should not be reported.
     *
     * @param (Closure(Throwable): bool) $dontReportWhen
     */
    public function dontReportWhen(Closure $dontReportWhen): static
    {
        $this->handler->dontReportWhen($dontReportWhen);

        return $this;
    }

    /**
     * Do not report duplicate exceptions.
     */
    public function dontReportDuplicates(): static
    {
        $this->handler->dontReportDuplicates();

        return $this;
    }

    /**
     * Indicate that the given attributes should never be flashed to the session on validation errors.
     */
    public function dontFlash(array|string $attributes): static
    {
        $this->handler->dontFlash($attributes);

        return $this;
    }

    /**
     * Register the callable that determines if the exception handler response should be JSON.
     */
    public function shouldRenderJsonWhen(callable $callback): static
    {
        $this->handler->shouldRenderJsonWhen($callback);

        return $this;
    }

    /**
     * Indicate that the given exception class should not be ignored.
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
     */
    public function truncateRequestExceptionsAt(int $length): static
    {
        RequestException::truncateAt($length);

        return $this;
    }

    /**
     * Disable truncation of request exception messages.
     */
    public function dontTruncateRequestExceptions(): static
    {
        RequestException::dontTruncate();

        return $this;
    }
}
