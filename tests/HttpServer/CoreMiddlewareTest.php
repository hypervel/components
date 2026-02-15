<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use FastRoute\Dispatcher;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use Hyperf\Contract\NormalizerInterface;
use Hyperf\Di\ClosureDefinitionCollector;
use Hyperf\Di\ClosureDefinitionCollectorInterface;
use Hyperf\Di\MethodDefinitionCollector;
use Hyperf\Di\MethodDefinitionCollectorInterface;
use Hypervel\HttpMessage\Exceptions\ServerErrorHttpException;
use Hypervel\HttpMessage\Server\Request;
use Hypervel\HttpMessage\Stream\SwooleStream;
use Hypervel\HttpMessage\Uri\Uri;
use Hyperf\Serializer\SimpleNormalizer;
use Hypervel\Context\Context;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Dispatcher\HttpRequestHandler;
use Hypervel\Dispatcher\Pipeline;
use Hypervel\HttpServer\CoreMiddleware;
use Hypervel\HttpServer\Router\Dispatched;
use Hypervel\HttpServer\Router\DispatcherFactory;
use Hypervel\HttpServer\Router\Handler;
use Hypervel\Tests\HttpServer\Stub\CoreMiddlewareStub;
use Hypervel\Tests\HttpServer\Stub\DemoController;
use Hypervel\Tests\HttpServer\Stub\FooController;
use Hypervel\Tests\HttpServer\Stub\SetHeaderMiddleware;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use Hypervel\Contracts\Http\ResponsePlusInterface;

/**
 * @internal
 * @coversNothing
 */
class CoreMiddlewareTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 2));
        }
    }

    public function testParseParameters()
    {
        $middleware = new CoreMiddlewareStub($container = $this->getContainer(), 'http');
        $id = rand(0, 99999);

        $params = $middleware->parseMethodParameters(DemoController::class, 'index', ['id' => $id]);

        $this->assertSame([$id, 'Hyperf', []], $params);
    }

    public function testTransferToResponse()
    {
        $middleware = new CoreMiddlewareStub($container = $this->getContainer(), 'http');
        $reflectionMethod = new ReflectionMethod(CoreMiddleware::class, 'transferToResponse');
        $request = m::mock(ServerRequestInterface::class);
        /** @var ResponseInterface $response */

        // String
        $response = $reflectionMethod->invoke($middleware, $body = 'foo', $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame($body, (string) $response->getBody());
        $this->assertSame('text/plain', $response->getHeaderLine('content-type'));

        // Array
        $response = $reflectionMethod->invoke($middleware, $body = ['foo' => 'bar'], $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(json_encode($body), (string) $response->getBody());
        $this->assertSame('application/json', $response->getHeaderLine('content-type'));

        // Arrayable
        $response = $reflectionMethod->invoke($middleware, new class implements Arrayable {
            public function toArray(): array
            {
                return ['foo' => 'bar'];
            }
        }, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(json_encode(['foo' => 'bar']), (string) $response->getBody());
        $this->assertSame('application/json', $response->getHeaderLine('content-type'));

        // Jsonable
        $response = $reflectionMethod->invoke($middleware, new class implements Stringable, Jsonable {
            public function __toString(): string
            {
                return json_encode(['foo' => 'bar'], JSON_UNESCAPED_UNICODE);
            }
        }, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(json_encode(['foo' => 'bar']), (string) $response->getBody());
        $this->assertSame('application/json', $response->getHeaderLine('content-type'));

        // __toString
        $response = $reflectionMethod->invoke($middleware, new class implements Stringable {
            public function __toString(): string
            {
                return 'This is a string';
            }
        }, $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame('This is a string', (string) $response->getBody());
        $this->assertSame('text/plain', $response->getHeaderLine('content-type'));

        // Json encode failed
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type is not supported');
        $response = $reflectionMethod->invoke($middleware, ['id' => fopen(__FILE__, 'r')], $request);
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testDispatch()
    {
        $container = $this->getContainer();

        $router = $container->make(DispatcherFactory::class)->getRouter('http');
        $router->addRoute('GET', '/user', 'UserController::index');
        $router->addRoute('GET', '/user/{id:\d+}', 'UserController::info');

        $middleware = new CoreMiddleware($container, 'http');

        $request = new Request('GET', new Uri('/user'));
        $request = $middleware->dispatch($request);
        $dispatched = $request->getAttribute(Dispatched::class);
        $this->assertInstanceOf(Request::class, $request);
        $this->assertInstanceOf(Dispatched::class, $dispatched);
        $this->assertInstanceOf(Handler::class, $dispatched->handler);
        $this->assertSame($dispatched, $request->getAttribute(Dispatched::class));
        $this->assertSame('/user', $dispatched->handler->route);
        $this->assertSame('UserController::index', $dispatched->handler->callback);
        $this->assertTrue($dispatched->isFound());

        $request = new Request('GET', new Uri('/user/123'));
        $request = $middleware->dispatch($request);
        $dispatched = $request->getAttribute(Dispatched::class);
        $this->assertInstanceOf(Request::class, $request);
        $this->assertInstanceOf(Dispatched::class, $dispatched);
        $this->assertInstanceOf(Handler::class, $dispatched->handler);
        $this->assertSame($dispatched, $request->getAttribute(Dispatched::class));
        $this->assertSame('/user/{id:\d+}', $dispatched->handler->route);
        $this->assertSame('UserController::info', $dispatched->handler->callback);
        $this->assertTrue($dispatched->isFound());

        $request = new Request('GET', new Uri('/users'));
        $request = $middleware->dispatch($request);
        $dispatched = $request->getAttribute(Dispatched::class);
        $this->assertInstanceOf(Request::class, $request);
        $this->assertInstanceOf(Dispatched::class, $dispatched);
        $this->assertSame($dispatched, $request->getAttribute(Dispatched::class));
        $this->assertFalse($dispatched->isFound());
    }

    public function testProcess()
    {
        $container = $this->getContainer();
        $id = uniqid();
        $container->shouldReceive('make')->with(SetHeaderMiddleware::class)->andReturn(new SetHeaderMiddleware($id));
        $container->shouldReceive('make')->with(Pipeline::class)->andReturnUsing(function () use ($container) {
            return new Pipeline($container);
        });

        $router = $container->make(DispatcherFactory::class)->getRouter('http');
        $router->addRoute('GET', '/request', function () {
            return Context::get(ServerRequestInterface::class)->getHeaders();
        });

        $response = m::mock(ResponsePlusInterface::class);
        $response->shouldReceive('addHeader')->andReturn($response);
        $response->shouldReceive('setBody')->with(m::any())->andReturnUsing(function ($stream) use ($response, $id) {
            $this->assertInstanceOf(SwooleStream::class, $stream);
            /* @var SwooleStream $stream */
            $this->assertSame(json_encode(['DEBUG' => [$id]]), (string) $stream);
            return $response;
        });
        $request = new Request('GET', new Uri('/request'));
        ResponseContext::set($response);
        Context::set(ServerRequestInterface::class, $request);

        $middleware = new CoreMiddleware($container, 'http');
        $request = $middleware->dispatch($request);
        $handler = new HttpRequestHandler([SetHeaderMiddleware::class], $middleware, $container);
        $response = $handler->handle($request);
    }

    public function testHandleFound()
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(DemoController::class)->andReturn(new DemoController());
        $middleware = new CoreMiddleware($container, 'http');
        $ref = new ReflectionClass($middleware);
        $method = $ref->getMethod('handleFound');

        $handler = new Handler([DemoController::class, 'demo'], '/');
        $dispatched = new Dispatched([Dispatcher::FOUND, $handler, []]);
        $res = $method->invokeArgs($middleware, [$dispatched, m::mock(ServerRequestInterface::class)]);
        $this->assertSame('Hello World.', $res);
    }

    public function testHandleFoundWithInvokable()
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(DemoController::class)->andReturn(new DemoController());
        $middleware = new CoreMiddleware($container, 'http');
        $ref = new ReflectionClass($middleware);
        $method = $ref->getMethod('handleFound');

        $handler = new Handler(DemoController::class, '/');
        $dispatched = new Dispatched([Dispatcher::FOUND, $handler, []]);
        $res = $method->invokeArgs($middleware, [$dispatched, m::mock(ServerRequestInterface::class)]);
        $this->assertSame('Action for an invokable controller.', $res);
    }

    public function testHandleFoundWithNamespace()
    {
        $container = $this->getContainer();
        $container->shouldReceive('make')->with(DemoController::class)->andReturn(new FooController());
        $middleware = new CoreMiddleware($container, 'http');
        $ref = new ReflectionClass($middleware);
        $method = $ref->getMethod('handleFound');

        $this->expectException(ServerErrorHttpException::class);
        $this->expectExceptionMessage('Method of class does not exist.');
        $handler = new Handler([DemoController::class, 'demo'], '/');
        $dispatched = new Dispatched([Dispatcher::FOUND, $handler, []]);
        $method->invokeArgs($middleware, [$dispatched, m::mock(ServerRequestInterface::class)]);
    }

    protected function getContainer()
    {
        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(DispatcherFactory::class)->andReturn(new DispatcherFactory());
        $container->shouldReceive('make')->with(MethodDefinitionCollectorInterface::class)
            ->andReturn(new MethodDefinitionCollector());
        $container->shouldReceive('has')->with(ClosureDefinitionCollectorInterface::class)
            ->andReturn(false);
        $container->shouldReceive('make')->with(ClosureDefinitionCollectorInterface::class)
            ->andReturn(new ClosureDefinitionCollector());
        $container->shouldReceive('make')->with(NormalizerInterface::class)
            ->andReturn(new SimpleNormalizer());
        return $container;
    }
}
