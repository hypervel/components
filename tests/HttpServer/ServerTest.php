<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Http\Request;
use Hypervel\Http\Response as HypervelResponse;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\HttpServer\Server;
use Hypervel\Routing\Router;
use Hypervel\Server\Option;
use Hypervel\Server\ServerFactory;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 * @coversNothing
 */
class ServerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        CoordinatorManager::clear(Constants::WORKER_START);
    }

    public function testInitCoreMiddlewareResolvesKernelAndBootstraps()
    {
        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('bootstrap')->once();

        $router = m::mock(Router::class);
        $router->shouldReceive('compileAndWarm')->once();

        $serverFactory = m::mock(ServerFactory::class);
        $serverFactory->shouldReceive('getConfig')->andReturn(null);

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(false);
        $container->shouldReceive('make')->with(KernelContract::class)->andReturn($kernel);
        $container->shouldReceive('make')->with(Router::class)->andReturn($router);
        $container->shouldReceive('make')->with(ServerFactory::class)->andReturn($serverFactory);

        $server = new Server($container);
        $server->initCoreMiddleware('http');

        $this->assertSame('http', $server->getServerName());
    }

    public function testOnRequestDelegatestoKernelAndSendsResponse()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->with(m::type(Request::class))
            ->andReturn(new Response('Hello World', 200));
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setOption($server, Option::make(['enable_request_lifecycle' => false]));

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200);
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->once()->with('Hello World');

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testOnRequestSetsRequestAndResponseInContext()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $capturedRequest = null;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (Request $request) use (&$capturedRequest) {
                // Inside the kernel, RequestContext should have the request
                $capturedRequest = RequestContext::get();
                // ResponseContext should also be set
                $this->assertInstanceOf(HypervelResponse::class, ResponseContext::get());
                return new Response('OK');
            });
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setOption($server, Option::make(['enable_request_lifecycle' => false]));

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->withAnyArgs();
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->withAnyArgs();

        $server->onRequest($swooleRequest, $swooleResponse);

        $this->assertInstanceOf(Request::class, $capturedRequest);
    }

    public function testOnRequestReturns500OnKernelException()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Fatal error'));
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setOption($server, Option::make(['enable_request_lifecycle' => false]));

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(500);
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->once()->with('Internal Server Error');

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testOnRequestSuppressesBodyForHeadRequests()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andReturn(new Response('This should not be sent', 200));
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setOption($server, Option::make(['enable_request_lifecycle' => false]));

        $swooleRequest = $this->createSwooleRequest(method: 'head');
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200);
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        // end() with no args — body suppressed for HEAD
        $swooleResponse->shouldReceive('end')->once()->withNoArgs();

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testOnRequestDispatchesLifecycleEventsWhenEnabled()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $dispatchedEvents = [];

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->andReturn(new Response('OK'));
        $kernel->shouldReceive('terminate');

        $eventDispatcher = m::mock(EventDispatcherContract::class);
        $eventDispatcher->shouldReceive('dispatch')
            ->andReturnUsing(function ($event) use (&$dispatchedEvents) {
                $dispatchedEvents[] = get_class($event);
                return $event;
            });

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(EventDispatcherContract::class)->andReturn($eventDispatcher);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setOption($server, Option::make(['enable_request_lifecycle' => true]));
        $this->setServerName($server, 'http');

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->withAnyArgs();
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->withAnyArgs();

        $server->onRequest($swooleRequest, $swooleResponse);

        // RequestReceived and RequestHandled should be dispatched synchronously.
        // RequestTerminated is deferred, so it may not be in the list yet.
        $this->assertContains(RequestReceived::class, $dispatchedEvents);
        $this->assertContains(RequestHandled::class, $dispatchedEvents);
    }

    public function testOnRequestDoesNotDispatchLifecycleEventsWhenDisabled()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $eventDispatcher = m::mock(EventDispatcherContract::class);
        $eventDispatcher->shouldNotReceive('dispatch');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->andReturn(new Response('OK'));
        $kernel->shouldReceive('terminate');

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(EventDispatcherContract::class)->andReturn($eventDispatcher);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setOption($server, Option::make(['enable_request_lifecycle' => false]));

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->withAnyArgs();
        $swooleResponse->shouldReceive('header')->withAnyArgs();
        $swooleResponse->shouldReceive('end')->withAnyArgs();

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testSetAndGetServerName()
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(false);

        $server = new Server($container);
        $result = $server->setServerName('custom');

        $this->assertSame($server, $result);
        $this->assertSame('custom', $server->getServerName());
    }

    public function testConstructorResolvesEventDispatcherWhenAvailable()
    {
        $eventDispatcher = m::mock(EventDispatcherContract::class);

        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(true);
        $container->shouldReceive('make')->with(EventDispatcherContract::class)->andReturn($eventDispatcher);

        // Should not throw — event dispatcher is resolved
        $server = new Server($container);
        $this->assertInstanceOf(Server::class, $server);
    }

    public function testOnRequestHandlesMalformedMethodOverrideAfterOverrideEnabled()
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        // Simulate a prior request having enabled method override (static flag persists in Swoole workers).
        // Save and restore the state since it's a process-global static.
        $reflection = new ReflectionProperty(Request::class, 'httpMethodParameterOverride');
        $previousState = $reflection->getValue();
        Request::enableHttpMethodParameterOverride();

        try {
            $kernel = m::mock(KernelContract::class);
            $kernel->shouldReceive('handle')
                ->once()
                ->andReturn(new Response('Bad Request', 400));
            $kernel->shouldReceive('terminate')->once();

            $container = m::mock(Container::class);
            $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(false);

            $server = new Server($container);
            $this->setKernel($server, $kernel);
            $this->setOption($server, Option::make(['enable_request_lifecycle' => false]));

            // Raw POST with malicious _method override — should not throw before kernel
            $swooleRequest = $this->createSwooleRequest(method: 'post');
            $swooleRequest->post = ['_method' => '__construct'];

            $swooleResponse = m::mock(SwooleResponse::class);
            $swooleResponse->shouldReceive('status')->once()->with(400);
            $swooleResponse->shouldReceive('header')->withAnyArgs();
            $swooleResponse->shouldReceive('end')->once();

            // Should not throw SuspiciousOperationException — the raw method
            // decision uses $swooleRequest->server['request_method'], not
            // $request->getMethod() which triggers the override.
            $server->onRequest($swooleRequest, $swooleResponse);
        } finally {
            $reflection->setValue(null, $previousState);
        }
    }

    public function testConstructorSkipsEventDispatcherWhenNotAvailable()
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('has')->with(EventDispatcherContract::class)->andReturn(false);
        $container->shouldNotReceive('make')->with(EventDispatcherContract::class);

        $server = new Server($container);
        $this->assertInstanceOf(Server::class, $server);
    }

    /**
     * Create a mock Swoole request.
     */
    private function createSwooleRequest(string $method = 'get', string $uri = '/'): SwooleRequest
    {
        $swooleRequest = m::mock(SwooleRequest::class);
        $swooleRequest->server = ['request_method' => $method, 'request_uri' => $uri];
        $swooleRequest->header = ['host' => 'example.com'];
        $swooleRequest->get = null;
        $swooleRequest->post = null;
        $swooleRequest->cookie = null;
        $swooleRequest->files = null;
        $swooleRequest->shouldReceive('rawContent')->andReturn(false);

        return $swooleRequest;
    }

    /**
     * Set the kernel on the server via reflection.
     */
    private function setKernel(Server $server, KernelContract $kernel): void
    {
        $reflection = new ReflectionProperty($server, 'kernel');
        $reflection->setValue($server, $kernel);
    }

    /**
     * Set the option on the server via reflection.
     */
    private function setOption(Server $server, Option $option): void
    {
        $reflection = new ReflectionProperty($server, 'option');
        $reflection->setValue($server, $option);
    }

    /**
     * Set the server name on the server via reflection.
     */
    private function setServerName(Server $server, string $name): void
    {
        $reflection = new ReflectionProperty($server, 'serverName');
        $reflection->setValue($server, $name);
    }
}
