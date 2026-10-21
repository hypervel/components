<?php

declare(strict_types=1);

namespace Hypervel\Tests\Log;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Engine\Channel;
use Hypervel\Events\Dispatcher;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Log\Logger;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class LogLoggerTest extends TestCase
{
    public function testMethodsPassErrorAdditionsToMonolog(): void
    {
        $monolog = $this->mockMonolog();
        $monolog->expects('isHandling')->with('error')->andReturn(true);
        $monolog->expects('error')->with('foo', []);
        $writer = new Logger($monolog);

        $writer->error('foo');
    }

    public function testContextIsAddedToAllSubsequentLogs(): void
    {
        $monolog = $this->mockMonolog();
        $writer = new Logger($monolog);
        $writer->withContext(['bar' => 'baz']);

        $monolog->expects('isHandling')->with('error')->andReturn(true);
        $monolog->expects('error')->with('foo', ['bar' => 'baz']);

        $writer->error('foo');
    }

    public function testNumericContextKeysArePreservedAndOverridden(): void
    {
        $handler = new TestHandler;
        $writer = new Logger(new Monolog('test', [$handler], [new PsrLogMessageProcessor]));
        $writer->withContext(['123' => 'first', '456' => 'kept']);
        $writer->withContext(['123' => 'updated']);

        $writer->info('{123}/{456}', ['123' => 'override']);

        $record = $handler->getRecords()[0];
        $this->assertSame('override/kept', $record->message);
        $this->assertSame([123 => 'override', 456 => 'kept'], $record->context);
        $this->assertSame([123 => 'updated', 456 => 'kept'], $writer->getContext());
    }

    public function testContextIsFlushed(): void
    {
        $monolog = $this->mockMonolog();
        $writer = new Logger($monolog);
        $writer->withContext(['bar' => 'baz']);
        $writer->withoutContext();

        $monolog->expects('isHandling')->with('error')->andReturn(true);
        $monolog->expects('error')->with('foo', []);

        $writer->error('foo');
    }

    public function testContextKeysCanBeRemovedForSubsequentLogs(): void
    {
        $monolog = $this->mockMonolog();
        $writer = new Logger($monolog);
        $writer->withContext(['bar' => 'baz', 'forget' => 'me']);
        $writer->withoutContext(['forget']);

        $monolog->expects('isHandling')->with('error')->andReturn(true);
        $monolog->expects('error')->with('foo', ['bar' => 'baz']);

        $writer->error('foo');
    }

    public function testLoggerFiresEventsDispatcher(): void
    {
        $monolog = $this->mockMonolog();
        $monolog->expects('isHandling')->with('error')->andReturn(true);
        $monolog->expects('error')->with('foo', []);
        $writer = new Logger($monolog, $events = new Dispatcher);

        $context = [];

        $events->listen(MessageLogged::class, function (MessageLogged $event) use (&$context): void {
            $context['level'] = $event->level;
            $context['message'] = $event->message;
            $context['event_context'] = $event->context;
        });

        $writer->error('foo');
        $this->assertTrue(isset($context['level']));
        $this->assertSame('error', $context['level']);
        $this->assertTrue(isset($context['message']));
        $this->assertSame('foo', $context['message']);
        $this->assertTrue(isset($context['event_context']));
        $this->assertSame([], $context['event_context']);
    }

    public function testListenShortcutFailsWithNoDispatcher(): void
    {
        $this->expectExceptionObject(new RuntimeException('Events dispatcher has not been set.'));

        $writer = new Logger($this->mockMonolog());
        $writer->listen(function (): void {
        });
    }

    public function testListenShortcut(): void
    {
        $events = m::mock(DispatcherContract::class);
        $writer = new Logger($this->mockMonolog(), $events);

        $callback = function (): string {
            return 'success';
        };
        $events->expects('listen')->with(MessageLogged::class, $callback);

        $writer->listen($callback);
    }

    public function testComplexContextManipulation(): void
    {
        $monolog = $this->mockMonolog();
        $writer = new Logger($monolog);

        $writer->withContext(['user_id' => 123, 'action' => 'login']);
        $writer->withContext(['ip' => '127.0.0.1', 'timestamp' => '1986-10-29']);
        $writer->withoutContext(['timestamp']);

        $monolog->expects('isHandling')->with('info')->andReturn(true);
        $monolog->expects('info')->with('User action', [
            'user_id' => 123,
            'action' => 'login',
            'ip' => '127.0.0.1',
        ]);

        $writer->info('User action');
    }

    public function testSkipsSerializationWhenLogLevelNotHandled(): void
    {
        $monolog = new Monolog('test');
        $monolog->pushHandler(new TestHandler(Level::Error));

        $writer = new Logger($monolog);

        $arrayable = new class implements Arrayable {
            public bool $wasCalled = false;

            /**
             * Get the instance as an array.
             */
            public function toArray(): array
            {
                $this->wasCalled = true;

                return ['serialized' => 'data'];
            }
        };

        $writer->debug($arrayable);

        $this->assertFalse($arrayable->wasCalled);
    }

    public function testSerializesWhenLogLevelIsHandled(): void
    {
        $monolog = new Monolog('test');
        $handler = new TestHandler(Level::Debug);
        $monolog->pushHandler($handler);

        $writer = new Logger($monolog);

        $arrayable = new class implements Arrayable {
            public bool $wasCalled = false;

            /**
             * Get the instance as an array.
             */
            public function toArray(): array
            {
                $this->wasCalled = true;

                return ['serialized' => 'data'];
            }
        };

        $writer->debug($arrayable);

        $this->assertTrue($arrayable->wasCalled);
        $this->assertTrue($handler->hasDebugRecords());
    }

    public function testNamedLoggerPreservesWrapperBehaviorAndSharesChannelContext(): void
    {
        $monolog = new Monolog('base');
        $handler = new TestHandler(Level::Debug);
        $monolog->pushHandler($handler);

        $events = new Dispatcher;
        $writer = new Logger($monolog, $events);
        $writer->withContext(['request_id' => 'request-1']);

        $named = $writer->withName('tenant');

        $this->assertNotSame($writer, $named);
        $this->assertNotSame($writer->getLogger(), $named->getLogger());
        $this->assertSame('base', $monolog->getName());
        $this->assertSame('tenant', $named->getLogger()->getName());
        $this->assertSame($events, $named->getEventDispatcher());
        $this->assertSame(['request_id' => 'request-1'], $named->getContext());

        $named->withContext(['tenant_id' => 42]);

        $this->assertSame(['request_id' => 'request-1', 'tenant_id' => 42], $writer->getContext());

        $event = null;
        $events->listen(MessageLogged::class, function (MessageLogged $message) use (&$event): void {
            $event = $message;
        });

        $named->info('hello');

        $this->assertSame('tenant', $handler->getRecords()[0]->channel);
        $this->assertSame(['request_id' => 'request-1', 'tenant_id' => 42], $event->context);

        $other = new Logger(new Monolog('other'));
        $this->assertSame([], $other->getContext());
    }

    public function testDestroyedLoggerContextCannotAliasANewLogger(): void
    {
        $monolog = new Monolog('shared');
        $first = new Logger($monolog);
        $firstObjectId = spl_object_id($first);
        $first->withContext(['request_id' => 'first']);

        unset($first);

        $second = new Logger($monolog);

        $this->assertSame($firstObjectId, spl_object_id($second));
        $this->assertSame([], $second->getContext());
    }

    public function testConcurrentLogsDoNotTriggerMonologsSharedLoopDetector(): void
    {
        $release = new Channel(3);
        $handler = new InterleavingLogHandler($release);
        $logger = new Logger(new Monolog('concurrent', [$handler]));

        parallel([
            fn (): null => $logger->info('first'),
            fn (): null => $logger->info('second'),
            fn (): null => $logger->info('third'),
        ]);

        $messages = array_map(
            static fn (LogRecord $record): string => $record->message,
            $handler->records
        );
        sort($messages);

        $this->assertSame(['first', 'second', 'third'], $messages);
    }

    public function testClearingContextDuringAWritePreservesLoopDetection(): void
    {
        $writer = null;
        $handler = new ContextClearingRecursiveLogHandler(function () use (&$writer): void {
            $writer->withoutContext();
            $writer->info('nested');
        });
        $writer = new Logger(new Monolog('recursive', [$handler]));
        $writer->withContext(['request_id' => 'request-1']);

        $writer->info('initial');

        $this->assertTrue($handler->loopWarningEmitted);
        $this->assertSame([], $writer->getContext());
    }

    public function testMessageLoggedListenerRecursionUsesLoopDetection(): void
    {
        $handler = new TestHandler;
        $events = new Dispatcher;
        $writer = new Logger(new Monolog('recursive', [$handler]), $events);
        $remainingRecursions = 6;

        $events->listen(MessageLogged::class, function () use ($writer, &$remainingRecursions): void {
            if ($remainingRecursions-- > 0) {
                $writer->info('nested');
            }
        });

        $writer->info('initial');

        $this->assertTrue($handler->hasWarningThatContains('infinite logging loop'));
    }

    public function testNamedLoggerRejectsNonMonologDrivers(): void
    {
        $this->expectExceptionObject(new RuntimeException('Named loggers are only supported by Monolog drivers.'));

        (new Logger(m::mock(LoggerInterface::class)))->withName('tenant');
    }

    // -- Hypervel-specific tests --

    public function testWithContext(): void
    {
        $writer = new Logger($monolog = $this->mockMonolog());

        $writer->withContext(['foo' => 'bar']);
        $writer->withContext(['baz' => 'qux']);

        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->expects('error')->with('test message', ['foo' => 'bar', 'baz' => 'qux']);

        $writer->error('test message');
    }

    public function testLoggerSkipsEventDispatchWhenNoListenersAreRegistered(): void
    {
        $writer = new Logger($monolog = $this->mockMonolog(), $events = m::mock(DispatcherContract::class));
        $monolog->shouldReceive('isHandling')->with('error')->andReturn(true);
        $monolog->expects('error')->with('foo', []);
        $events->expects('hasListeners')->with(MessageLogged::class)->andReturn(false);
        $events->shouldNotReceive('dispatch');

        $writer->error('foo');
    }

    public function testMessageLoggedExtraContainsVisibleContextAndExcludesHidden(): void
    {
        $events = new Dispatcher;
        $repository = new ContextRepository($events);
        $repository->add('trace_id', 'abc-123');
        $repository->addHidden('api_key', 'secret-token');
        CoroutineContext::set(ContextRepository::CONTEXT_KEY, $repository);

        $writer = new Logger($monolog = $this->mockMonolog(), $events);
        $monolog->shouldReceive('isHandling')->with('info')->andReturn(true);
        $monolog->expects('info')->with('test', []);

        $captured = null;
        $events->listen(MessageLogged::class, function (MessageLogged $event) use (&$captured): void {
            $captured = $event;
        });

        $writer->info('test');

        $this->assertSame(['trace_id' => 'abc-123'], $captured->extra);
        $this->assertArrayNotHasKey('api_key', $captured->extra);

        CoroutineContext::forget(ContextRepository::CONTEXT_KEY);
    }

    public function testMessageLoggedExtraIsEmptyWhenNoContextUsed(): void
    {
        CoroutineContext::forget(ContextRepository::CONTEXT_KEY);

        $events = new Dispatcher;
        $writer = new Logger($monolog = $this->mockMonolog(), $events);
        $monolog->shouldReceive('isHandling')->with('info')->andReturn(true);
        $monolog->expects('info')->with('test', []);

        $captured = null;
        $events->listen(MessageLogged::class, function (MessageLogged $event) use (&$captured): void {
            $captured = $event;
        });

        $writer->info('test');

        $this->assertSame([], $captured->extra);
    }

    /**
     * Create a Monolog mock expecting its loop detector to be disabled.
     */
    private function mockMonolog(): Monolog
    {
        $monolog = m::mock(Monolog::class);
        $monolog->expects('useLoggingLoopDetection')->with(false);

        return $monolog;
    }
}

class InterleavingLogHandler extends AbstractHandler
{
    /** @var list<LogRecord> */
    public array $records = [];

    private int $entered = 0;

    /**
     * Create a handler that interleaves concurrent writes.
     */
    public function __construct(
        private readonly Channel $release
    ) {
        parent::__construct();
    }

    /**
     * Record a message and wait for the other writes.
     */
    public function handle(LogRecord $record): bool
    {
        $this->records[] = $record;

        if (++$this->entered === 3) {
            $this->release->push(true);
            $this->release->push(true);
            $this->release->push(true);
        }

        $this->release->pop();

        return false;
    }
}

class ContextClearingRecursiveLogHandler extends AbstractHandler
{
    public bool $loopWarningEmitted = false;

    private int $remainingRecursions = 6;

    /**
     * Create a handler that recursively logs messages.
     */
    public function __construct(
        private readonly Closure $recurse
    ) {
        parent::__construct();
    }

    /**
     * Recurse until the logger detects the loop.
     */
    public function handle(LogRecord $record): bool
    {
        if ($record->level === Level::Warning && str_contains($record->message, 'infinite logging loop')) {
            $this->loopWarningEmitted = true;

            return false;
        }

        if ($this->remainingRecursions-- > 0) {
            ($this->recurse)();
        }

        return false;
    }
}
