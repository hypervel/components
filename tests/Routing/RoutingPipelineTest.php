<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing;

use DomainException;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Routing\Pipeline;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use LogicException;
use RuntimeException;
use Throwable;

class RoutingPipelineTest extends TestCase
{
    use VerifiesDoubles;

    public function testReportingFailureRetainsTheOriginalException(): void
    {
        $original = new RuntimeException('original');
        $failure = new LogicException('reporting failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($original)->throws($failure);
        $handler->expects('render')->never();

        $caught = $this->handleAndCatch($handler, $original);

        $this->assertSame($failure, $caught);
        $this->assertSame($original, $caught->getPrevious());
    }

    public function testRenderingFailureRetainsTheOriginalException(): void
    {
        $original = new RuntimeException('original');
        $failure = new LogicException('rendering failed');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($original);
        $handler->expects('render')->with(Argument::type(Request::class), $original)->throws($failure);

        $caught = $this->handleAndCatch($handler, $original);

        $this->assertSame($failure, $caught);
        $this->assertSame($original, $caught->getPrevious());
    }

    public function testHandlingFailureRetainsItsExistingChainBeforeTheOriginalException(): void
    {
        $original = new RuntimeException('original');
        $existing = new DomainException('existing');
        $failure = new LogicException('reporting failed', previous: $existing);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($original)->throws($failure);

        $caught = $this->handleAndCatch($handler, $original);

        $this->assertSame($failure, $caught);
        $this->assertSame($existing, $caught->getPrevious());
        $this->assertSame($original, $caught->getPrevious()?->getPrevious());
    }

    public function testRethrowingTheOriginalExceptionDoesNotCreateASelfReferentialChain(): void
    {
        $original = new RuntimeException('original');
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($original)->throws($original);

        $caught = $this->handleAndCatch($handler, $original);

        $this->assertSame($original, $caught);
        $this->assertNull($caught->getPrevious());
    }

    public function testSuccessfulHandlingReturnsTheRenderedResponse(): void
    {
        $original = new RuntimeException('original');
        $response = new Response('handled', 500);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($original);
        $handler->expects('render')->with(Argument::type(Request::class), $original)->returns($response);

        $this->assertSame($response, $this->pipeline($handler)->handleThrowable(new Request, $original));
    }

    /**
     * Handle an exception and return the handling failure.
     */
    private function handleAndCatch(ExceptionHandler $handler, Throwable $original): Throwable
    {
        try {
            $this->pipeline($handler)->handleThrowable(new Request, $original);
        } catch (Throwable $failure) {
            return $failure;
        }

        $this->fail('Expected exception handling to fail.');
    }

    /**
     * Create a routing pipeline with the given exception handler.
     */
    private function pipeline(ExceptionHandler $handler): ExposedRoutingPipeline
    {
        $container = new Container;
        $container->instance(ExceptionHandler::class, $handler);

        return new ExposedRoutingPipeline($container);
    }
}

class ExposedRoutingPipeline extends Pipeline
{
    /**
     * Handle the given exception through the routing boundary.
     */
    public function handleThrowable(Request $request, Throwable $throwable): mixed
    {
        return $this->handleException($request, $throwable);
    }
}
