<?php

declare(strict_types=1);

namespace Hypervel\Tests\Events\CoroutineEventsTest;

use Hypervel\Context\Context;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Events\Dispatcher;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use ReflectionClass;
use RuntimeException;

use function Hypervel\Coroutine\go;

/**
 * @internal
 * @coversNothing
 */
class CoroutineEventsTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testDeferredEventsAreCoroutineIsolated()
    {
        $dispatcher = new Dispatcher();
        $results = [];

        $dispatcher->listen('event-a', function () use (&$results) {
            $results[] = 'a-dispatched';
        });

        $dispatcher->listen('event-b', function () use (&$results) {
            $results[] = 'b-dispatched';
        });

        $waiter = new WaitGroup();

        // Track whether events were correctly deferred (not dispatched during callback)
        $aDeferredDuringCallback = null;
        $bDeferredDuringCallback = null;

        // Coroutine 1: defer event-a, sleep to let coroutine 2 run, then complete
        $waiter->add(1);
        go(function () use ($dispatcher, &$results, &$aDeferredDuringCallback, $waiter) {
            $dispatcher->defer(function () use ($dispatcher, &$results, &$aDeferredDuringCallback) {
                $dispatcher->dispatch('event-a');

                // Event should be deferred, not dispatched yet
                $aDeferredDuringCallback = ! in_array('a-dispatched', $results, true);

                usleep(10000); // 10ms — let coroutine 2 start its defer
            });

            // After defer() completes, event-a should have been dispatched
            $results[] = 'coroutine-1-done';
            $waiter->done();
        });

        // Coroutine 2: defer event-b independently
        $waiter->add(1);
        go(function () use ($dispatcher, &$results, &$bDeferredDuringCallback, $waiter) {
            usleep(5000); // 5ms — start after coroutine 1 enters defer

            $dispatcher->defer(function () use ($dispatcher, &$results, &$bDeferredDuringCallback) {
                $dispatcher->dispatch('event-b');

                // Event should be deferred, not dispatched yet
                $bDeferredDuringCallback = ! in_array('b-dispatched', $results, true);
            });

            // After defer() completes, event-b should have been dispatched
            $results[] = 'coroutine-2-done';
            $waiter->done();
        });

        $waiter->wait();

        // Events were correctly deferred inside their respective callbacks
        $this->assertTrue($aDeferredDuringCallback, 'event-a should have been deferred during callback');
        $this->assertTrue($bDeferredDuringCallback, 'event-b should have been deferred during callback');

        // Both events should have been dispatched after their respective defers completed
        $this->assertContains('a-dispatched', $results);
        $this->assertContains('b-dispatched', $results);
        $this->assertContains('coroutine-1-done', $results);
        $this->assertContains('coroutine-2-done', $results);
    }

    public function testDeferredEventsDoNotLeakBetweenCoroutines()
    {
        $dispatcher = new Dispatcher();
        $coroutine1Events = [];
        $coroutine2Events = [];

        $dispatcher->listen('shared-event', function (string $source) use (&$coroutine1Events, &$coroutine2Events) {
            if ($source === 'coroutine-1') {
                $coroutine1Events[] = 'shared-event';
            } elseif ($source === 'coroutine-2') {
                $coroutine2Events[] = 'shared-event';
            }
        });

        $waiter = new WaitGroup();

        // Coroutine 1: defer and dispatch with source=coroutine-1
        $waiter->add(1);
        go(function () use ($dispatcher, $waiter) {
            $dispatcher->defer(function () use ($dispatcher) {
                $dispatcher->dispatch('shared-event', ['coroutine-1']);
                usleep(15000); // 15ms — hold open while coroutine 2 finishes defer
            });
            $waiter->done();
        });

        // Coroutine 2: defer and dispatch with source=coroutine-2
        $waiter->add(1);
        go(function () use ($dispatcher, $waiter) {
            usleep(5000); // 5ms delay
            $dispatcher->defer(function () use ($dispatcher) {
                $dispatcher->dispatch('shared-event', ['coroutine-2']);
            });
            $waiter->done();
        });

        $waiter->wait();

        // Each coroutine should have dispatched its own event independently
        $this->assertCount(1, $coroutine1Events);
        $this->assertCount(1, $coroutine2Events);
    }

    public function testContextKeysAreCleanedUpAfterDeferCompletes()
    {
        $dispatcher = new Dispatcher();

        $dispatcher->listen('test-event', function () {
            // no-op
        });

        // Before defer, no deferred event state should exist
        $this->assertFalse(Context::get('__events.deferring', false));
        $this->assertSame([], Context::get('__events.deferred_events', []));
        $this->assertNull(Context::get('__events.events_to_defer'));

        $dispatcher->defer(function () use ($dispatcher) {
            // Inside defer, state should be active
            $this->assertTrue(Context::get('__events.deferring', false));

            $dispatcher->dispatch('test-event');

            // Deferred events should be collected
            $this->assertNotEmpty(Context::get('__events.deferred_events', []));
        });

        // After defer completes, state should be restored to pre-defer values
        $this->assertFalse(Context::get('__events.deferring', false));
        $this->assertSame([], Context::get('__events.deferred_events', []));
        $this->assertNull(Context::get('__events.events_to_defer'));
    }

    public function testContextKeysAreCleanedUpAfterDeferThrowsException()
    {
        $dispatcher = new Dispatcher();

        $dispatcher->listen('test-event', function () {
            // no-op
        });

        try {
            $dispatcher->defer(function () use ($dispatcher) {
                $dispatcher->dispatch('test-event');

                throw new RuntimeException('Test exception');
            });

            $this->fail('Exception should have been thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Test exception', $e->getMessage());
        }

        // After exception, state should be restored to pre-defer values
        $this->assertFalse(Context::get('__events.deferring', false));
        $this->assertSame([], Context::get('__events.deferred_events', []));
        $this->assertNull(Context::get('__events.events_to_defer'));
    }

    public function testNestedDeferRestoresOuterStateAfterInnerCompletes()
    {
        $dispatcher = new Dispatcher();
        $dispatched = [];

        $dispatcher->listen('outer-event', function () use (&$dispatched) {
            $dispatched[] = 'outer';
        });
        $dispatcher->listen('inner-event', function () use (&$dispatched) {
            $dispatched[] = 'inner';
        });

        $dispatcher->defer(function () use ($dispatcher, &$dispatched) {
            $dispatcher->dispatch('outer-event');

            // outer-event should be deferred
            $this->assertNotContains('outer', $dispatched);

            $dispatcher->defer(function () use ($dispatcher, &$dispatched) {
                $dispatcher->dispatch('inner-event');

                // inner-event should be deferred
                $this->assertNotContains('inner', $dispatched);
            });

            // After inner defer completes, inner-event should be dispatched
            $this->assertContains('inner', $dispatched);

            // But outer-event should still be deferred (outer defer hasn't completed)
            $this->assertNotContains('outer', $dispatched);
        });

        // After outer defer completes, both events should be dispatched
        $this->assertContains('outer', $dispatched);
        $this->assertContains('inner', $dispatched);
    }

    public function testListenersCacheIsPopulatedOnFirstGetListenersCall()
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen('test-event', function () {
            return 'listener-1';
        });

        // Access the protected listenersCache via reflection
        $reflection = new ReflectionClass($dispatcher);
        $cacheProperty = $reflection->getProperty('listenersCache');

        // Cache should be empty before getListeners()
        $this->assertEmpty($cacheProperty->getValue($dispatcher));

        // First call should populate the cache
        $listeners = $dispatcher->getListeners('test-event');
        $this->assertNotEmpty($listeners);

        $cache = $cacheProperty->getValue($dispatcher);
        $this->assertArrayHasKey('test-event', $cache);
        $this->assertCount(count($listeners), $cache['test-event']);

        // Second call should return same result from cache
        $listeners2 = $dispatcher->getListeners('test-event');
        $this->assertSame($listeners, $listeners2);
    }

    public function testListenersCacheIsInvalidatedOnListen()
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen('test-event', function () {
            return 'listener-1';
        });

        $reflection = new ReflectionClass($dispatcher);
        $cacheProperty = $reflection->getProperty('listenersCache');

        // Populate the cache
        $dispatcher->getListeners('test-event');
        $this->assertNotEmpty($cacheProperty->getValue($dispatcher));

        // Adding a new listener should invalidate the cache
        $dispatcher->listen('test-event', function () {
            return 'listener-2';
        });

        $this->assertEmpty($cacheProperty->getValue($dispatcher));

        // New call should include both listeners
        $listeners = $dispatcher->getListeners('test-event');
        $this->assertCount(2, $listeners);
    }

    public function testListenersCacheIsInvalidatedOnForget()
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen('test-event', function () {
            return 'listener-1';
        });

        $reflection = new ReflectionClass($dispatcher);
        $cacheProperty = $reflection->getProperty('listenersCache');

        // Populate the cache
        $dispatcher->getListeners('test-event');
        $this->assertNotEmpty($cacheProperty->getValue($dispatcher));

        // Forgetting the event should invalidate the cache
        $dispatcher->forget('test-event');

        $this->assertEmpty($cacheProperty->getValue($dispatcher));

        // New call should return empty listeners
        $listeners = $dispatcher->getListeners('test-event');
        $this->assertEmpty($listeners);
    }

    public function testListenersCacheIsInvalidatedOnWildcardListen()
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen('test-event', function () {
            return 'listener-1';
        });

        $reflection = new ReflectionClass($dispatcher);
        $cacheProperty = $reflection->getProperty('listenersCache');

        // Populate the cache
        $dispatcher->getListeners('test-event');
        $this->assertNotEmpty($cacheProperty->getValue($dispatcher));

        // Adding a wildcard listener should invalidate the cache
        $dispatcher->listen('test-*', function () {
            return 'wildcard';
        });

        $this->assertEmpty($cacheProperty->getValue($dispatcher));

        // New call should include original + wildcard listener
        $listeners = $dispatcher->getListeners('test-event');
        $this->assertCount(2, $listeners);
    }
}
