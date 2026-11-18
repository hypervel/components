<?php

declare(strict_types=1);

namespace Hypervel\Tests\HttpServer;

use Hypervel\Container\Container as BaseContainer;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Http\Kernel as KernelContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Events\Dispatcher;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Http\Request;
use Hypervel\HttpServer\Events\RequestCanceled;
use Hypervel\HttpServer\Events\RequestHandled;
use Hypervel\HttpServer\Events\RequestReceived;
use Hypervel\HttpServer\Events\RequestTerminated;
use Hypervel\HttpServer\Events\ResponseSent;
use Hypervel\HttpServer\Server;
use Hypervel\Routing\Router;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Hypervel\Coroutine\wait;

class ServerTest extends TestCase
{
    use VerifiesDoubles;

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
        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('bootstrap');

        $router = Double::for(Router::class)->strict();
        $router->expects('compileAndWarm');

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);
        $container->allows('make')->with(KernelContract::class)->returns($kernel);
        $container->allows('make')->with('router')->returns($router);

        $server = new Server($container->instance());
        $server->bootstrapForServer('http');

        $this->assertSame('http', $server->getServerName());
    }

    public function testOnRequestDelegatestoKernelAndSendsResponse(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')
            ->with(Argument::type(Request::class))
            ->returns(new Response('Hello World', 200));
        $kernel->expects('terminate');

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('Hello World')->returns(true);

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testOnRequestWaitsForWorkerStartOnlyOnce(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')
            ->times(2)
            ->with(Argument::type(Request::class))
            ->returns(new Response('OK'));
        $kernel->expects('terminate')->times(2);

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->times(2)->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->times(2)->with('OK')->returns(true);

        wait(fn () => $server->onRequest($this->createSwooleRequest(), $swooleResponse));

        CoordinatorManager::clear(Constants::WORKER_START);

        wait(
            fn () => $server->onRequest($this->createSwooleRequest(), $swooleResponse),
            timeout: 0.1
        );
    }

    public function testOnRequestRetriesWorkerStartAfterNonThrowingCancellation(): void
    {
        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')
            ->with(Argument::type(Request::class))
            ->returns(new Response('OK'));
        $kernel->expects('terminate');

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);

        $canceledResponse = Double::for(SwooleResponse::class)->strict();

        $cancellation = null;
        $canceledRequest = EngineCoroutine::create(function () use ($server, $canceledResponse, &$cancellation): void {
            try {
                $server->onRequest($this->createSwooleRequest(), $canceledResponse);
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            }
        });

        $this->assertTrue(EngineCoroutine::cancelById($canceledRequest->getId()));
        $this->assertInstanceOf(CanceledException::class, $cancellation);
        $this->assertSame('Waiting for the HTTP worker to start was canceled.', $cancellation->getMessage());
        $canceledResponse->unused();

        $handled = false;
        $response = Double::for(SwooleResponse::class)->strict();
        $response->expects('status')->with(200)->returns(true);
        $response->allows('header')->returns(true);
        $response->expects('end')->with('OK')->returns(true);

        EngineCoroutine::create(function () use ($server, $response, &$handled): void {
            $server->onRequest($this->createSwooleRequest(), $response);
            $handled = true;
        });

        $this->assertFalse($handled);

        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $this->assertTrue($handled);
    }

    public function testOnRequestKeepsRequestInContextThroughDeferredTermination(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $capturedRequest = null;
        $terminatedRequest = null;
        $eventRequest = null;
        $handlingCoroutineId = null;
        $terminationCoroutineId = null;

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')
            ->resolves(function (Request $request) use (&$capturedRequest, &$handlingCoroutineId): Response {
                $capturedRequest = RequestContext::get();
                $handlingCoroutineId = EngineCoroutine::id();

                return new Response('OK');
            });
        $kernel->expects('terminate');

        $events = new Dispatcher;
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedRequest, &$eventRequest, &$terminationCoroutineId): void {
            $terminatedRequest = RequestContext::getOrNull();
            $eventRequest = $event->request;
            $terminationCoroutineId = EngineCoroutine::id();
        });

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->allows('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->allows('end')->returns(true);

        wait(fn () => $server->onRequest($swooleRequest, $swooleResponse));

        $this->assertSame($handlingCoroutineId, $terminationCoroutineId);
        $this->assertInstanceOf(Request::class, $capturedRequest);
        $this->assertSame($capturedRequest, $eventRequest);
        $this->assertSame($capturedRequest, $terminatedRequest);
        $this->assertFalse(RequestContext::has());
    }

    public function testOnRequestReturns500OnKernelException(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $failure = new RuntimeException('Fatal error');

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')
            ->throws($failure);
        $kernel->expects('terminate');

        $handledEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestHandled::class, function (RequestHandled $event) use (&$handledEvent): void {
            $handledEvent = $event;
        });

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(500)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('Internal Server Error')->returns(true);

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

        $handler = $this->bindReportingHandler();
        $handler->expects('report')->with(Argument::same($handledFailure))->ordered();
        $handler->expects('report')->with(Argument::same($sendFailure))->ordered();
        $handler->expects('report')->with(Argument::same($terminateFailure))->ordered();

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')->never();
        $kernel->expects('terminate')
            ->with(Argument::type(Request::class), Argument::satisfies(
                fn (Response $response): bool => $response->getStatusCode() === 500
            ))
            ->throws($terminateFailure);

        $terminatedEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestReceived::class, fn () => throw $operationFailure);
        $events->listen(RequestHandled::class, fn () => throw $handledFailure);
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedEvent): void {
            $terminatedEvent = $event;
        });

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(500)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('Internal Server Error')->throws($sendFailure);

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
        $handler = $this->bindReportingHandler();
        $handler->expects('report')->with(Argument::same($sendFailure))->ordered();
        $handler->expects('report')->with(Argument::same($terminateFailure))->ordered();
        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')->returns(new Response('OK'));
        $kernel->expects('terminate')->throws($terminateFailure);

        $terminatedEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestHandled::class, fn () => throw $handledFailure);
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedEvent): void {
            $terminatedEvent = $event;
        });

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('OK')->throws($sendFailure);

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
        $this->bindReportingHandler()->expects('report')->with(Argument::same($terminateFailure));

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')->returns(new Response('OK'));
        $kernel->expects('terminate')->throws($terminateFailure);

        $sentEvent = null;
        $terminatedEvent = null;
        $events = new Dispatcher;
        $events->listen(ResponseSent::class, function (ResponseSent $event) use (&$sentEvent): void {
            $sentEvent = $event;
        });
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedEvent): void {
            $terminatedEvent = $event;
        });

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('OK')->throws($sendFailure);

        try {
            wait(fn () => $server->onRequest($this->createSwooleRequest(), $swooleResponse));
            $this->fail('Expected the send failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($sendFailure, $exception);
        }

        $this->assertInstanceOf(ResponseSent::class, $sentEvent);
        $this->assertSame($sendFailure, $sentEvent->exception);
        $this->assertInstanceOf(RequestTerminated::class, $terminatedEvent);
        $this->assertSame($sendFailure, $terminatedEvent->exception);
    }

    public function testRequestTerminatedObservesTerminationFailure(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $terminateFailure = new RuntimeException('terminate failed');

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')->returns(new Response('OK'));
        $kernel->expects('terminate')->throws($terminateFailure);

        $terminatedEvent = null;
        $events = new Dispatcher;
        $events->listen(RequestTerminated::class, function (RequestTerminated $event) use (&$terminatedEvent): void {
            $terminatedEvent = $event;
        });

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('OK')->returns(true);

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

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')
            ->returns(new Response('This should not be sent', 200));
        $kernel->expects('terminate');

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);

        $swooleRequest = $this->createSwooleRequest(method: 'head');
        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        // end() with no args — body suppressed for HEAD
        $swooleResponse->expects('end')->with()->returns(true);

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testOnRequestForwardsHttp2ProtocolToBinaryResponseEmission(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $path = $this->tempDirectory . '/http2-response.txt';
        file_put_contents($path, 'HTTP/2 body');

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')->returns(new BinaryFileResponse($path));
        $kernel->expects('terminate');

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('write')->with('HTTP/2 body')->returns(true);
        $swooleResponse->expects('end')->with()->returns(true);
        $swooleResponse->expects('sendfile')->never();

        $server->onRequest(
            $this->createSwooleRequest(protocol: 'HTTP/2'),
            $swooleResponse
        );
    }

    public function testOnRequestDispatchesLifecycleEventsWhenListenersAreRegistered(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $response = new Response('OK');

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->allows('handle')->returns($response);
        $order = [];
        $kernel->allows('terminate')->resolves(function () use (&$order): void {
            $order[] = 'terminate';
        });

        $dispatchedEvents = [];
        $events = new Dispatcher;
        foreach ([RequestReceived::class, RequestHandled::class, ResponseSent::class, RequestTerminated::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$dispatchedEvents, &$order): void {
                $dispatchedEvents[$event::class] = $event;
                $order[] = $event::class;
            });
        }

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->allows('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->allows('end')->returns(true);

        wait(fn () => $server->onRequest($swooleRequest, $swooleResponse));

        $this->assertNull($dispatchedEvents[RequestReceived::class]->response);
        $this->assertSame($response, $dispatchedEvents[RequestHandled::class]->response);
        $this->assertNull($dispatchedEvents[RequestHandled::class]->exception);
        $this->assertSame($response, $dispatchedEvents[ResponseSent::class]->response);
        $this->assertNull($dispatchedEvents[ResponseSent::class]->exception);
        $this->assertSame($response, $dispatchedEvents[RequestTerminated::class]->response);
        $this->assertNull($dispatchedEvents[RequestTerminated::class]->exception);
        $this->assertSame([
            RequestReceived::class,
            RequestHandled::class,
            ResponseSent::class,
            'terminate',
            RequestTerminated::class,
        ], $order);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testOnRequestSkipsFallbackEmissionAndTerminationAfterCancellation(bool $chained): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $cancellation = new CanceledException;

        if ($chained) {
            $cancellation = new RuntimeException('cleanup failed', previous: $cancellation);
        }

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')->throws($cancellation);
        $kernel->expects('terminate')->never();

        $dispatchedEvents = [];
        $events = new Dispatcher;
        foreach ([RequestHandled::class, ResponseSent::class, RequestTerminated::class, RequestCanceled::class] as $eventClass) {
            $events->listen($eventClass, function (object $event) use (&$dispatchedEvents): void {
                $dispatchedEvents[$event::class] = $event;
            });
        }

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        foreach (['status', 'header', 'cookie', 'rawcookie', 'write', 'sendfile', 'end'] as $method) {
            $swooleResponse->expects($method)->never();
        }

        $caughtCancellation = null;
        $completed = new Channel(1);
        $requestCoroutine = EngineCoroutine::create(function () use (
            $completed,
            $server,
            $swooleResponse,
            &$caughtCancellation,
        ): void {
            try {
                $server->onRequest($this->createSwooleRequest(), $swooleResponse);
            } catch (Throwable $exception) {
                $caughtCancellation = $exception;
            } finally {
                $completed->push(true);
            }
        });

        try {
            $this->assertTrue($completed->pop(1));
            $this->assertSame($cancellation, $caughtCancellation);
            $this->assertSame([RequestCanceled::class], array_keys($dispatchedEvents));
            $this->assertSame($cancellation, $dispatchedEvents[RequestCanceled::class]->cancellation);
            $this->assertNull($dispatchedEvents[RequestCanceled::class]->response);
            $this->assertNull($dispatchedEvents[RequestCanceled::class]->exception);
            $this->assertFalse(RequestContext::has());
        } finally {
            if (EngineCoroutine::exists($requestCoroutine->getId())) {
                EngineCoroutine::cancelById($requestCoroutine->getId(), throwException: true);
            }
        }
    }

    #[DataProvider('lifecycleCancellationStages')]
    public function testOnRequestStopsAtCancellationDuringFinalization(
        string $stage,
        array $expectedOrder,
        bool $chained,
    ): void {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $cancellation = new CanceledException;

        if ($chained) {
            $cancellation = new RuntimeException('cleanup failed', previous: $cancellation);
        }

        $order = [];

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')->returns(new Response('OK'));
        $kernel->expects('terminate')
            ->times($stage === 'terminate' ? 1 : 0)
            ->resolves(function () use ($stage, &$order, $cancellation): void {
                $order[] = 'terminate';

                if ($stage === 'terminate') {
                    throw $cancellation;
                }
            });

        $events = new Dispatcher;
        $events->listen(RequestHandled::class, function () use ($stage, &$order, $cancellation): void {
            $order[] = 'handled';

            if ($stage === 'handled') {
                throw $cancellation;
            }
        });
        $events->listen(ResponseSent::class, function () use ($stage, &$order, $cancellation): void {
            $order[] = 'sent';

            if ($stage === 'sent') {
                throw $cancellation;
            }
        });
        $events->listen(RequestTerminated::class, function () use (&$order): void {
            $order[] = 'terminated';
        });
        $canceled = null;
        $events->listen(RequestCanceled::class, function (RequestCanceled $event) use (&$order, &$canceled): void {
            $order[] = 'canceled';
            $canceled = $event;
        });

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = Double::for(SwooleResponse::class)->strict();

        if ($stage === 'handled') {
            foreach (['status', 'header', 'cookie', 'rawcookie', 'write', 'sendfile', 'end'] as $method) {
                $swooleResponse->expects($method)->never();
            }
        } else {
            $swooleResponse->expects('status')->with(200)->returns(true);
            $swooleResponse->allows('header')->returns(true);
            $swooleResponse->expects('end')
                ->with('OK')
                ->resolves(function () use ($stage, $cancellation): bool {
                    if ($stage === 'send') {
                        throw $cancellation;
                    }

                    return true;
                });
        }

        $caught = null;

        try {
            $server->onRequest($this->createSwooleRequest(), $swooleResponse);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
        $this->assertSame([...$expectedOrder, 'canceled'], $order);
        $this->assertSame($cancellation, $canceled->cancellation);
        $this->assertSame(in_array($stage, ['handled', 'send'], true), $canceled->response === null);
        $this->assertNull($canceled->exception);
    }

    public static function lifecycleCancellationStages(): array
    {
        return [
            'handled listener' => ['handled', ['handled'], false],
            'response send' => ['send', ['handled'], false],
            'sent listener' => ['sent', ['handled', 'sent'], false],
            'termination' => ['terminate', ['handled', 'sent', 'terminate'], false],
            'handled listener cleanup' => ['handled', ['handled'], true],
            'response send cleanup' => ['send', ['handled'], true],
            'sent listener cleanup' => ['sent', ['handled', 'sent'], true],
            'termination cleanup' => ['terminate', ['handled', 'sent', 'terminate'], true],
        ];
    }

    public function testCancellationReportsTheHeldFailureAndCanceledListenerFailures(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();
        $heldFailure = new RuntimeException('Handled listener failed.');
        $listenerFailure = new RuntimeException('Canceled listener failed.');
        $cancellation = new CanceledException;
        $handler = $this->bindReportingHandler();
        $handler->expects('report')->with(Argument::same($listenerFailure))->ordered();
        $handler->expects('report')->with(Argument::same($heldFailure))->ordered();

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->expects('handle')->returns(new Response('OK'));
        $kernel->expects('terminate')->never();

        $events = new Dispatcher;
        $events->listen(RequestHandled::class, static fn () => throw $heldFailure);
        $events->listen(ResponseSent::class, static fn () => throw $cancellation);
        $canceled = null;
        $events->listen(RequestCanceled::class, static function (RequestCanceled $event) use (&$canceled, $listenerFailure): never {
            $canceled = $event;

            throw $listenerFailure;
        });

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($events);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);
        $this->setServerName($server, 'http');

        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->expects('status')->with(200)->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->expects('end')->with('OK')->returns(true);

        try {
            $server->onRequest($this->createSwooleRequest(), $swooleResponse);
            $this->fail('Expected the cancellation to escape.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame($heldFailure, $canceled->exception);
        $this->assertNotNull($canceled->response);
    }

    public function testOnRequestSkipsLifecycleEventDispatchWhenNoListenersAreRegistered(): void
    {
        CoordinatorManager::until(Constants::WORKER_START)->resume();

        $eventDispatcher = Double::for(EventDispatcherContract::class)->strict();
        $eventDispatcher->expects('hasListeners')->with(RequestReceived::class)->returns(false);
        $eventDispatcher->expects('hasListeners')->with(RequestTerminated::class)->returns(false);
        $eventDispatcher->expects('hasListeners')->with(RequestHandled::class)->returns(false);
        $eventDispatcher->expects('hasListeners')->with(ResponseSent::class)->returns(false);
        $eventDispatcher->expects('dispatch')->never();

        $kernel = Double::for(KernelContract::class)->strict();
        $kernel->allows('handle')->returns(new Response('OK'));
        $kernel->allows('terminate');

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($eventDispatcher);

        $server = new Server($container->instance());
        $this->setKernel($server, $kernel);

        $swooleRequest = $this->createSwooleRequest();
        $swooleResponse = Double::for(SwooleResponse::class)->strict();
        $swooleResponse->allows('status')->returns(true);
        $swooleResponse->allows('header')->returns(true);
        $swooleResponse->allows('end')->returns(true);

        $server->onRequest($swooleRequest, $swooleResponse);
    }

    public function testSetAndGetServerName(): void
    {
        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);

        $server = new Server($container->instance());
        $result = $server->setServerName('custom');

        $this->assertSame($server, $result);
        $this->assertSame('custom', $server->getServerName());
    }

    public function testConstructorResolvesEventDispatcherWhenAvailable(): void
    {
        $eventDispatcher = Double::for(EventDispatcherContract::class)->strict();

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(true);
        $container->allows('make')->with('events')->returns($eventDispatcher);

        // Should not throw — event dispatcher is resolved
        $server = new Server($container->instance());
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
            $kernel = Double::for(KernelContract::class)->strict();
            $kernel->expects('handle')
                ->returns(new Response('Bad Request', 400));
            $kernel->expects('terminate');

            $container = Double::for(Container::class, override: true)->strict();
            $container->allows('bound')->with('events')->returns(false);

            $server = new Server($container->instance());
            $this->setKernel($server, $kernel);

            // Raw POST with malicious _method override — should not throw before kernel
            $swooleRequest = $this->createSwooleRequest(method: 'post');
            $swooleRequest->post = ['_method' => '__construct'];

            $swooleResponse = Double::for(SwooleResponse::class)->strict();
            $swooleResponse->expects('status')->with(400)->returns(true);
            $swooleResponse->allows('header')->returns(true);
            $swooleResponse->expects('end')->returns(true);

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
        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('bound')->with('events')->returns(false);
        $container->expects('make')->never()->with('events');

        $server = new Server($container->instance());
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
        $swooleRequest = Double::for(SwooleRequest::class)->strict();
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
        $swooleRequest->allows('rawContent')->returns(false);

        return $swooleRequest;
    }

    /**
     * Bind a mocked exception handler for displaced failure reports.
     */
    private function bindReportingHandler(): ExceptionHandler&DoubleInterface
    {
        $container = new BaseContainer;
        BaseContainer::setInstance($container);
        $handler = Double::for(ExceptionHandler::class)->strict();
        $container->instance(ExceptionHandler::class, $handler);

        return $handler;
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
