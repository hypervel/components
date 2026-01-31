<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Closure;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Support\Traits\ReflectsClosures;
use PHPUnit\Framework\Assert as PHPUnit;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * @mixin \Hypervel\Foundation\Exceptions\Handler
 */
class ExceptionHandlerFake implements ExceptionHandler, Fake
{
    use ForwardsCalls;
    use ReflectsClosures;

    /**
     * All of the exceptions that have been reported.
     *
     * @var list<Throwable>
     */
    protected array $reported = [];

    /**
     * If the fake should throw exceptions when they are reported.
     */
    protected bool $throwOnReport = false;

    /**
     * Create a new exception handler fake.
     *
     * @param list<class-string<Throwable>> $exceptions
     */
    public function __construct(
        protected ExceptionHandler $handler,
        protected array $exceptions = [],
    ) {
    }

    /**
     * Get the underlying handler implementation.
     */
    public function handler(): ExceptionHandler
    {
        return $this->handler;
    }

    /**
     * Assert if an exception of the given type has been reported.
     *
     * @param (Closure(Throwable): bool)|class-string<Throwable> $exception
     */
    public function assertReported(Closure|string $exception): void
    {
        $message = sprintf(
            'The expected [%s] exception was not reported.',
            is_string($exception) ? $exception : $this->firstClosureParameterType($exception)
        );

        if (is_string($exception)) {
            PHPUnit::assertTrue(
                in_array($exception, array_map(get_class(...), $this->reported), true),
                $message,
            );

            return;
        }

        PHPUnit::assertTrue(
            (new Collection($this->reported))->contains(
                fn (Throwable $e) => $this->firstClosureParameterType($exception) === get_class($e)
                    && $exception($e) === true,
            ),
            $message,
        );
    }

    /**
     * Assert the number of exceptions that have been reported.
     */
    public function assertReportedCount(int $count): void
    {
        $total = count($this->reported);

        PHPUnit::assertSame(
            $count,
            $total,
            "The total number of exceptions reported was {$total} instead of {$count}."
        );
    }

    /**
     * Assert if an exception of the given type has not been reported.
     *
     * @param (Closure(Throwable): bool)|class-string<Throwable> $exception
     */
    public function assertNotReported(Closure|string $exception): void
    {
        try {
            $this->assertReported($exception);
        } catch (ExpectationFailedException) {
            return;
        }

        throw new ExpectationFailedException(sprintf(
            'The expected [%s] exception was reported.',
            is_string($exception) ? $exception : $this->firstClosureParameterType($exception)
        ));
    }

    /**
     * Assert nothing has been reported.
     */
    public function assertNothingReported(): void
    {
        PHPUnit::assertEmpty(
            $this->reported,
            sprintf(
                'The following exceptions were reported: %s.',
                implode(', ', array_map(get_class(...), $this->reported)),
            ),
        );
    }

    /**
     * Report or log an exception.
     */
    public function report(Throwable $e): void
    {
        if (! $this->isFakedException($e)) {
            $this->handler->report($e);

            return;
        }

        if (! $this->shouldReport($e)) {
            return;
        }

        $this->reported[] = $e;

        if ($this->throwOnReport) {
            throw $e;
        }
    }

    /**
     * Determine if the given exception is faked.
     */
    protected function isFakedException(Throwable $e): bool
    {
        return count($this->exceptions) === 0 || in_array(get_class($e), $this->exceptions, true);
    }

    /**
     * Determine if the exception should be reported.
     */
    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render(Request $request, Throwable $e): ResponseInterface
    {
        return $this->handler->render($request, $e);
    }

    /**
     * Register a callback to be called after an HTTP error response is rendered.
     */
    public function afterResponse(callable $callback): void
    {
        $this->handler->afterResponse($callback);
    }

    /**
     * Throw exceptions when they are reported.
     */
    public function throwOnReport(): static
    {
        $this->throwOnReport = true;

        return $this;
    }

    /**
     * Throw the first reported exception.
     *
     * @throws Throwable
     */
    public function throwFirstReported(): static
    {
        foreach ($this->reported as $e) {
            throw $e;
        }

        return $this;
    }

    /**
     * Get the exceptions that have been reported.
     *
     * @return list<Throwable>
     */
    public function reported(): array
    {
        return $this->reported;
    }

    /**
     * Set the "original" handler that should be used by the fake.
     */
    public function setHandler(ExceptionHandler $handler): static
    {
        $this->handler = $handler;

        return $this;
    }

    /**
     * Handle dynamic method calls to the handler.
     *
     * @param array<string, mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->handler, $method, $parameters);
    }
}
