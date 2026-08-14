<?php

declare(strict_types=1);

namespace Hypervel\Tests\Dispatcher;

use Closure;
use Hypervel\Dispatcher\ParsedMiddleware;
use Hypervel\Dispatcher\Pipeline;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class PipelineTest extends TestCase
{
    use HasMockedApplication;

    public function testHandleLaravelMiddleware()
    {
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('withAttribute')
            ->with('param', 'foo')
            ->once()
            ->andReturnSelf();

        $mockedResponse = m::mock(ResponseInterface::class);
        $closure = fn (ServerRequestInterface $request, Closure $next) => $mockedResponse;

        $response = (new Pipeline($this->getApplication()))
            ->send($request)
            ->through([LaravelMiddleware::class . ':foo', $closure])
            ->thenReturn();

        $this->assertSame($mockedResponse, $response);
    }

    public function testHandleHyperfMiddleware()
    {
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('withAttribute')
            ->with('param', 'foo')
            ->once()
            ->andReturnSelf();

        $mockedResponse = m::mock(ResponseInterface::class);
        $closure = fn (ServerRequestInterface $request, Closure $next) => $mockedResponse;

        $response = (new Pipeline($container = $this->getApplication()))
            ->send($request)
            ->through([HyperfMiddleware::class . ':foo', $closure])
            ->thenReturn();

        $this->assertSame($mockedResponse, $response);
    }

    public function testHandleParsedMiddleware()
    {
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('withAttribute')
            ->with('param', 'foo')
            ->once()
            ->andReturnSelf();
        $parsedMiddleware = new ParsedMiddleware(HyperfMiddleware::class . ':foo');

        $mockedResponse = m::mock(ResponseInterface::class);
        $closure = fn (ServerRequestInterface $request, Closure $next) => $mockedResponse;

        $response = (new Pipeline($container = $this->getApplication()))
            ->send($request)
            ->through([$parsedMiddleware, $closure])
            ->thenReturn();

        $this->assertSame($mockedResponse, $response);
    }

    public function testLaravelAndHyperfMiddleware()
    {
        $request = m::mock(ServerRequestInterface::class);
        $request->shouldReceive('withAttribute')
            ->with('param', 'foo')
            ->once()
            ->andReturnSelf();
        $request->shouldReceive('withAttribute')
            ->with('param', 'bar')
            ->once()
            ->andReturnSelf();

        $mockedResponse = m::mock(ResponseInterface::class);
        $closure = fn (ServerRequestInterface $request, Closure $next) => $mockedResponse;

        $response = (new Pipeline($container = $this->getApplication()))
            ->send($request)
            ->through([
                HyperfMiddleware::class . ':foo',
                LaravelMiddleware::class . ':bar',
                $closure,
            ])->thenReturn();

        $this->assertSame($mockedResponse, $response);
    }

    public function testExceptionsAreRethrownByDefault()
    {
        $request = m::mock(ServerRequestInterface::class);
        $exception = new RuntimeException('boom');

        $this->expectExceptionObject($exception);

        (new Pipeline($this->getApplication()))
            ->send($request)
            ->through([fn () => throw $exception])
            ->thenReturn();
    }

    public function testDestinationExceptionsAreRethrownByDefault()
    {
        $request = m::mock(ServerRequestInterface::class);
        $exception = new RuntimeException('boom');

        $this->expectExceptionObject($exception);

        (new Pipeline($this->getApplication()))
            ->send($request)
            ->through([])
            ->then(fn () => throw $exception);
    }

    public function testHandleExceptionHookCanConvertThrowablesToResponses()
    {
        $request = m::mock(ServerRequestInterface::class);
        $mockedResponse = m::mock(ResponseInterface::class);

        $response = (new HandlesExceptionPipeline($this->getApplication(), $mockedResponse))
            ->send($request)
            ->through([fn () => throw new RuntimeException('boom')])
            ->thenReturn();

        $this->assertSame($mockedResponse, $response);
    }

    public function testHandleExceptionHookSeesThrowablesFromOuterMiddleware()
    {
        $request = m::mock(ServerRequestInterface::class);
        $mockedResponse = m::mock(ResponseInterface::class);
        $mockedResponse->shouldReceive('getStatusCode')
            ->once()
            ->andReturn(404);

        $observed = null;
        $observer = function (ServerRequestInterface $request, Closure $next) use (&$observed) {
            $response = $next($request);
            $observed = $response->getStatusCode();

            return $response;
        };

        (new HandlesExceptionPipeline($this->getApplication(), $mockedResponse))
            ->send($request)
            ->through([$observer, fn () => throw new RuntimeException('boom')])
            ->thenReturn();

        $this->assertSame(404, $observed);
    }
}

class HandlesExceptionPipeline extends Pipeline
{
    public function __construct($container, protected ?ResponseInterface $rendered = null)
    {
        parent::__construct($container);
    }

    protected function handleException(mixed $passable, Throwable $e): mixed
    {
        return $this->handleCarry($this->rendered);
    }
}

class LaravelMiddleware
{
    public function handle(ServerRequestInterface $request, Closure $next, ?string $param = null): ResponseInterface
    {
        if ($param) {
            $request = $request->withAttribute('param', $param);
        }

        return $next($request);
    }
}

class HyperfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler, ?string $param = null): ResponseInterface
    {
        if ($param) {
            $request = $request->withAttribute('param', $param);
        }

        return $handler->handle($request);
    }
}
