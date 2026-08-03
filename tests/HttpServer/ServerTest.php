<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Request;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\HttpServer\Server;
use Hypervel\Routing\Router;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

use function Hypervel\Coroutine\wait;

class ServerTest extends TestCase
{
    protected string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = ParallelTesting::tempDir('HttpServerServerTest');
        (new Filesystem)->deleteDirectory($this->tempDirectory);
        mkdir($this->tempDirectory, 0777, true);
    }

    protected function tearDown(): void
    {
        CoordinatorManager::clear(Constants::WORKER_START);
        (new Filesystem)->deleteDirectory($this->tempDirectory);

        parent::tearDown();
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

    public function testOnRequestSetsRequestInContext(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $capturedRequest = null;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (Request $request) use (&$capturedRequest): Response {
                $capturedRequest = RequestContext::get();

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
        $failure = new RuntimeException('Fatal error');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')
            ->once()
            ->andThrow($failure);
        $kernel->shouldReceive('terminate')->once();

        $handledEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestHandled::class, function (RequestHandled $event) use (&$handledEvent): void {
            $handledEvent = $event;
        });

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($events);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(500)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('Internal Server Error')->andReturnTrue();

        try {
            $server->onRequest($swooleRequest, $swooleResponse);
            $this->fail('Expected the kernel failure to propagate after the fallback response.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertInstanceOf(RequestHandled::class, $handledEvent);
        $this->assertSame(500, $handledEvent->response->getStatusCode());
        $this->assertSame($failure, $handledEvent->exception);
    }

    public function testOnRequestPreservesOperationFailureAcrossExhaustiveFinalization(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $operationFailure = new RuntimeException('received failed');
        $handledFailure = new RuntimeException('handled failed');
        $sendFailure = new RuntimeException('send failed');
        $terminateFailure = new RuntimeException('terminate failed');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldNotReceive('handle');
        $kernel->shouldReceive('terminate')
            ->once()
            ->with(m::type(Request::class), m::on(
                fn (Response $response): bool => $response->getStatusCode() === 500
            ))
            ->andThrow($terminateFailure);

        $terminatedEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestReceived::class, fn () => throw $operationFailure);
        $events->listen(RequestHandled::class, fn () => throw $handledFailure);
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedEvent): void {
            $terminatedEvent = $event;
        });

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($events);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(500)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('Internal Server Error')->andThrow($sendFailure);

        try {
            wait(fn () => $server->onRequest($this->createSwooleRequest(), $swooleResponse));
            $this->fail('Expected the operation failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($operationFailure, $exception);
        }

        $this->assertInstanceOf(RequestTerminated::class, $terminatedEvent);
        $this->assertSame(500, $terminatedEvent->response->getStatusCode());
        $this->assertSame($operationFailure, $terminatedEvent->exception);
        $this->assertSame('http', $terminatedEvent->server);
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

        $terminatedEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestHandled::class, fn () => throw $handledFailure);
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedEvent): void {
            $terminatedEvent = $event;
        });

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($events);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('OK')->andThrow($sendFailure);

        try {
            wait(fn () => $server->onRequest($this->createSwooleRequest(), $swooleResponse));
            $this->fail('Expected the first finalization failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($handledFailure, $exception);
        }

        $this->assertInstanceOf(RequestTerminated::class, $terminatedEvent);
        $this->assertSame($handledFailure, $terminatedEvent->exception);
    }

    public function testRequestTerminatedObservesSendFailureBeforeLaterTerminationFailure(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $sendFailure = new RuntimeException('send failed');
        $terminateFailure = new RuntimeException('terminate failed');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->once()->andReturn(new Response('OK'));
        $kernel->shouldReceive('terminate')->once()->andThrow($terminateFailure);

        $terminatedEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedEvent): void {
            $terminatedEvent = $event;
        });

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($events);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('OK')->andThrow($sendFailure);

        try {
            wait(fn () => $server->onRequest($this->createSwooleRequest(), $swooleResponse));
            $this->fail('Expected the send failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($sendFailure, $exception);
        }

        $this->assertInstanceOf(RequestTerminated::class, $terminatedEvent);
        $this->assertSame($sendFailure, $terminatedEvent->exception);
    }

    public function testRequestTerminatedObservesTerminationFailure(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $terminateFailure = new RuntimeException('terminate failed');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->once()->andReturn(new Response('OK'));
        $kernel->shouldReceive('terminate')->once()->andThrow($terminateFailure);

        $terminatedEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedEvent): void {
            $terminatedEvent = $event;
        });

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($events);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->with('OK')->andReturnTrue();

        try {
            wait(fn () => $server->onRequest($this->createSwooleRequest(), $swooleResponse));
            $this->fail('Expected the termination failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($terminateFailure, $exception);
        }

        $this->assertInstanceOf(RequestTerminated::class, $terminatedEvent);
        $this->assertSame($terminateFailure, $terminatedEvent->exception);
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

    public function testOnRequestForwardsHttp2ProtocolToBinaryResponseEmission(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $path = $this->tempDirectory . '/http2-response.txt';
        file_put_contents($path, 'HTTP/2 body');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->once()->andReturn(new BinaryFileResponse($path));
        $kernel->shouldReceive('terminate')->once();

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(false);

        $server = new Server($container);
        $this->setKernel($server, $kernel);

        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->once()->with(200)->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('write')->once()->with('HTTP/2 body')->andReturnTrue();
        $swooleResponse->shouldReceive('end')->once()->withNoArgs()->andReturnTrue();
        $swooleResponse->shouldNotReceive('sendfile');

        $server->onRequest(
            $this->createSwooleRequest(protocol: 'HTTP/2'),
            $swooleResponse
        );
    }

    public function testOnRequestDispatchesLifecycleEventsWhenListenersAreRegistered(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $response = new Response('OK');

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->andReturn($response);
        $kernel->shouldReceive('terminate');

        $dispatchedEvents = [];
        $events = new Dispatcher;
        foreach ([RequestReceived::class, RequestHandled::class, RequestTerminated::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$dispatchedEvents): void {
                $dispatchedEvents[$event::class] = $event;
            });
        }

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($events);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldReceive('status')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('header')->withAnyArgs()->andReturnTrue();
        $swooleResponse->shouldReceive('end')->withAnyArgs()->andReturnTrue();

        wait(fn () => $server->onRequest($swooleRequest, $swooleResponse));

        $this->assertNull($dispatchedEvents[RequestReceived::class]->response);
        $this->assertSame($response, $dispatchedEvents[RequestHandled::class]->response);
        $this->assertNull($dispatchedEvents[RequestHandled::class]->exception);
        $this->assertSame($response, $dispatchedEvents[RequestTerminated::class]->response);
        $this->assertNull($dispatchedEvents[RequestTerminated::class]->exception);
    }

    public function testOnRequestSkipsFallbackEmissionAndTerminationAfterCancellation(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $cancellation = new CanceledException;

        $kernel = m::mock(KernelContract::class);
        $kernel->shouldReceive('handle')->once()->andThrow($cancellation);
        $kernel->shouldNotReceive('terminate');

        $dispatchedEvents = [];
        $events = new Dispatcher;
        foreach ([RequestHandled::class, RequestTerminated::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$dispatchedEvents): void {
                $dispatchedEvents[$event::class] = $event;
            });
        }

        $container = m::mock(Container::class);
        $container->shouldReceive('bound')->with('events')->andReturn(true);
        $container->shouldReceive('make')->with('events')->andReturn($events);

        $server = new Server($container);
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = m::mock(SwooleResponse::class);
        $swooleResponse->shouldNotReceive('status', 'header', 'cookie', 'rawcookie', 'write', 'sendfile', 'end');

        try {
            wait(fn () => $server->onRequest($this->createSwooleRequest(), $swooleResponse));
            $this->fail('Expected cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertNull($dispatchedEvents[RequestHandled::class]->response);
        $this->assertSame($cancellation, $dispatchedEvents[RequestHandled::class]->exception);
        $this->assertNull($dispatchedEvents[RequestTerminated::class]->response);
        $this->assertSame($cancellation, $dispatchedEvents[RequestTerminated::class]->exception);
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
    private function createSwooleRequest(
        string $method = 'get',
        string $uri = '/',
        string $protocol = 'HTTP/1.1',
    ): SwooleRequest {
        $swooleRequest = m::mock(SwooleRequest::class);
        $swooleRequest->server = [
            'request_method' => $method,
            'request_uri' => $uri,
            'server_protocol' => $protocol,
        ];
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
