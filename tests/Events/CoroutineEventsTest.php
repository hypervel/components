<?php

declare(strict_types=1);

namespace Hypervel\Tests\Events\CoroutineEventsTest;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class CoroutineEventsTest extends TestCase
{
    public function testDeferredEventsAreCoroutineIsolated()
    {
        $dispatcher = new Dispatcher;
        $results = [];

        $dispatcher->listen('event-a', function () use (&$results) {
            $results[] = 'a-dispatched';
        });

        $dispatcher->listen('event-b', function () use (&$results) {
            $results[] = 'b-dispatched';
        });

        // Track whether events were correctly deferred (not dispatched during callback)
        $aDeferredDuringCallback = null;
        $bDeferredDuringCallback = null;

        parallel([
            // Coroutine 1: defer event-a, sleep to let coroutine 2 run, then complete
            function () use ($dispatcher, &$results, &$aDeferredDuringCallback) {
                $dispatcher->defer(function () use ($dispatcher, &$results, &$aDeferredDuringCallback) {
                    $dispatcher->dispatch('event-a');

                    // Event should be deferred, not dispatched yet
                    $aDeferredDuringCallback = ! in_array('a-dispatched', $results, true);

                    usleep(10000); // 10ms — let coroutine 2 start its defer
                });

                // After defer() completes, event-a should have been dispatched
                $results[] = 'coroutine-1-done';
            },
            // Coroutine 2: defer event-b independently
            function () use ($dispatcher, &$results, &$bDeferredDuringCallback) {
                usleep(5000); // 5ms — start after coroutine 1 enters defer

                $dispatcher->defer(function () use ($dispatcher, &$results, &$bDeferredDuringCallback) {
                    $dispatcher->dispatch('event-b');

                    // Event should be deferred, not dispatched yet
                    $bDeferredDuringCallback = ! in_array('b-dispatched', $results, true);
                });

                // After defer() completes, event-b should have been dispatched
                $results[] = 'coroutine-2-done';
            },
        ]);

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
        $dispatcher = new Dispatcher;
        $coroutine1Events = [];
        $coroutine2Events = [];

        $dispatcher->listen('shared-event', function (string $source) use (&$coroutine1Events, &$coroutine2Events) {
            if ($source === 'coroutine-1') {
                $coroutine1Events[] = 'shared-event';
            } elseif ($source === 'coroutine-2') {
                $coroutine2Events[] = 'shared-event';
            }
        });

        parallel([
            // Coroutine 1: defer and dispatch with source=coroutine-1
            function () use ($dispatcher) {
                $dispatcher->defer(function () use ($dispatcher) {
                    $dispatcher->dispatch('shared-event', ['coroutine-1']);
                    usleep(15000); // 15ms — hold open while coroutine 2 finishes defer
                });
            },
            // Coroutine 2: defer and dispatch with source=coroutine-2
            function () use ($dispatcher) {
                usleep(5000); // 5ms delay
                $dispatcher->defer(function () use ($dispatcher) {
                    $dispatcher->dispatch('shared-event', ['coroutine-2']);
                });
            },
        ]);

        // Each coroutine should have dispatched its own event independently
        $this->assertCount(1, $coroutine1Events);
        $this->assertCount(1, $coroutine2Events);
    }

    public function testPushedEventsDoNotLeakBetweenCoroutines()
    {
        $dispatcher = new Dispatcher;
        $flushed = [];

        $dispatcher->listen('shared-event', function (string $source) use (&$flushed) {
            $flushed[] = $source;
        });

        parallel([
            function () use ($dispatcher) {
                $dispatcher->push('shared-event', ['coroutine-1']);

                usleep(10000);

                $dispatcher->flush('shared-event');
            },
            function () use ($dispatcher) {
                usleep(5000);

                $dispatcher->push('shared-event', ['coroutine-2']);
            },
        ]);

        $this->assertSame(['coroutine-1'], $flushed);
    }

    public function testForgetPushedDoesNotClearOtherCoroutinePushedEvents()
    {
        $dispatcher = new Dispatcher;
        $flushed = [];

        $dispatcher->listen('shared-event', function (string $source) use (&$flushed) {
            $flushed[] = $source;
        });

        parallel([
            function () use ($dispatcher) {
                $dispatcher->push('shared-event', ['coroutine-1']);

                usleep(10000);

                $dispatcher->flush('shared-event');
            },
            function () use ($dispatcher) {
                usleep(5000);

                $dispatcher->push('shared-event', ['coroutine-2']);
                $dispatcher->forgetPushed();
            },
        ]);

        $this->assertSame(['coroutine-1'], $flushed);
    }

    public function testContextKeysAreCleanedUpAfterDeferCompletes()
    {
        $dispatcher = new Dispatcher;

        $dispatcher->listen('test-event', function () {
            // no-op
        });

        // Before defer, no deferred event state should exist
        $this->assertFalse(CoroutineContext::get(Dispatcher::DEFERRING_CONTEXT_KEY, false));
        $this->assertSame([], CoroutineContext::get(Dispatcher::DEFERRED_EVENTS_CONTEXT_KEY, []));
        $this->assertNull(CoroutineContext::get(Dispatcher::EVENTS_TO_DEFER_CONTEXT_KEY));

        $dispatcher->defer(function () use ($dispatcher) {
            // Inside defer, state should be active
            $this->assertTrue(CoroutineContext::get(Dispatcher::DEFERRING_CONTEXT_KEY, false));

            $dispatcher->dispatch('test-event');

            // Deferred events should be collected
            $this->assertNotEmpty(CoroutineContext::get(Dispatcher::DEFERRED_EVENTS_CONTEXT_KEY, []));
        });

        // After defer completes, state should be restored to pre-defer values
        $this->assertFalse(CoroutineContext::get(Dispatcher::DEFERRING_CONTEXT_KEY, false));
        $this->assertSame([], CoroutineContext::get(Dispatcher::DEFERRED_EVENTS_CONTEXT_KEY, []));
        $this->assertNull(CoroutineContext::get(Dispatcher::EVENTS_TO_DEFER_CONTEXT_KEY));
    }

    public function testContextKeysAreCleanedUpAfterDeferThrowsException()
    {
        $dispatcher = new Dispatcher;

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
        $this->assertFalse(CoroutineContext::get(Dispatcher::DEFERRING_CONTEXT_KEY, false));
        $this->assertSame([], CoroutineContext::get(Dispatcher::DEFERRED_EVENTS_CONTEXT_KEY, []));
        $this->assertNull(CoroutineContext::get(Dispatcher::EVENTS_TO_DEFER_CONTEXT_KEY));
    }

    public function testNestedDeferRestoresOuterStateAfterInnerCompletes()
    {
        $dispatcher = new Dispatcher;
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

    public function testPreparedListenersAreSharedAcrossCoroutines(): void
    {
        $dispatcher = new CoroutinePreparationCountingDispatcher;
        $dispatcher->listen('test-event', function () {
            return 'listener-1';
        });

        [$first, $second] = parallel([
            static fn (): array => $dispatcher->getListeners('test-event'),
            static fn (): array => $dispatcher->getListeners('test-event'),
        ]);

        $this->assertSame($first[0], $second[0]);
        $this->assertSame(1, $dispatcher->listenerPreparationCount);
    }

    public function testPreparedListenerBucketIsInvalidatedOnListen(): void
    {
        $dispatcher = new CoroutinePreparationCountingDispatcher;
        $dispatcher->listen('test-event', function () {
            return 'listener-1';
        });

        $dispatcher->getListeners('test-event');
        $this->assertSame(1, $dispatcher->listenerPreparationCount);

        $dispatcher->listen('test-event', function () {
            return 'listener-2';
        });

        $listeners = $dispatcher->getListeners('test-event');
        $this->assertCount(2, $listeners);
        $this->assertSame(3, $dispatcher->listenerPreparationCount);
    }

    public function testPreparedListenerBucketIsRemovedOnForget(): void
    {
        $dispatcher = new CoroutinePreparationCountingDispatcher;
        $dispatcher->listen('test-event', function () {
            return 'listener-1';
        });

        $dispatcher->getListeners('test-event');
        $dispatcher->forget('test-event');

        $this->assertSame([], $dispatcher->getListeners('test-event'));
        $this->assertSame(1, $dispatcher->listenerPreparationCount);
    }

    public function testPreparedWildcardListenerBucketIsInvalidatedOnListen(): void
    {
        $dispatcher = new CoroutinePreparationCountingDispatcher;
        $dispatcher->listen('test-*', function () {
            return 'listener-1';
        });

        $first = $dispatcher->getListeners('test-first');
        $this->assertSame(1, $dispatcher->listenerPreparationCount);

        $dispatcher->listen('test-*', function () {
            return 'listener-2';
        });

        $second = $dispatcher->getListeners('test-second');
        $third = $dispatcher->getListeners('test-third');

        $this->assertCount(1, $first);
        $this->assertCount(2, $second);
        $this->assertSame($second[0], $third[0]);
        $this->assertSame($second[1], $third[1]);
        $this->assertSame(3, $dispatcher->listenerPreparationCount);
    }
}

class CoroutinePreparationCountingDispatcher extends Dispatcher
{
    public int $listenerPreparationCount = 0;

    public function makeListener(array|object|string $listener, bool $wildcard = false): Closure
    {
        ++$this->listenerPreparationCount;

        return parent::makeListener($listener, $wildcard);
    }
}
