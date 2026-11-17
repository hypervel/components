<?php

declare(strict_types=1);

namespace Hypervel\Tests\WebSocketServer;

use Hypervel\Container\Container as BaseContainer;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Events\Dispatcher as EventDispatcherContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Server\OnCloseInterface;
use Hypervel\Contracts\Server\OnMessageInterface;
use Hypervel\Contracts\Server\OnOpenInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Waiter;
use Hypervel\Http\Request as HttpRequest;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Defer\DeferredCallbackCollection;
use Hypervel\Tests\TestCase;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketMessageStub;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketStub;
use Hypervel\Tests\WebSocketServer\Fixtures\WebSocketThrowingStub;
use Hypervel\WebSocketServer\Collector\FdCollector;
use Hypervel\WebSocketServer\Context as WebSocketContext;
use Hypervel\WebSocketServer\Events\ConnectionClosed;
use Hypervel\WebSocketServer\Events\ConnectionClosing;
use Hypervel\WebSocketServer\Events\ConnectionOpened;
use Hypervel\WebSocketServer\Events\MessageHandled;
use Hypervel\WebSocketServer\Events\MessageReceived;
use Hypervel\WebSocketServer\Exceptions\Handler\WebSocketExceptionHandler;
use Hypervel\WebSocketServer\Server;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use JMac\Testing\OverriddenDouble;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WebSocketSwooleServer;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function Hypervel\Support\defer;

class ServerTest extends TestCase
{
    use VerifiesDoubles;

    protected function setUp(): void
    {
        parent::setUp();

        BaseContainer::getInstance()->scoped(DeferredCallbackCollection::class);
    }

    protected function tearDown(): void
    {
        WebSocketMessageStub::flushState();
        WebSocketStub::$coroutineId = 0;

        parent::tearDown();
    }

    /**
     * Verify that deferOnOpen defers the onOpen call so it runs after
     * the calling coroutine exits, and that onOpen is invoked on
     * OnOpenInterface implementors.
     */
    public function testDeferOnOpenCallsOnOpen(): void
    {
        WebSocketStub::$coroutineId = 0;

        $container = $this->createContainer();
        $server = new Server($container->instance());

        $invoker = new ClassInvoker($server);
        $swooleServer = Double::for(WebSocketSwooleServer::class)->strict();

        // Run deferOnOpen inside a child coroutine so that defer() fires
        // when that coroutine exits, before we make our assertions.
        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketStub, $swooleServer, 1, new HttpRequest, new Response('', 101));
        });
        $this->waitForCoroutine($coroutineId);

        $this->assertNotSame(0, WebSocketStub::$coroutineId, 'onOpen should have been called');
        $this->assertNotSame(Coroutine::id(), WebSocketStub::$coroutineId, 'onOpen should run in a different coroutine');
    }

    public function testDeferOnOpenLogsExceptionFromOnOpen(): void
    {
        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('error')->with(Argument::satisfies(
            fn (string $message): bool => str_contains($message, 'onOpen failed')
        ));

        $container = $this->createContainer($logger);
        $server = new Server($container->instance());
        $invoker = new ClassInvoker($server);
        $swooleServer = Double::for(WebSocketSwooleServer::class)->strict();

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketThrowingStub, $swooleServer, 1, new HttpRequest, new Response('', 101));
        });
        $this->waitForCoroutine($coroutineId);
    }

    public function testConnectionOpenedEventIsDispatched(): void
    {
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionOpened::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (ConnectionOpened $event): bool => $event->fd === 1 && $event->server === 'websocket'
        ));

        $container = $this->createContainer(dispatcher: $dispatcher);
        $server = new Server($container->instance());
        $invoker = new ClassInvoker($server);
        $swooleServer = Double::for(WebSocketSwooleServer::class)->strict();

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketStub, $swooleServer, 1, new HttpRequest, new Response('', 101));
        });
        $this->waitForCoroutine($coroutineId);
    }

    public function testConnectionOpenedEventNotDispatchedWithoutListeners(): void
    {
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(ConnectionOpened::class)->returns(false);
        $dispatcher->expects('dispatch')->never();

        $container = $this->createContainer(dispatcher: $dispatcher);
        $server = new Server($container->instance());
        $invoker = new ClassInvoker($server);
        $swooleServer = Double::for(WebSocketSwooleServer::class)->strict();

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketStub, $swooleServer, 1, new HttpRequest, new Response('', 101));
        });
        $this->waitForCoroutine($coroutineId);
    }

    public function testConnectionOpenedEventDispatchedEvenWhenOnOpenThrows(): void
    {
        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('error');

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionOpened::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (ConnectionOpened $event): bool => $event->fd === 1
        ));

        $container = $this->createContainer($logger, $dispatcher);
        $server = new Server($container->instance());
        $invoker = new ClassInvoker($server);
        $swooleServer = Double::for(WebSocketSwooleServer::class)->strict();

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketThrowingStub, $swooleServer, 1, new HttpRequest, new Response('', 101));
        });
        $this->waitForCoroutine($coroutineId);
    }

    public function testOnOpenRunsWhenConnectionOpenedEventThrows(): void
    {
        WebSocketStub::$coroutineId = 0;

        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('error')->with(Argument::type('string'));

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionOpened::class)->returns(true);
        $dispatcher->expects('dispatch')->throws(new RuntimeException('event failed'));

        $server = new Server($this->createContainer($logger, $dispatcher)->instance());
        $invoker = new ClassInvoker($server);
        $swooleServer = Double::for(WebSocketSwooleServer::class)->strict();

        $coroutineId = Coroutine::create(function () use ($invoker, $swooleServer) {
            $invoker->deferOnOpen(new SwooleRequest, new WebSocketStub, $swooleServer, 1, new HttpRequest, new Response('', 101));
        });
        $this->waitForCoroutine($coroutineId);

        $this->assertNotSame(0, WebSocketStub::$coroutineId);
    }

    #[DataProvider('messageDeferredOutcomes')]
    public function testMessageDeferredWorkFollowsTheCallbackOutcome(?string $failureAt, string $failure, array $expected): void
    {
        $calls = [];
        $exception = match ($failure) {
            'cancellation' => new CanceledException,
            'wrapped' => new RuntimeException('message cleanup failed', previous: new CanceledException),
            default => new RuntimeException('message failed'),
        };
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->returns(true);
        $dispatcher->allows('dispatch')->resolves(function (object $event) use (&$calls, $failureAt, $exception): void {
            $phase = $event instanceof MessageReceived ? 'received' : 'handled';
            $calls[] = $phase;

            if ($phase === 'received') {
                defer(function () use (&$calls): void { $calls[] = 'deferred'; });
                defer(function () use (&$calls): void { $calls[] = 'always'; })->always();
            }

            if ($failureAt === $phase) {
                throw $exception;
            }
        });
        $handler = Double::for(OnMessageInterface::class)->strict();
        $handler->allows('onMessage')->resolves(function () use (&$calls, $failureAt, $exception): void {
            $calls[] = 'handler';
            if ($failureAt === 'handler') {
                throw $exception;
            }
        });
        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->allows('make')->with(OnMessageInterface::class)->returns($handler);
        FdCollector::set(1, OnMessageInterface::class);
        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertSame($expected, $calls);
    }

    /**
     * Supply successful, failed and canceled message phases.
     */
    public static function messageDeferredOutcomes(): array
    {
        return [
            'success' => [null, 'failure', ['received', 'handler', 'handled', 'deferred', 'always']],
            'received failure' => ['received', 'failure', ['received', 'handler', 'handled', 'always']],
            'handler failure' => ['handler', 'failure', ['received', 'handler', 'handled', 'always']],
            'handled failure' => ['handled', 'failure', ['received', 'handler', 'handled', 'always']],
            'received cancellation' => ['received', 'cancellation', ['received', 'handled']],
            'handler cancellation' => ['handler', 'cancellation', ['received', 'handler', 'handled']],
            'handled cancellation' => ['handled', 'cancellation', ['received', 'handler', 'handled']],
            'received wrapped cancellation' => ['received', 'wrapped', ['received', 'handled']],
            'handler wrapped cancellation' => ['handler', 'wrapped', ['received', 'handler', 'handled']],
            'handled wrapped cancellation' => ['handled', 'wrapped', ['received', 'handler', 'handled']],
        ];
    }

    #[DataProvider('callbackFailures')]
    public function testOpenDeferredWorkRunsAfterTheHandler(bool $fail): void
    {
        $calls = [];
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionOpened::class)->returns(true);
        $dispatcher->expects('dispatch')->resolves(function () use (&$calls): void {
            $calls[] = 'opened';
            defer(function () use (&$calls): void { $calls[] = 'event deferred'; })->always();
        });
        $handler = Double::for(OnOpenInterface::class)->strict();
        $handler->expects('onOpen')->resolves(function () use (&$calls, $fail): void {
            $calls[] = 'handler';
            defer(function () use (&$calls): void { $calls[] = 'handler deferred'; });
            if ($fail) {
                throw new RuntimeException('open failed');
            }
        });
        $server = new Server($this->createContainer(dispatcher: $dispatcher)->instance());
        $native = Double::for(WebSocketSwooleServer::class)->strict();

        (new Waiter(1))->wait(fn () => (new ClassInvoker($server))->deferOnOpen(
            new SwooleRequest,
            $handler,
            $native,
            1,
            new HttpRequest,
            new Response('', 101)
        ));

        $this->assertSame($fail
            ? ['opened', 'handler', 'event deferred']
            : ['opened', 'handler', 'event deferred', 'handler deferred'], $calls);
    }

    #[DataProvider('callbackFailures')]
    public function testCloseDeferredWorkRunsBeforeConnectionContextIsReleased(bool $fail): void
    {
        $calls = [];
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionClosing::class)->returns(false);
        $dispatcher->allows('hasListeners')->with(ConnectionClosed::class)->returns(true);
        $dispatcher->expects('dispatch')->resolves(function () use (&$calls): void { $calls[] = 'closed'; });
        $handler = Double::for(OnCloseInterface::class)->strict();
        $handler->expects('onClose')->resolves(function () use (&$calls, $fail): void {
            defer(function () use (&$calls): void { $calls[] = 'deferred'; });
            defer(function () use (&$calls): void { $calls[] = WebSocketContext::get('value'); })->always();
            if ($fail) {
                throw new RuntimeException('close failed');
            }
        });
        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->allows('make')->with(OnCloseInterface::class)->returns($handler);
        FdCollector::set(1, OnCloseInterface::class);
        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('value', 'context');

        (new Server($container->instance()))->onClose(Double::for(SwooleServer::class)->strict(), 1, 0);

        $this->assertSame($fail ? ['closed', 'context'] : ['closed', 'deferred', 'context'], $calls);
        $this->assertNull(WebSocketContext::get('value', fd: 1));
        $this->assertNull(FdCollector::get(1));
    }

    /**
     * Supply ordinary callback outcomes.
     */
    public static function callbackFailures(): array
    {
        return [[false], [true]];
    }

    public function testCancellationDuringDeferredWorkStillReleasesConnectionContext(): void
    {
        $calls = [];
        $handler = Double::for(OnCloseInterface::class)->strict();
        $handler->expects('onClose')->resolves(function () use (&$calls): void {
            defer(static fn () => throw new CanceledException);
            defer(function () use (&$calls): void { $calls[] = 'later'; })->always();
        });
        $container = $this->createContainer();
        $container->allows('make')->with(OnCloseInterface::class)->returns($handler);
        FdCollector::set(1, OnCloseInterface::class);
        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('value', 'context');

        (new Server($container->instance()))->onClose(Double::for(SwooleServer::class)->strict(), 1, 0);

        $this->assertSame([], $calls);
        $this->assertNull(WebSocketContext::get('value', fd: 1));
        $this->assertNull(FdCollector::get(1));
    }

    public function testMessageLifecycleEventsAreDispatchedInOrder(): void
    {
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof MessageReceived && $event->fd === 1 && $event->server === 'websocket'
        ))->ordered();
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof MessageHandled && WebSocketMessageStub::$messageHandled
                && $event->fd === 1
                && $event->frame->data === 'test'
                && $event->server === 'websocket'
                && $event->exception === null
        ))->ordered();

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container->instance());
        $swooleServer = Double::for(WebSocketSwooleServer::class)->strict();

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = 'test';

        $server->onMessage($swooleServer, $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageReceivedEventNotDispatchedWithoutListeners(): void
    {
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(false);
        $dispatcher->expects('dispatch')->never();

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container->instance());
        $swooleServer = Double::for(WebSocketSwooleServer::class)->strict();

        $frame = new Frame;
        $frame->fd = 1;
        $frame->data = 'test';

        $server->onMessage($swooleServer, $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandlerRunsWhenMessageReceivedEventThrows(): void
    {
        $exception = new RuntimeException('event failed');

        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->with($exception);

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(MessageReceived::class))->throws($exception);
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof MessageHandled && WebSocketMessageStub::$messageHandled
                && $event->exception === $exception
        ));

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    #[TestWith([false], 'direct')]
    #[TestWith([true], 'wrapped')]
    public function testMessageReceivedCancellationSkipsTheHandlerAndCompletesWithTheCancellation(bool $wrapped): void
    {
        $cancellation = $wrapped
            ? new RuntimeException('received cleanup failed', previous: new CanceledException)
            : new CanceledException;
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->times($wrapped ? 1 : 0)->with($cancellation);
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(MessageReceived::class))->throws($cancellation);
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof MessageHandled && $event->exception === $cancellation
        ));

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertFalse(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandlerFailuresAreReportedWithoutEscaping(): void
    {
        $exception = new RuntimeException('message failed');
        WebSocketMessageStub::$messageException = $exception;

        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('error')->never();

        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->with($exception);

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof MessageHandled && $event->exception === $exception
        ));

        $container = $this->createContainer($logger, $dispatcher, $exceptionHandler);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandledKeepsTheFirstFailure(): void
    {
        $receivedException = new RuntimeException('event failed');
        $messageException = new RuntimeException('message failed');
        WebSocketMessageStub::$messageException = $messageException;

        $reported = [];
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->times(2)->resolves(
            function (Throwable $throwable) use (&$reported): void {
                $reported[] = $throwable;
            }
        );

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(MessageReceived::class))->throws($receivedException);
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof MessageHandled && $event->exception === $receivedException
        ));

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertSame([$receivedException, $messageException], $reported);
    }

    #[TestWith([false], 'without an earlier failure')]
    #[TestWith([true], 'after a received failure')]
    public function testMessageHandlerCancellationCompletesWithTheKnownOutcome(bool $receivedFails): void
    {
        $cancellation = new CanceledException;
        $receivedFailure = new RuntimeException('received failed');
        WebSocketMessageStub::$messageException = $cancellation;

        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->times($receivedFails ? 1 : 0)->with($receivedFailure);

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns($receivedFails);
        if ($receivedFails) {
            $dispatcher->expects('dispatch')->with(Argument::type(MessageReceived::class))->throws($receivedFailure);
        }
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof MessageHandled && $event->exception === ($receivedFails ? $receivedFailure : $cancellation)
        ));

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    #[TestWith([false], 'ordinary failure')]
    #[TestWith([true], 'cancellation')]
    public function testCanceledMessageCompletionContainsListenerFailures(bool $cancel): void
    {
        WebSocketMessageStub::$messageException = new CanceledException;
        $listenerFailure = $cancel ? new CanceledException : new RuntimeException('completion failed');

        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->times($cancel ? 0 : 1)->with($listenerFailure);

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(MessageHandled::class))->throws($listenerFailure);

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandledFailureIsReportedWithoutEscaping(): void
    {
        $exception = new RuntimeException('completion failed');

        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->with($exception);

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(MessageHandled::class))->throws($exception);

        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testMessageHandledCancellationIsContained(): void
    {
        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('error')->never();

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->expects('hasListeners')->with(MessageReceived::class)->returns(false);
        $dispatcher->expects('hasListeners')->with(MessageHandled::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(MessageHandled::class))->throws(new CanceledException);

        $container = $this->createContainer($logger, $dispatcher);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        FdCollector::set(1, WebSocketMessageStub::class);

        $frame = new Frame;
        $frame->fd = 1;

        (new Server($container->instance()))->onMessage(Double::for(WebSocketSwooleServer::class)->strict(), $frame);

        $this->assertTrue(WebSocketMessageStub::$messageHandled);
    }

    public function testConnectionLifecycleEventsAreDispatchedAroundTheCloseHandler(): void
    {
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionClosing::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof ConnectionClosing && ! WebSocketMessageStub::$closeHandled
                && $event->fd === 1
                && $event->reactorId === 0
                && $event->server === 'websocket'
        ))->ordered();
        $dispatcher->allows('hasListeners')->with(ConnectionClosed::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::satisfies(
            fn (mixed $event): bool => $event instanceof ConnectionClosed && WebSocketMessageStub::$closeHandled
                && $event->fd === 1
                && $event->reactorId === 0
                && $event->server === 'websocket'
        ))->ordered();

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container->instance());
        $swooleServer = Double::for(SwooleServer::class)->strict();

        $server->onClose($swooleServer, 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
    }

    public function testConnectionClosedEventNotDispatchedWithoutListeners(): void
    {
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionClosing::class)->returns(false);
        $dispatcher->allows('hasListeners')->with(ConnectionClosed::class)->returns(false);
        $dispatcher->expects('dispatch')->never();

        $container = $this->createContainer(dispatcher: $dispatcher);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);

        FdCollector::set(1, WebSocketMessageStub::class);

        $server = new Server($container->instance());
        $swooleServer = Double::for(SwooleServer::class)->strict();

        $server->onClose($swooleServer, 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
    }

    public function testConnectionClosingFailureDoesNotSkipCloseCallbacksOrCleanup(): void
    {
        $exception = new RuntimeException('closing event failed');
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->expects('report')->with($exception);
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionClosing::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(ConnectionClosing::class))
            ->throws($exception);
        $dispatcher->allows('hasListeners')->with(ConnectionClosed::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(ConnectionClosed::class));
        $container = $this->createContainer(
            dispatcher: $dispatcher,
            exceptionHandler: $exceptionHandler,
        );
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);
        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container->instance()))->onClose(Double::for(SwooleServer::class)->strict(), 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    #[TestWith([false], 'direct')]
    #[TestWith([true], 'wrapped')]
    public function testConnectionClosingCancellationSkipsCloseCallbacksButCompletesAndCleansUp(bool $wrapped): void
    {
        $cancellation = $wrapped
            ? new RuntimeException('closing cleanup failed', previous: new CanceledException)
            : new CanceledException;
        $listenerFailure = new RuntimeException('closed listener failed');
        $reported = [];
        $exceptionHandler = Double::for(ExceptionHandlerContract::class)->strict();
        $exceptionHandler->allows('report')->resolves(function (Throwable $throwable) use (&$reported): void {
            $reported[] = $throwable;
        });
        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionClosing::class)->returns(true);
        $dispatcher->expects('dispatch')
            ->with(Argument::type(ConnectionClosing::class))
            ->throws($cancellation);
        $dispatcher->allows('hasListeners')->with(ConnectionClosed::class)->returns(true);
        $dispatcher->expects('dispatch')->with(Argument::type(ConnectionClosed::class))->throws($listenerFailure);
        $container = $this->createContainer(dispatcher: $dispatcher, exceptionHandler: $exceptionHandler);
        $container->expects('make')->never()->with(WebSocketMessageStub::class);
        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container->instance()))->onClose(Double::for(SwooleServer::class)->strict(), 1, 0);

        $this->assertSame($wrapped ? [$cancellation, $listenerFailure] : [$listenerFailure], $reported);
        $this->assertFalse(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testCloseWithoutCollectorStillReleasesConnectionContext(): void
    {
        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');

        (new Server($this->createContainer()->instance()))->onClose(Double::for(SwooleServer::class)->strict(), 1, 0);

        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testCloseHandlerFailureDoesNotSkipEventOrCleanup(): void
    {
        WebSocketMessageStub::$closeException = new RuntimeException('close failed');

        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('error')->with(Argument::type('string'));

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionClosing::class)->returns(false);
        $dispatcher->allows('hasListeners')->with(ConnectionClosed::class)->returns(true);
        $dispatcher->expects('dispatch');

        $container = $this->createContainer($logger, $dispatcher);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);

        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container->instance()))->onClose(Double::for(SwooleServer::class)->strict(), 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testCloseEventFailureDoesNotSkipCleanup(): void
    {
        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('error')->with(Argument::type('string'));

        $dispatcher = Double::for(EventDispatcherContract::class)->strict();
        $dispatcher->allows('hasListeners')->with(ConnectionClosing::class)->returns(false);
        $dispatcher->allows('hasListeners')->with(ConnectionClosed::class)->returns(true);
        $dispatcher->expects('dispatch')->throws(new RuntimeException('event failed'));

        $container = $this->createContainer($logger, $dispatcher);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);

        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container->instance()))->onClose(Double::for(SwooleServer::class)->strict(), 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testCloseCancellationIsContainedAfterCleanup(): void
    {
        WebSocketMessageStub::$closeException = new CanceledException;

        $logger = Double::for(StdoutLoggerInterface::class);
        $logger->expects('error')->never();

        $container = $this->createContainer($logger);
        $container->allows('make')->with(WebSocketMessageStub::class)->returns(new WebSocketMessageStub);

        CoroutineContext::set(WebSocketContext::FD, 1);
        WebSocketContext::set('connection.id', 'one');
        FdCollector::set(1, WebSocketMessageStub::class);

        (new Server($container->instance()))->onClose(Double::for(SwooleServer::class)->strict(), 1, 0);

        $this->assertTrue(WebSocketMessageStub::$closeHandled);
        $this->assertNull(FdCollector::get(1));
        $this->assertArrayNotHasKey(1, WebSocketContext::getStorage());
    }

    public function testExceptionHandlerResponseSuppressesHandshakeException(): void
    {
        $original = new RuntimeException('handshake failed');
        $response = new Response('Unauthorized', Response::HTTP_UNAUTHORIZED);
        $handler = Double::for(WebSocketExceptionHandler::class)->strict();
        $handler->expects('handle')
            ->with($original, Argument::type(Response::class))
            ->returns($response);

        $container = $this->createContainer();
        $container->expects('make')
            ->with(WebSocketExceptionHandler::class)
            ->returns($handler);

        $this->assertSame(
            $response,
            (new ClassInvoker(new Server($container->instance())))->handleException($original)
        );
    }

    public function testExceptionHandlerFailureRetainsHandshakeException(): void
    {
        $original = new RuntimeException('handshake failed');
        $handlingFailure = new RuntimeException('exception handler failed');
        $handler = Double::for(WebSocketExceptionHandler::class)->strict();
        $handler->expects('handle')
            ->with($original, Argument::type(Response::class))
            ->throws($handlingFailure);

        $container = $this->createContainer();
        $container->expects('make')
            ->with(WebSocketExceptionHandler::class)
            ->returns($handler);

        try {
            (new ClassInvoker(new Server($container->instance())))->handleException($original);
            $this->fail('Expected exception handling to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($handlingFailure, $exception);
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    /**
     * Wait for a child coroutine and its deferred callbacks to finish.
     */
    private function waitForCoroutine(int $coroutineId, float $timeout = 1.0): void
    {
        $deadline = hrtime(true) + (int) ($timeout * 1_000_000_000);

        while (Coroutine::exists($coroutineId)) {
            if (hrtime(true) >= $deadline) {
                $this->fail("Coroutine {$coroutineId} did not finish within {$timeout} seconds.");
            }

            usleep(1_000);
        }
    }

    /**
     * Create a container mock with logger and optional event dispatcher.
     */
    protected function createContainer(
        ?StdoutLoggerInterface $logger = null,
        ?EventDispatcherContract $dispatcher = null,
        ?ExceptionHandlerContract $exceptionHandler = null,
    ): OverriddenDouble {
        $logger ??= Double::for(StdoutLoggerInterface::class);

        $container = Double::for(Container::class, override: true)->strict();
        $container->allows('make')->with(StdoutLoggerInterface::class)->returns($logger);

        if ($exceptionHandler !== null) {
            $container->allows('make')
                ->with(ExceptionHandlerContract::class)
                ->returns($exceptionHandler);
        }

        if ($dispatcher) {
            $container->allows('bound')->with('events')->returns(true);
            $container->allows('make')->with('events')->returns($dispatcher);
        } else {
            $container->allows('bound')->with('events')->returns(false);
        }

        return $container;
    }

    // REMOVED: testEngineServer — Tests FooServer::getServer() which accepts Swow\Http\Server\Connection in a type union. Pure Swow/coroutine-server test, not applicable to Swoole-only mode.
}
