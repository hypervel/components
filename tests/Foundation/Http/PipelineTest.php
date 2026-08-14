<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Http;

use Closure;
use DomainException;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\HttpServer\Router\Dispatched;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Foundation\Http\Pipeline;
use Hypervel\Http\DispatchedRoute;
use Hypervel\Http\Request;
use Hypervel\HttpMessage\Exceptions\NotFoundHttpException;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class PipelineTest extends TestCase
{
    use HasMockedApplication;

    public function testReportingFailureRetainsTheOriginalException(): void
    {
        $original = new RuntimeException('original');
        $failure = new LogicException('reporting failed');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($original)->andThrow($failure);
        $handler->shouldNotReceive('render');

        $caught = $this->handleAndCatch($handler, $original);

        $this->assertSame($failure, $caught);
        $this->assertSame($original, $caught->getPrevious());
    }

    public function testRenderingFailureRetainsTheOriginalException(): void
    {
        $original = new RuntimeException('original');
        $failure = new LogicException('rendering failed');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($original);
        $handler->expects('render')->with(m::type(Request::class), $original)->andThrow($failure);

        $caught = $this->handleAndCatch($handler, $original);

        $this->assertSame($failure, $caught);
        $this->assertSame($original, $caught->getPrevious());
    }

    public function testHandlingFailureRetainsItsExistingChainBeforeTheOriginalException(): void
    {
        $original = new RuntimeException('original');
        $existing = new DomainException('existing');
        $failure = new LogicException('reporting failed', previous: $existing);
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($original)->andThrow($failure);

        $caught = $this->handleAndCatch($handler, $original);

        $this->assertSame($failure, $caught);
        $this->assertSame($existing, $caught->getPrevious());
        $this->assertSame($original, $caught->getPrevious()?->getPrevious());
    }

    public function testRethrowingTheOriginalExceptionDoesNotCreateASelfReferentialChain(): void
    {
        $original = new RuntimeException('original');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($original)->andThrow($original);

        $caught = $this->handleAndCatch($handler, $original);

        $this->assertSame($original, $caught);
        $this->assertNull($caught->getPrevious());
    }

    public function testSuccessfulHandlingReturnsTheRenderedResponse(): void
    {
        $original = new RuntimeException('original');
        $response = (new Psr7Response())->withStatus(500);
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($original);
        $handler->expects('render')->with(m::type(Request::class), $original)->andReturn($response);

        $this->assertSame(
            $response,
            $this->pipeline($handler)->handleThrowable($this->httpRequest(), $original)
        );
    }

    public function testNonHttpPassablesAreRethrownUntouched(): void
    {
        $original = new RuntimeException('original');
        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->shouldNotReceive('report');
        $handler->shouldNotReceive('render');

        // The WebSocket server shares this pipeline but attaches Hyperf's plain
        // Dispatched, so its kernel must keep catching the throwable itself.
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')
            ->with(Dispatched::class)
            ->andReturn(m::mock(Dispatched::class));

        try {
            $this->pipeline($handler)->handleThrowable($request, $original);
        } catch (Throwable $caught) {
            $this->assertSame($original, $caught);

            return;
        }

        $this->fail('Expected the exception to be rethrown.');
    }

    public function testMiddlewareSeesTheStatusTheClientReceives(): void
    {
        $exception = new ModelNotFoundException();
        $rendered = (new Psr7Response())->withStatus(404);

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($exception);
        $handler->expects('render')->with(m::type(Request::class), $exception)->andReturn($rendered);

        $observed = null;
        $observer = function (ServerRequestInterface $request, Closure $next) use (&$observed) {
            $response = $next($request);
            $observed = $response->getStatusCode();

            return $response;
        };

        $response = $this->pipeline($handler)
            ->send($this->httpRequest())
            ->through([$observer, fn () => throw $exception])
            ->thenReturn();

        $this->assertSame(404, $observed);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testExceptionsFromTheDestinationAreAlsoHandled(): void
    {
        $exception = new NotFoundHttpException();
        $rendered = (new Psr7Response())->withStatus(404);

        $handler = m::mock(ExceptionHandlerContract::class);
        $handler->expects('report')->with($exception);
        $handler->expects('render')->with(m::type(Request::class), $exception)->andReturn($rendered);

        $response = $this->pipeline($handler)
            ->send($this->httpRequest())
            ->through([])
            ->then(fn () => throw $exception);

        $this->assertSame($rendered, $response);
    }

    /**
     * Handle an exception and return the handling failure.
     */
    private function handleAndCatch(ExceptionHandlerContract $handler, Throwable $original): Throwable
    {
        try {
            $this->pipeline($handler)->handleThrowable($this->httpRequest(), $original);
        } catch (Throwable $failure) {
            return $failure;
        }

        $this->fail('Expected exception handling to fail.');
    }

    /**
     * Create a foundation pipeline with the given exception handler.
     */
    private function pipeline(ExceptionHandlerContract $handler): ExposedPipeline
    {
        return new ExposedPipeline($this->getApplication([
            ExceptionHandlerContract::class => fn () => $handler,
            Request::class => fn () => m::mock(Request::class),
        ]));
    }

    /**
     * Create a request carrying the marker the HTTP core middleware attaches.
     */
    private function httpRequest(): ServerRequestInterface
    {
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('getAttribute')
            ->with(Dispatched::class)
            ->andReturn(m::mock(DispatchedRoute::class));

        return $request;
    }
}

class ExposedPipeline extends Pipeline
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);
    }

    /**
     * Handle the given exception through the pipeline boundary.
     */
    public function handleThrowable(ServerRequestInterface $request, Throwable $throwable): mixed
    {
        return $this->handleException($request, $throwable);
    }

    public function thenReturn(): ResponseInterface
    {
        return parent::thenReturn();
    }
}
