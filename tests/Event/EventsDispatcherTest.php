<?php

declare(strict_types=1);

namespace Hypervel\Tests\Event;

use Error;
use Exception;
use Hypervel\Contracts\Event\ShouldDispatchAfterCommit;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Event\EventDispatcher;
use Hypervel\Event\ListenerProvider;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use Psr\Container\ContainerInterface;

/**
 * @internal
 * @coversNothing
 */
class EventsDispatcherTest extends TestCase
{
    /**
     * @var ContainerInterface|MockInterface
     */
    private ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = m::mock(ContainerInterface::class);
    }

    public function testBasicEventExecution()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen('foo', function ($foo) {
            $_SERVER['__event.test'] = $foo;
        });

        $this->assertEquals('foo', $d->dispatch('foo', ['bar']));
        $this->assertSame('bar', $_SERVER['__event.test']);

        // we can still add listeners after the event has fired
        $d->listen('foo', function ($foo) {
            $_SERVER['__event.test'] .= $foo;
        });

        $d->dispatch('foo', ['bar']);
        $this->assertSame('barbar', $_SERVER['__event.test']);
    }

    public function testDeferEventExecution()
    {
        unset($_SERVER['__event.test']);
        $d = $this->getEventDispatcher();
        $d->listen('foo', function ($foo) {
            $_SERVER['__event.test'] = $foo;
        });

        $result = $d->defer(function () use ($d) {
            $d->dispatch('foo', ['bar']);
            $this->assertArrayNotHasKey('__event.test', $_SERVER);

            return 'callback_result';
        });

        $this->assertEquals('callback_result', $result);
        $this->assertSame('bar', $_SERVER['__event.test']);
    }

    public function testDeferMultipleEvents()
    {
        $_SERVER['__event.test'] = [];
        $d = $this->getEventDispatcher();
        $d->listen('foo', function ($value) {
            $_SERVER['__event.test'][] = $value;
        });
        $d->listen('bar', function ($value) {
            $_SERVER['__event.test'][] = $value;
        });
        $d->defer(function () use ($d) {
            $d->dispatch('foo', ['foo']);
            $d->dispatch('bar', ['bar']);
            $this->assertSame([], $_SERVER['__event.test']);
        });

        $this->assertSame(['foo', 'bar'], $_SERVER['__event.test']);
    }

    public function testDeferNestedEvents()
    {
        $_SERVER['__event.test'] = [];
        $d = $this->getEventDispatcher();
        $d->listen('foo', function ($foo) {
            $_SERVER['__event.test'][] = $foo;
        });

        $d->defer(function () use ($d) {
            $d->dispatch('foo', ['outer1']);

            $d->defer(function () use ($d) {
                $d->dispatch('foo', ['inner']);
                $this->assertSame([], $_SERVER['__event.test']);
            });

            $this->assertSame(['inner'], $_SERVER['__event.test']);
            $d->dispatch('foo', ['outer2']);
        });

        $this->assertSame(['inner', 'outer1', 'outer2'], $_SERVER['__event.test']);
    }

    public function testDeferSpecificEvents()
    {
        $_SERVER['__event.test'] = [];
        $d = $this->getEventDispatcher();

        $d->listen('foo', function ($foo) {
            $_SERVER['__event.test'][] = $foo;
        });

        $d->listen('bar', function ($bar) {
            $_SERVER['__event.test'][] = $bar;
        });

        $d->defer(function () use ($d) {
            $d->dispatch('foo', ['deferred']);
            $d->dispatch('bar', ['immediate']);

            $this->assertSame(['immediate'], $_SERVER['__event.test']);
        }, ['foo']);

        $this->assertSame(['immediate', 'deferred'], $_SERVER['__event.test']);
    }

    public function testDeferSpecificNestedEvents()
    {
        $_SERVER['__event.test'] = [];
        $d = $this->getEventDispatcher();

        $d->listen('foo', function ($foo) {
            $_SERVER['__event.test'][] = $foo;
        });

        $d->listen('bar', function ($bar) {
            $_SERVER['__event.test'][] = $bar;
        });

        $d->defer(function () use ($d) {
            $d->dispatch('foo', ['outer-deferred']);
            $d->dispatch('bar', ['outer-immediate']);

            $this->assertSame(['outer-immediate'], $_SERVER['__event.test']);

            $d->defer(function () use ($d) {
                $d->dispatch('foo', ['inner-deferred']);
                $d->dispatch('bar', ['inner-immediate']);

                $this->assertSame(['outer-immediate', 'inner-immediate'], $_SERVER['__event.test']);
            }, ['foo']);

            $this->assertSame(['outer-immediate', 'inner-immediate', 'inner-deferred'], $_SERVER['__event.test']);
        }, ['foo']);

        $this->assertSame(['outer-immediate', 'inner-immediate', 'inner-deferred', 'outer-deferred'], $_SERVER['__event.test']);
    }

    public function testDeferSpecificObjectEvents()
    {
        $_SERVER['__event.test'] = [];
        $d = $this->getEventDispatcher();

        $d->listen(DeferTestEvent::class, function () {
            $_SERVER['__event.test'][] = 'DeferTestEvent';
        });

        $d->listen(ImmediateTestEvent::class, function () {
            $_SERVER['__event.test'][] = 'ImmediateTestEvent';
        });

        $d->defer(function () use ($d) {
            $d->dispatch(new DeferTestEvent());
            $d->dispatch(new ImmediateTestEvent());

            $this->assertSame(['ImmediateTestEvent'], $_SERVER['__event.test']);
        }, [DeferTestEvent::class]);

        $this->assertSame(['ImmediateTestEvent', 'DeferTestEvent'], $_SERVER['__event.test']);
    }

    public function testHaltingEventExecution()
    {
        $d = $this->getEventDispatcher();
        $d->listen('foo', function () {
            $this->assertTrue(true);

            return 'halted';
        });
        $d->listen('foo', function () {
            throw new Exception('should not be called');
        });

        // With halt=true, returns first non-null response
        $response = $d->dispatch('foo', ['bar'], true);
        $this->assertEquals('halted', $response);

        $response = $d->until('foo', ['bar']);
        $this->assertEquals('halted', $response);
    }

    public function testResponseWhenNoListenersAreSet()
    {
        $d = $this->getEventDispatcher();

        $response = $d->dispatch('foo');
        $this->assertEquals('foo', $response);

        $response = $d->dispatch('foo', [], true);
        $this->assertEquals('foo', $response);
    }

    public function testReturningFalseStopsPropagation()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen('foo', function ($foo) {
            return $foo;
        });
        $d->listen('foo', function ($foo) {
            $_SERVER['__event.test'] = $foo;

            return false;
        });
        $d->listen('foo', function () {
            throw new Exception('should not be called');
        });

        $response = $d->dispatch('foo', ['bar']);

        $this->assertSame('bar', $_SERVER['__event.test']);
        $this->assertEquals('foo', $response);
    }

    public function testReturningFalsyValuesContinuesPropagation()
    {
        $d = $this->getEventDispatcher();
        $d->listen('foo', function () {
            return 0;
        });
        $d->listen('foo', function () {
            return [];
        });
        $d->listen('foo', function () {
            return '';
        });
        $d->listen('foo', function () {});

        $response = $d->dispatch('foo', ['bar']);

        $this->assertEquals('foo', $response);
    }

    public function testContainerResolutionOfEventHandlers()
    {
        unset($_SERVER['__event.test']);

        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestEventListener::class)
            ->andReturn(new TestEventListener());

        $d = $this->getEventDispatcher();
        $d->listen('foo', TestEventListener::class . '@onFooEvent');

        $response = $d->dispatch('foo', ['foo', 'bar']);

        $this->assertSame('foo', $_SERVER['__event.test']);
        $this->assertEquals('foo', $response);
    }

    public function testContainerResolutionOfEventHandlersWithDefaultMethods()
    {
        unset($_SERVER['__event.test']);

        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestEventListener::class)
            ->andReturn(new TestEventListener());

        $d = $this->getEventDispatcher();
        $d->listen('foo', TestEventListener::class);

        $response = $d->dispatch('foo', ['foo', 'bar']);

        $this->assertSame('bar', $_SERVER['__event.test']);
        $this->assertEquals('foo', $response);
    }

    public function testQueuedEventsAreFired()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen('update', function ($name) {
            $_SERVER['__event.test'] = $name;
        });
        $d->push('update', ['name' => 'taylor']);
        $d->listen('update', function ($name) {
            $_SERVER['__event.test'] .= '_' . $name;
        });

        $this->assertFalse(isset($_SERVER['__event.test']));
        $d->flush('update');
        $d->listen('update', function ($name) {
            $_SERVER['__event.test'] .= $name;
        });
        $this->assertSame('taylor_taylor', $_SERVER['__event.test']);
    }

    public function testQueuedEventsCanBeForgotten()
    {
        $_SERVER['__event.test'] = 'unset';

        $d = $this->getEventDispatcher();
        $d->push('update', ['name' => 'taylor']);
        $d->listen('update', function ($name) {
            $_SERVER['__event.test'] = $name;
        });

        $d->forgetPushed();
        $d->flush('update');
        $this->assertSame('unset', $_SERVER['__event.test']);
    }

    public function testMultiplePushedEventsWillGetFlushed()
    {
        $_SERVER['__event.test'] = '';

        $d = $this->getEventDispatcher();
        $d->push('update', ['name' => 'taylor ']);
        $d->push('update', ['name' => 'otwell']);
        $d->listen('update', function ($name) {
            $_SERVER['__event.test'] .= $name;
        });

        $d->flush('update');
        $this->assertSame('taylor otwell', $_SERVER['__event.test']);
    }

    public function testPushMethodCanAcceptObjectAsPayload()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->push(ExampleEvent::class, $e = new ExampleEvent());
        $d->listen(ExampleEvent::class, function ($payload) {
            $_SERVER['__event.test'] = $payload;
        });

        $d->flush(ExampleEvent::class);

        $this->assertSame($e, $_SERVER['__event.test']);
    }

    public function testWildcardListeners()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen('foo.bar', function () {
            $_SERVER['__event.test'][] = 'regular';
        });
        $d->listen('foo.*', function () {
            $_SERVER['__event.test'][] = 'wildcard';
        });
        $d->listen('bar.*', function () {
            $_SERVER['__event.test'][] = 'nope';
        });
        $d->listen('*', function () {
            $_SERVER['__event.test'][] = 'global_wildcard';
        });

        $response = $d->dispatch('foo.bar');

        $this->assertEquals('foo.bar', $response);
        $this->assertSame(['regular', 'wildcard', 'global_wildcard'], $_SERVER['__event.test']);
    }

    public function testWildcardListenersWithEventResponse()
    {
        unset($_SERVER['__event.test']);
        $d = $this->getEventDispatcher();
        $d->listen('foo.bar', function () {
            return 'regular';
        });

        $response = $d->dispatch('foo.bar');

        $this->assertEquals('foo.bar', $response);
    }

    public function testWildcardListenersCacheFlushing()
    {
        unset($_SERVER['__event.test']);
        $d = $this->getEventDispatcher();
        $d->listen('foo.*', function () {
            $_SERVER['__event.test'] = 'cached_wildcard';
        });
        $d->dispatch('foo.bar');
        $this->assertSame('cached_wildcard', $_SERVER['__event.test']);

        $d->listen('foo.*', function () {
            $_SERVER['__event.test'] = 'new_wildcard';
        });
        $d->dispatch('foo.bar');
        $this->assertSame('new_wildcard', $_SERVER['__event.test']);
    }

    public function testListenersCanBeRemoved()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen('foo', function () {
            $_SERVER['__event.test'] = 'foo';
        });
        $d->forget('foo');
        $d->dispatch('foo');

        $this->assertFalse(isset($_SERVER['__event.test']));
    }

    public function testWildcardListenersCanBeRemoved()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen('foo.*', function () {
            $_SERVER['__event.test'] = 'foo';
        });
        $d->forget('foo.*');
        $d->dispatch('foo.bar');

        $this->assertFalse(isset($_SERVER['__event.test']));
    }

    public function testWildcardCacheIsClearedWhenListenersAreRemoved()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen('foo*', function () {
            $_SERVER['__event.test'] = 'foo';
        });
        $d->dispatch('foo');

        $this->assertSame('foo', $_SERVER['__event.test']);

        unset($_SERVER['__event.test']);

        $d->forget('foo*');
        $d->dispatch('foo');

        $this->assertFalse(isset($_SERVER['__event.test']));
    }

    public function testHasWildcardListeners()
    {
        $d = $this->getEventDispatcher();

        $d->listen('foo', 'listener1');
        $this->assertFalse($d->hasWildcardListeners('foo'));

        $d->listen('foo*', 'listener1');
        $this->assertTrue($d->hasWildcardListeners('foo'));
    }

    public function testListenersCanBeFound()
    {
        $d = $this->getEventDispatcher();

        $this->assertFalse($d->hasListeners('foo'));

        $d->listen('foo', function () {});
        $this->assertTrue($d->hasListeners('foo'));
    }

    public function testWildcardListenersCanBeFound()
    {
        $d = $this->getEventDispatcher();

        $this->assertFalse($d->hasListeners('foo.*'));

        $d->listen('foo.*', function () {});
        $this->assertTrue($d->hasListeners('foo.*'));
        $this->assertTrue($d->hasListeners('foo.bar'));
    }

    public function testClassesWork()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen(ExampleEvent::class, function () {
            $_SERVER['__event.test'] = 'baz';
        });
        $d->dispatch(new ExampleEvent());

        $this->assertSame('baz', $_SERVER['__event.test']);
    }

    public function testClassesWorkWithAnonymousListeners()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen(function (ExampleEvent $event) {
            $_SERVER['__event.test'] = 'qux';
        });
        $d->dispatch(new ExampleEvent());

        $this->assertSame('qux', $_SERVER['__event.test']);
    }

    public function testEventClassesArePayload()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen(ExampleEvent::class, function ($payload) {
            $_SERVER['__event.test'] = $payload;
        });
        $d->dispatch($e = new ExampleEvent(), ['foo']);

        $this->assertSame($e, $_SERVER['__event.test']);
    }

    public function testInterfacesWork()
    {
        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen(SomeEventInterface::class, function () {
            $_SERVER['__event.test'] = 'bar';
        });
        $d->dispatch(new AnotherEvent());

        $this->assertSame('bar', $_SERVER['__event.test']);
    }

    public function testBothClassesAndInterfacesWork()
    {
        unset($_SERVER['__event.test'], $_SERVER['__event.test1'], $_SERVER['__event.test2']);

        $_SERVER['__event.test'] = [];

        $d = $this->getEventDispatcher();
        $d->listen(AnotherEvent::class, function ($p) {
            $_SERVER['__event.test'][] = $p;
            $_SERVER['__event.test1'] = 'fooo';
        });
        $d->listen(SomeEventInterface::class, function ($p) {
            $_SERVER['__event.test'][] = $p;
            $_SERVER['__event.test2'] = 'baar';
        });
        $d->dispatch($e = new AnotherEvent(), ['foo']);

        $this->assertSame($e, $_SERVER['__event.test'][0]);
        $this->assertSame($e, $_SERVER['__event.test'][1]);
        $this->assertSame('fooo', $_SERVER['__event.test1']);
        $this->assertSame('baar', $_SERVER['__event.test2']);
    }

    public function testNestedEvent()
    {
        $_SERVER['__event.test'] = [];

        $d = $this->getEventDispatcher();

        $d->listen('event', function () use ($d) {
            $d->listen('event', function () {
                $_SERVER['__event.test'][] = 'fired 1';
            });
            $d->listen('event', function () {
                $_SERVER['__event.test'][] = 'fired 2';
            });
        });

        $d->dispatch('event');
        $this->assertSame([], $_SERVER['__event.test']);
        $d->dispatch('event');
        $this->assertEquals(['fired 1', 'fired 2'], $_SERVER['__event.test']);
    }

    public function testDuplicateListenersWillFire()
    {
        TestListener::$counter = 0;

        $this->container
            ->shouldReceive('get')
            ->times(4)
            ->with(TestListener::class)
            ->andReturn(new TestListener());

        $d = $this->getEventDispatcher();
        $d->listen('event', TestListener::class);
        $d->listen('event', TestListener::class);
        $d->listen('event', TestListener::class . '@handle');
        $d->listen('event', TestListener::class . '@handle');
        $d->dispatch('event');

        $this->assertEquals(4, TestListener::$counter);
    }

    public function testGetListeners()
    {
        $d = $this->getEventDispatcher();
        $d->listen(ExampleEvent::class, 'Listener1');
        $d->listen(ExampleEvent::class, 'Listener2');
        $listeners = $d->getListeners(ExampleEvent::class);
        $this->assertCount(2, $listeners);

        $d->listen(ExampleEvent::class, 'Listener3');
        $listeners = $d->getListeners(ExampleEvent::class);
        $this->assertCount(3, $listeners);
    }

    public function testListenersObjectsCreationOrder()
    {
        $_SERVER['__event.test'] = [];

        $this->container
            ->shouldReceive('get')
            ->twice()
            ->with(TestListener1::class)
            ->andReturnUsing(fn () => new TestListener1());
        $this->container
            ->shouldReceive('get')
            ->twice()
            ->with(TestListener2::class)
            ->andReturnUsing(fn () => new TestListener2());
        $this->container
            ->shouldReceive('get')
            ->twice()
            ->with(TestListener3::class)
            ->andReturnUsing(fn () => new TestListener3());

        $d = $this->getEventDispatcher();
        $d->listen(TestEvent::class, TestListener1::class);
        $d->listen(TestEvent::class, TestListener2::class);
        $d->listen(TestEvent::class, TestListener3::class);

        // Attaching events does not make any objects.
        $this->assertEquals([], $_SERVER['__event.test']);

        $d->dispatch(TestEvent::class);

        // Dispatching event does not make an object of the event class.
        $this->assertEquals([
            'cons-1',
            'handle-1',
            'cons-2',
            'handle-2',
            'cons-3',
            'handle-3',
        ], $_SERVER['__event.test']);

        $d->dispatch(TestEvent::class);

        // Event Objects are re-resolved on each dispatch. (No memoization)
        $this->assertEquals([
            'cons-1',
            'handle-1',
            'cons-2',
            'handle-2',
            'cons-3',
            'handle-3',
            'cons-1',
            'handle-1',
            'cons-2',
            'handle-2',
            'cons-3',
            'handle-3',
        ], $_SERVER['__event.test']);

        unset($_SERVER['__event.test']);
    }

    public function testListenerObjectCreationIsLazy()
    {
        $this->container
            ->shouldReceive('get')
            ->twice()
            ->with(TestListener1::class)
            ->andReturnUsing(fn () => new TestListener1());
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestListener2Falser::class)
            ->andReturnUsing(fn () => new TestListener2Falser());
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestListener2::class)
            ->andReturnUsing(fn () => new TestListener2());

        $d = $this->getEventDispatcher();
        $d->listen(TestEvent::class, TestListener1::class);
        $d->listen(TestEvent::class, TestListener2Falser::class);
        $d->listen(TestEvent::class, TestListener3::class);
        $d->listen(ExampleEvent::class, TestListener2::class);

        $_SERVER['__event.test'] = [];
        $d->dispatch(ExampleEvent::class);

        // It only resolves relevant listeners not all.
        $this->assertEquals(['cons-2', 'handle-2'], $_SERVER['__event.test']);

        $_SERVER['__event.test'] = [];
        $d->dispatch(TestEvent::class);

        $this->assertEquals([
            'cons-1',
            'handle-1',
            'cons-2-falser',
            'handle-2-falser',
        ], $_SERVER['__event.test']);

        unset($_SERVER['__event.test']);

        $d = $this->getEventDispatcher();
        $d->listen(TestEvent::class, TestListener1::class);
        $d->listen(TestEvent::class, TestListener2Falser::class);
        $d->listen(TestEvent::class, TestListener3::class);

        $_SERVER['__event.test'] = [];
        $d->dispatch(TestEvent::class, halt: true);

        $this->assertEquals([
            'cons-1',
            'handle-1',
        ], $_SERVER['__event.test']);

        unset($_SERVER['__event.test']);
    }

    public function testInvokeIsCalled()
    {
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestListenerInvokeHandler::class)
            ->andReturnUsing(fn () => new TestListenerInvokeHandler());
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestListenerInvoke::class)
            ->andReturnUsing(fn () => new TestListenerInvoke());
        $this->container
            ->shouldReceive('get')
            ->once()
            ->with(TestListenerLean::class)
            ->andReturnUsing(fn () => new TestListenerLean());

        // Only "handle" is called when both "handle" and "__invoke" exist on listener.
        $_SERVER['__event.test'] = [];
        $d = $this->getEventDispatcher();
        $d->listen('myEvent', TestListenerInvokeHandler::class);
        $d->dispatch('myEvent');
        $this->assertEquals(['__construct', 'handle'], $_SERVER['__event.test']);

        // "__invoke" is called when there is no handle.
        $_SERVER['__event.test'] = [];
        $d = $this->getEventDispatcher();
        $d->listen('myEvent', TestListenerInvoke::class);
        $d->listen('myEvent', TestListenerInvokeHandler::class);
        $d->dispatch('myEvent', 'somePayload');
        $this->assertEquals(['__construct', '__invoke_somePayload'], $_SERVER['__event.test']);

        // It throws an "Error" when there is no method to be called.
        $d = $this->getEventDispatcher();
        $d->listen('myEvent', TestListenerLean::class);

        $this->expectException(Error::class);
        $this->expectExceptionMessage(EventDispatcher::class . '::createClassCallable(): Return value must be of type callable, array returned');

        $d->dispatch('myEvent', 'somePayload');

        unset($_SERVER['__event.test']);
    }

    public function testGetRawListeners()
    {
        $d = $this->getEventDispatcher();

        // Register some listeners
        $d->listen('event1', 'Listener1');
        $d->listen('event2', 'Listener2');
        $d->listen('event3', 'Listener3');

        // Get raw listeners
        $rawListeners = $d->getRawListeners();

        // Assert that the raw listeners are as expected (now returns raw strings, not ListenerData)
        $this->assertArrayHasKey('event1', $rawListeners);
        $this->assertArrayHasKey('event2', $rawListeners);
        $this->assertArrayHasKey('event3', $rawListeners);
        $this->assertSame('Listener1', $rawListeners['event1'][0]);
        $this->assertSame('Listener2', $rawListeners['event2'][0]);
        $this->assertSame('Listener3', $rawListeners['event3'][0]);
    }

    public function testDispatchWithAfterCommit()
    {
        $transactionResolver = m::mock(DatabaseTransactionsManager::class);
        $transactionResolver
            ->shouldReceive('addCallback')
            ->once();

        $d = $this->getEventDispatcher();
        $d->setTransactionManagerResolver(fn () => $transactionResolver);

        $listenerTriggered = false;
        $d->listen(AfterCommitEvent::class, function ($foo) use (&$listenerTriggered) {
            $listenerTriggered = true;
        });

        $d->dispatch(new AfterCommitEvent());

        $this->assertFalse($listenerTriggered);
    }

    private function getEventDispatcher(): EventDispatcher
    {
        return new EventDispatcher(new ListenerProvider(), null, $this->container);
    }
}

class TestListenerLean
{
}

class TestListenerInvokeHandler
{
    public function __construct()
    {
        $_SERVER['__event.test'][] = '__construct';
    }

    public function __invoke()
    {
        $_SERVER['__event.test'][] = '__invoke';
    }

    public function handle()
    {
        $_SERVER['__event.test'][] = 'handle';
    }
}

class TestListenerInvoke
{
    public function __construct()
    {
        $_SERVER['__event.test'][] = '__construct';
    }

    public function __invoke($payload)
    {
        $_SERVER['__event.test'][] = '__invoke_' . $payload;

        return false;
    }
}

class ExampleEvent
{
}

interface SomeEventInterface
{
}

class AnotherEvent implements SomeEventInterface
{
}

class AfterCommitEvent implements ShouldDispatchAfterCommit
{
}

class TestEventListener
{
    public function onFooEvent($foo, $bar)
    {
        $_SERVER['__event.test'] = $foo;

        return 'baz';
    }

    public function handle($foo, $bar)
    {
        $_SERVER['__event.test'] = $bar;

        return 'baz';
    }
}

class TestListener
{
    public static $counter = 0;

    public function handle()
    {
        ++self::$counter;
    }
}

class TestEvent
{
    public function __construct()
    {
        $_SERVER['__event.test'][] = 'cons-event-1';
    }
}

class TestListener1
{
    public function __construct()
    {
        $_SERVER['__event.test'][] = 'cons-1';
    }

    public function handle()
    {
        $_SERVER['__event.test'][] = 'handle-1';

        return 'resp-1';
    }
}

class TestListener2
{
    public function __construct()
    {
        $_SERVER['__event.test'][] = 'cons-2';
    }

    public function handle()
    {
        $_SERVER['__event.test'][] = 'handle-2';

        return 'resp-2';
    }
}

class TestListener2Falser
{
    public function __construct()
    {
        $_SERVER['__event.test'][] = 'cons-2-falser';
    }

    public function handle()
    {
        $_SERVER['__event.test'][] = 'handle-2-falser';

        return false;
    }
}

class TestListener3
{
    public function __construct()
    {
        $_SERVER['__event.test'][] = 'cons-3';
    }

    public function handle()
    {
        $_SERVER['__event.test'][] = 'handle-3';
    }
}

class DeferTestEvent
{
}

class ImmediateTestEvent
{
}
