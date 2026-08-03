<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Http\WebSocketKernel;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WebSocketKernelTest extends TestCase
{
    public function testHandleExceptionRetainsTheOriginalExceptionWhenReportingFails(): void
    {
        $original = new RuntimeException('WebSocket request failed');
        $reportingFailure = new RuntimeException('Reporting failed');
        $app = new Application;

        $app->instance(StdoutLoggerInterface::class, m::mock(StdoutLoggerInterface::class));

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldReceive('report')->once()->with($original)->andThrow($reportingFailure);
        $handler->shouldNotReceive('render');
        $app->instance(ExceptionHandlerContract::class, $handler);

        RequestContext::set(Request::create('/'));

        $kernel = new ExposedWebSocketKernel($app);

        try {
            $kernel->handleThrowable($original);
            $this->fail('Expected exception reporting to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($reportingFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    public function testHandleExceptionRetainsTheOriginalExceptionWhenResolvingTheHandlerFails(): void
    {
        $original = new RuntimeException('WebSocket request failed');
        $resolutionFailure = new RuntimeException('Handler resolution failed');
        $app = new Application;

        $app->instance(StdoutLoggerInterface::class, m::mock(StdoutLoggerInterface::class));
        $app->bind(ExceptionHandlerContract::class, static fn () => throw $resolutionFailure);

        $kernel = new ExposedWebSocketKernel($app);

        try {
            $kernel->handleThrowable($original);
            $this->fail('Expected exception handler resolution to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($resolutionFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }
}

class ExposedWebSocketKernel extends WebSocketKernel
{
    /**
     * Handle a throwable through the WebSocket exception boundary.
     */
    public function handleThrowable(Throwable $throwable): Response
    {
        return $this->handleException($throwable);
    }
}
