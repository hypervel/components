<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

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
    public function __construct(
        private readonly ExceptionHandler $handler,
    ) {
    }

    public function report(Throwable $e): void
    {
        Integration::captureUnhandledException($e);

        $this->handler->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->handler->shouldReport($e);
    }

    public function render(Request $request, Throwable $e): Response
    {
        return $this->handler->render($request, $e);
    }

    public function renderForConsole(OutputInterface $output, Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }

    public function afterResponse(callable $callback): void
    {
        $this->handler->afterResponse($callback);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->handler->{$name}(...$arguments);
    }
}
