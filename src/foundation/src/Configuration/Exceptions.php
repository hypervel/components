<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Configuration;

use Closure;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Foundation\Exceptions\ReportableHandler;
use Hypervel\Http\Client\RequestException;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Hypervel\Support\Attributes\BootOnly;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response;
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
     * On the application's worker-shared handler, the callback affects exception
     * reporting for every subsequent request and job in the worker.
     */
    public function report(callable $using): ReportableHandler
    {
        return $this->handler->reportable($using);
    }

    /**
     * Register a reportable callback.
     *
     * On the application's worker-shared handler, the callback affects exception
     * reporting for every subsequent request and job in the worker.
     */
    public function reportable(callable $reportUsing): ReportableHandler
    {
        return $this->handler->reportable($reportUsing);
    }

    /**
     * Register a renderable callback.
     *
     * On the application's worker-shared handler, the callback affects exception
     * rendering for every subsequent request in the worker.
     *
     * @return $this
     */
    public function render(callable $using): static
    {
        $this->handler->renderable($using);

        return $this;
    }

    /**
     * Register a renderable callback.
     *
     * On the application's worker-shared handler, the callback affects exception
     * rendering for every subsequent request in the worker.
     *
     * @return $this
     */
    public function renderable(callable $renderUsing): static
    {
        $this->handler->renderable($renderUsing);

        return $this;
    }

    /**
     * Register a callback to prepare the final, rendered exception response.
     *
     * On the application's worker-shared handler, the callback replaces the
     * response callback for every subsequent request in the worker.
     *
     * @return $this
     */
    public function respond(callable $using): static
    {
        $this->handler->respondUsing($using);

        return $this;
    }

    /**
     * Register a callback before final exception response customization.
     *
     * On the application's worker-shared handler, the callback runs for every
     * subsequent exception response in the worker. Callback return values are ignored.
     *
     * @param callable(Response, Throwable, Request): mixed $callback
     * @return $this
     */
    public function beforeResponding(callable $callback): static
    {
        $this->handler->beforeResponding($callback);

        return $this;
    }

    /**
     * Specify the callback that should be used to throttle reportable exceptions.
     *
     * On the application's worker-shared handler, the callback affects exception
     * reporting for every subsequent request and job in the worker.
     *
     * @return $this
     */
    public function throttle(callable $throttleUsing): static
    {
        $this->handler->throttleUsing($throttleUsing);

        return $this;
    }

    /**
     * Register a new exception mapping.
     *
     * On the application's worker-shared handler, the mapping affects exception
     * reporting and rendering for every subsequent request and job in the worker.
     *
     * @return $this
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
     * On the application's worker-shared handler, the level affects how later
     * exceptions of that type are logged in the worker.
     *
     * @param class-string<Throwable> $type
     * @param LogLevel::* $level
     * @return $this
     */
    public function level(string $type, string $level): static
    {
        $this->handler->level($type, $level);

        return $this;
    }

    /**
     * Register a closure that should be used to build exception context data.
     *
     * On the application's worker-shared handler, the closure runs for every
     * subsequently logged exception in the worker.
     *
     * @return $this
     */
    public function context(Closure $contextCallback): static
    {
        $this->handler->buildContextUsing($contextCallback);

        return $this;
    }

    /**
     * Indicate that the given exception type should not be reported.
     *
     * On the application's worker-shared handler, the exception types affect
     * exception reporting for every subsequent request and job in the worker.
     *
     * @return $this
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
     * On the application's worker-shared handler, the exception types affect
     * every subsequently failed job in the worker.
     *
     * @return $this
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
     * On the application's worker-shared handler, the callback affects exception
     * reporting for every subsequent request and job in the worker.
     *
     * @param (Closure(Throwable): bool) $dontReportWhen
     * @return $this
     */
    public function dontReportWhen(Closure $dontReportWhen): static
    {
        $this->handler->dontReportWhen($dontReportWhen);

        return $this;
    }

    /**
     * Register a callback to determine if an exception should stop job retries.
     *
     * On the application's worker-shared handler, the callback affects job retry
     * decisions for every subsequent job in the worker.
     *
     * @template TException of Throwable
     *
     * @param Closure(TException): bool $dontRetryWhen
     * @return $this
     */
    public function dontRetryWhen(Closure $dontRetryWhen): static
    {
        $this->handler->dontRetryWhen($dontRetryWhen);

        return $this;
    }

    /**
     * Do not report duplicate exceptions.
     *
     * On the application's worker-shared handler, the setting applies to every
     * subsequent request and job in the worker.
     *
     * @return $this
     */
    public function dontReportDuplicates(): static
    {
        $this->handler->dontReportDuplicates();

        return $this;
    }

    /**
     * Indicate that the given attributes should never be flashed to the session on validation errors.
     *
     * On the application's worker-shared handler, the attributes are omitted from
     * every subsequent validation redirect in the worker.
     *
     * @return $this
     */
    public function dontFlash(array|string $attributes): static
    {
        $this->handler->dontFlash($attributes);

        return $this;
    }

    /**
     * Register the callable that determines if the exception handler response should be JSON.
     *
     * On the application's worker-shared handler, the callable replaces the JSON
     * check for every subsequent request in the worker.
     *
     * @return $this
     */
    public function shouldRenderJsonWhen(callable $callback): static
    {
        $this->handler->shouldRenderJsonWhen($callback);

        return $this;
    }

    /**
     * Indicate that the given exception class should not be ignored.
     *
     * On the application's worker-shared handler, the exception lists affect
     * exception reporting for every subsequent request and job in the worker.
     *
     * @param array<int, class-string<Throwable>>|class-string<Throwable> $class
     * @return $this
     */
    public function stopIgnoring(array|string $class): static
    {
        $this->handler->stopIgnoring($class);

        return $this;
    }

    /**
     * Set the truncation length for request exception messages.
     *
     * The global default persists for the worker lifetime across all
     * coroutines; per-request truncation settings take precedence.
     *
     * @return $this
     */
    #[BootOnly]
    public function truncateRequestExceptionsAt(int $length): static
    {
        RequestException::truncateAt($length);

        return $this;
    }

    /**
     * Disable truncation of request exception messages.
     *
     * The global default persists for the worker lifetime across all
     * coroutines; per-request truncation settings take precedence.
     *
     * @return $this
     */
    #[BootOnly]
    public function dontTruncateRequestExceptions(): static
    {
        RequestException::dontTruncate();

        return $this;
    }
}
