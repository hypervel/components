<?php

declare(strict_types=1);

namespace Hypervel\Tests\Dispatcher;

use Hyperf\HttpMessage\Server\Response;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Dispatcher\HttpDispatcher;
use Hypervel\Dispatcher\Pipeline;
use Hypervel\Tests\Dispatcher\Stub\CoreMiddleware;
use Hypervel\Tests\Dispatcher\Stub\Test2Middleware;
use Hypervel\Tests\Dispatcher\Stub\TestMiddleware;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal
 * @coversNothing
 */
class HttpDispatcherTest extends TestCase
{
    public function testDispatch()
    {
        $middlewares = [
            TestMiddleware::class,
        ];
        $container = $this->getContainer();
        $request = Context::get(ServerRequestInterface::class);
        $coreHandler = $container->make(CoreMiddleware::class);
        $dispatcher = new HttpDispatcher($container);
        $this->assertInstanceOf(HttpDispatcher::class, $dispatcher);
        $response = $dispatcher->dispatch($request, $middlewares, $coreHandler);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('Hyperf', $response->getHeaderLine('Server'));
        $this->assertSame('Hyperf', $response->getHeaderLine('Test'));
    }

    public function testRepeatedMiddleware()
    {
        $middlewares = [
            TestMiddleware::class,
            TestMiddleware::class,
        ];
        $container = $this->getContainer();
        $request = Context::get(ServerRequestInterface::class);
        $coreHandler = $container->make(CoreMiddleware::class);
        $dispatcher = new HttpDispatcher($container);
        $this->assertInstanceOf(HttpDispatcher::class, $dispatcher);
        $response = $dispatcher->dispatch($request, $middlewares, $coreHandler);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('Hyperf', $response->getHeaderLine('Server'));
        $this->assertSame('Hyperf, Hyperf', $response->getHeaderLine('Test'));
    }

    public function testIntervalRepeatedMiddleware()
    {
        $middlewares = [
            TestMiddleware::class,
            3 => Test2Middleware::class,
            TestMiddleware::class,
        ];
        $container = $this->getContainer();
        $request = Context::get(ServerRequestInterface::class);
        $coreHandler = $container->make(CoreMiddleware::class);
        $dispatcher = new HttpDispatcher($container);
        $this->assertInstanceOf(HttpDispatcher::class, $dispatcher);
        $response = $dispatcher->dispatch($request, $middlewares, $coreHandler);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('Hyperf', $response->getHeaderLine('Server'));
        $this->assertSame('Hyperf, Hyperf2, Hyperf', $response->getHeaderLine('Test'));
    }

    protected function getContainer(): ContainerContract
    {
        $container = m::mock(ContainerContract::class);

        $container->shouldReceive('make')->with(CoreMiddleware::class)->andReturn(new CoreMiddleware());
        $container->shouldReceive('make')->with(Pipeline::class)
            ->andReturnUsing(fn () => new Pipeline($container));
        $container->shouldReceive('make')->with(TestMiddleware::class)->andReturn(new TestMiddleware());
        $container->shouldReceive('make')->with(Test2Middleware::class)->andReturn(new Test2Middleware());

        $request = m::mock(ServerRequestInterface::class);
        $response = new Response();
        Context::set(ServerRequestInterface::class, $request);
        Context::set(ResponseInterface::class, $response);

        return $container;
    }
}
