<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Closure;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\Request;
use Hypervel\Support\Testing\Fakes\ExceptionHandlerFake;
use Hypervel\Support\Traits\ReflectsClosures;
use Hypervel\Testing\Assert;
use Hypervel\Validation\ValidationException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

trait InteractsWithExceptionHandling
{
    use ReflectsClosures;

    /**
     * The original exception handler.
     */
    protected ?ExceptionHandler $originalExceptionHandler = null;

    /**
     * Restore exception handling.
     */
    protected function withExceptionHandling(): static
    {
        if ($this->originalExceptionHandler) {
            $currentExceptionHandler = app(ExceptionHandler::class);

            $currentExceptionHandler instanceof ExceptionHandlerFake
                ? $currentExceptionHandler->setHandler($this->originalExceptionHandler)
                : $this->app->instance(ExceptionHandler::class, $this->originalExceptionHandler);
        }

        return $this;
    }

    /**
     * Only handle the given exceptions via the exception handler.
     *
     * @param list<class-string<Throwable>> $exceptions
     */
    protected function handleExceptions(array $exceptions): static
    {
        return $this->withoutExceptionHandling($exceptions);
    }

    /**
     * Only handle validation exceptions via the exception handler.
     */
    protected function handleValidationExceptions(): static
    {
        return $this->handleExceptions([ValidationException::class]);
    }

    /**
     * Disable exception handling for the test.
     *
     * @param list<class-string<Throwable>> $except
     */
    protected function withoutExceptionHandling(array $except = []): static
    {
        if ($this->originalExceptionHandler === null) {
            $currentExceptionHandler = app(ExceptionHandler::class);

            $this->originalExceptionHandler = $currentExceptionHandler instanceof ExceptionHandlerFake
                ? $currentExceptionHandler->handler()
                : $currentExceptionHandler;
        }

        $exceptionHandler = new class($this->originalExceptionHandler, $except) implements ExceptionHandler, WithoutExceptionHandlingHandler {
            /**
             * @param list<class-string<Throwable>> $except
             */
            public function __construct(
                protected ExceptionHandler $originalHandler,
                protected array $except = [],
            ) {
            }

            /**
             * Report or log an exception.
             */
            public function report(Throwable $e): void
            {
            }

            /**
             * Determine if the exception should be reported.
             */
            public function shouldReport(Throwable $e): bool
            {
                return false;
            }

            /**
             * Render an exception into an HTTP response.
             *
             * @throws Throwable
             */
            public function render(Request $request, Throwable $e): Response
            {
                foreach ($this->except as $class) {
                    if ($e instanceof $class) {
                        return $this->originalHandler->render($request, $e);
                    }
                }

                if ($e instanceof NotFoundHttpException) {
                    throw new NotFoundHttpException(
                        "{$request->method()} {$request->url()}",
                        $e,
                        is_int($e->getCode()) ? $e->getCode() : 0
                    );
                }

                throw $e;
            }

            /**
             * Render an exception to the console.
             */
            public function renderForConsole(OutputInterface $output, Throwable $e): void
            {
                (new ConsoleApplication)->renderThrowable($e, $output);
            }

            /**
             * Register a callback to be called after an HTTP error response is rendered.
             */
            public function afterResponse(callable $callback): void
            {
            }
        };

        $currentExceptionHandler = app(ExceptionHandler::class);

        $currentExceptionHandler instanceof ExceptionHandlerFake
            ? $currentExceptionHandler->setHandler($exceptionHandler)
            : $this->app->instance(ExceptionHandler::class, $exceptionHandler);

        return $this;
    }

    /**
     * Assert that the given callback throws an exception with the given message when invoked.
     *
     * @param class-string<Throwable>|Closure(Throwable): bool $expectedClass
     */
    protected function assertThrows(Closure $test, string|Closure $expectedClass = Throwable::class, ?string $expectedMessage = null): static
    {
        [$expectedClass, $expectedClassCallback] = $expectedClass instanceof Closure
            ? [$this->firstClosureParameterType($expectedClass), $expectedClass]
            : [$expectedClass, null];

        try {
            $test();

            $thrown = false;
        } catch (Throwable $exception) {
            $thrown = $exception instanceof $expectedClass && ($expectedClassCallback === null || $expectedClassCallback($exception));

            $actualMessage = $exception->getMessage();
        }

        Assert::assertTrue(
            $thrown,
            sprintf('Failed asserting that exception of type "%s" was thrown.', $expectedClass)
        );

        if (isset($expectedMessage)) {
            if (! isset($actualMessage)) {
                Assert::fail(
                    sprintf(
                        'Failed asserting that exception of type "%s" with message "%s" was thrown.',
                        $expectedClass,
                        $expectedMessage
                    )
                );
            } else {
                Assert::assertStringContainsString($expectedMessage, $actualMessage);
            }
        }

        return $this;
    }

    /**
     * Assert that the given callback does not throw an exception.
     */
    protected function assertDoesntThrow(Closure $test): static
    {
        try {
            $test();

            $thrown = false;
        } catch (Throwable $exception) {
            $thrown = true;

            $exceptionClass = get_class($exception);
            $exceptionMessage = $exception->getMessage();
        }

        Assert::assertTrue(
            ! $thrown,
            sprintf('Unexpected exception of type %s with message %s was thrown.', $exceptionClass ?? null, $exceptionMessage ?? null)
        );

        return $this;
    }
}
