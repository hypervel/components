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
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Response;

class ServerTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        CoordinatorManager::clear(Constants::WORKER_START);
    }

    public function testBootstrapForServerResolvesKernelAndBootstraps(): void
    {
        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('bootstrap')->once();

        $router = m::mock(Router::class);
        $router->shouldReceive('compileAndWarm')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldReceive('make')->with(KernelContract::class)->andReturn($kernel);
        $container->shouldReceive('make')->with('router')->andReturn($router);

        $server = new Server($container);
        $server->bootstrapForServer('http');

        $this->assertSame('http', $server->getServerName());
    }

    public function testOnRequestDelegatestoKernelAndSendsResponse(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->with(m::type(Request::class))
            ->andReturn(new Response('Hello World', 200));
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('Hello World')->andReturnTrue();

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testOnRequestSetsRequestAndResponseInContext(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $capturedRequest = null;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (Request $request) use (&$capturedRequest): Response {
                // Inside the kernel, RequestContext should have the request
                $capturedRequest = RequestContext::get();
                // ResponseContext should also be set
                $this->assertInstanceOf(HypervelResponse::class, ResponseContext::get());
                return new Response('OK');
            });
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->withAnyArgs()->andReturnTrue();

        $server->onRequest($swooleRequest, $swooleResponse);

        $this->assertInstanceOf(Request::class, $capturedRequest);
    }

    public function testOnRequestReturns500OnKernelException(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Fatal error'));
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(500)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('Internal Server Error')->andReturnTrue();

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testOnRequestPreservesACommittedResponseAfterKernelFailure(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $failure = new RuntimeException('stream failed');
        $committedResponse = null;
        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function () use ($failure, &$committedResponse): never {
                $committedResponse = ResponseContext::get();
                $committedResponse->markStreamed();

                throw $failure;
            });
        $kernel->shouldReceive('terminate')
            ->once()
            ->with(m::type(Request::class), m::on(
                function (Response $response) use (&$committedResponse): bool {
                    return $response === $committedResponse;
                }
            ));
        $handledEvent = null;
        $eventDispatcher = m::mock(EventDispatcherContract::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestReceived::class)->andReturn(false);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestTerminated::class)->andReturn(false);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestHandled::class)->andReturn(true);
        $eventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(RequestHandled::class))
            ->andReturnUsing(function (RequestHandled $event) use (&$handledEvent): RequestHandled {
                $handledEvent = $event;

                return $event;
            });
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($eventDispatcher);
        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldNotReceive('status');
        $swooleResponse->shouldNotReceive('header');
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnFalse();

        $server->onRequest($this->createSwooleRequest(), $swooleResponse);

        $this->assertInstanceOf(HypervelResponse::class, $committedResponse);
        $this->assertInstanceOf(RequestHandled::class, $handledEvent);
        $this->assertSame($committedResponse, $handledEvent->response);
        $this->assertSame($failure, $handledEvent->exception);
    }

    public function testOnRequestFinalizationIsExhaustiveAndPreservesTheFirstFailure(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $handledFailure = new RuntimeException('handled failed');
        $sendFailure = new RuntimeException('send failed');
        $terminateFailure = new RuntimeException('terminate failed');
        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->once()->andReturn(new Response('OK'));
        $kernel->shouldReceive('terminate')->once()->andThrow($terminateFailure);
        $eventDispatcher = m::mock(EventDispatcherContract::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestReceived::class)->andReturn(false);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestTerminated::class)->andReturn(false);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestHandled::class)->andReturn(true);
        $eventDispatcher->shouldReceive('dispatch')
            ->once()
            ->with(m::type(RequestHandled::class))
            ->andThrow($handledFailure);
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($eventDispatcher);
        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('OK')->andThrow($sendFailure);

        try {
            $server->onRequest($this->createSwooleRequest(), $swooleResponse);
            $this->fail('Expected the first finalization failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($handledFailure, $exception);
        }
    }

    public function testOnRequestSuppressesBodyForHeadRequests(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andReturn(new Response('This should not be sent', 200));
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);

        $swooleRequest = $this->createSwooleRequest(method: 'head');
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        // end() with no args — body suppressed for HEAD
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testOnRequestDispatchesLifecycleEventsWhenListenersAreRegistered(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $dispatchedEvents = [];

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->andReturn(new Response('OK'));
        $kernel->shouldReceive('terminate');

        $eventDispatcher = m::mock(EventDispatcherContract::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestReceived::class)->andReturn(true);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestTerminated::class)->andReturn(true);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestHandled::class)->andReturn(true);
        $eventDispatcher->shouldReceive('dispatch')
            ->andReturnUsing(function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = get_class($event);

                return $event;
            });

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($eventDispatcher);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->withAnyArgs()->andReturnTrue();

        $server->onRequest($swooleRequest, $swooleResponse);

        // RequestReceived and RequestHandled should be dispatched synchronously.
        // RequestTerminated is deferred, so it may not be in the list yet.
        $this->assertContains(RequestReceived::class, $dispatchedEvents);
        $this->assertContains(RequestHandled::class, $dispatchedEvents);
    }

    public function testOnRequestSkipsLifecycleEventDispatchWhenNoListenersAreRegistered(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $eventDispatcher = m::mock(EventDispatcherContract::class);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestReceived::class)->andReturn(false);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestTerminated::class)->andReturn(false);
        $eventDispatcher->shouldReceive('hasListeners')->once()->with(RequestHandled::class)->andReturn(false);
        $eventDispatcher->shouldNotReceive('dispatch');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->andReturn(new Response('OK'));
        $kernel->shouldReceive('terminate');

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($eventDispatcher);

        $server = new Server($container);
        $this->setKernel($server, $kernel);

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->withAnyArgs()->andReturnTrue();

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testSetAndGetServerName(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $server = new Server($container);
        $result = $server->setServerName('custom');

        $this->assertSame($server, $result);
        $this->assertSame('custom', $server->getServerName());
    }

    public function testConstructorResolvesEventDispatcherWhenAvailable(): void
    {
        $eventDispatcher = m::mock(EventDispatcherContract::class);

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($eventDispatcher);

        // Should not throw — event dispatcher is resolved
        $server = new Server($container);
        $this->assertInstanceOf(Server::class, $server);
    }

    public function testOnRequestHandlesMalformedMethodOverrideAfterOverrideEnabled(): void
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
            $container->shouldReceive('bound')->with('events')->andReturn(false);

            $server = new Server($container);
            $this->setKernel($server, $kernel);

            // Raw POST with malicious _method override — should not throw before kernel
            $swooleRequest = $this->createSwooleRequest(method: 'post');
            $swooleRequest->post = ['_method' => '__construct'];

            $swooleResponse = m::mock(SwooleResponse::class);
            $swooleResponse->shouldReceive('status')->once()->with(400)->andReturnTrue();
            $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
            $swooleResponse->shouldReceive('end')->once()->andReturnTrue();

            // Should not throw SuspiciousOperationException — the raw method
            // decision uses $swooleRequest->server['request_method'], not
            // $request->getMethod() which triggers the override.
            $server->onRequest($swooleRequest, $swooleResponse);
        } finally {
            $reflection->setValue(null, $previousState);
        }
    }

    public function testConstructorSkipsEventDispatcherWhenNotAvailable(): void
    {
        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldNotReceive('make')->with('events');

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
     * Set the server name on the server via reflection.
     */
    private function setServerName(Server $server, string $name): void
    {
        $reflection = new ReflectionProperty($server, 'serverName');
        $reflection->setValue($server, $name);
    }
}
