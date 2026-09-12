<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry\Fixtures;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\Request;
use Hypervel\Sentry\Integration;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Proxy class that injects Sentry exception capture into the test exception handler.
 */
class TestCaseExceptionHandler implements ExceptionHandler
{
    /**
     * Create the exception handler proxy.
     */
    public function __construct(
        private readonly ExceptionHandler $handler,
    ) {
    }

    /**
     * Report or log an exception.
     *
     * @throws Throwable
     */
    public function report(Throwable $e): void
    {
        Integration::captureUnhandledException($e);

        $this->handler->report($e);
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
     *
     * @throws Throwable
     */
    public function render(Request $request, Throwable $e): Response
    {
        return $this->handler->render($request, $e);
    }

    /**
     * Render an exception to the console.
     */
    public function renderForConsole(OutputInterface $output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }

    /**
     * Register a callback to run after an HTTP error response is rendered.
     */
    public function afterResponse(callable $callback): void
    {
        $this->handler->afterResponse($callback);
    }

    /**
     * Forward calls to the wrapped handler.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->handler->{$name}(...$arguments);
    }
}
