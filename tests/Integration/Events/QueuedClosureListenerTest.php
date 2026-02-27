<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Events\QueuedClosureListenerTest;

use Hypervel\Events\CallQueuedListener;
use Hypervel\Events\InvokeQueuedClosure;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;
use Laravel\SerializableClosure\SerializableClosure;

/**
 * @internal
 * @coversNothing
 */
class QueuedClosureListenerTest extends TestCase
{
    public function testAnonymousQueuedListenerIsQueued()
    {
        Bus::fake();

        Event::listen(\Hypervel\Events\queueable(function (TestEvent $event) {
        })->catch(function (TestEvent $event) {
        })->onConnection(null)->onQueue(null));

        Event::dispatch(new TestEvent());

        Bus::assertDispatched(CallQueuedListener::class, function ($job) {
            return $job->class === InvokeQueuedClosure::class;
        });
    }

    public function testAnonymousQueuedListenerIsQueuedOnMessageGroup()
    {
        $messageGroup = 'group-1';

        Bus::fake();

        Event::listen(\Hypervel\Events\queueable(function (TestEvent $event) {
        })->catch(function (TestEvent $event) {
        })->onConnection(null)->onQueue(null)->onGroup($messageGroup));

        Event::dispatch(new TestEvent());

        Bus::assertDispatched(CallQueuedListener::class, function ($job) use ($messageGroup) {
            return $job->messageGroup === $messageGroup;
        });
    }

    public function testAnonymousQueuedListenerIsQueuedWithDeduplicator()
    {
        $deduplicator = fn ($payload, $queue) => 'deduplicator-1';

        Bus::fake();

        Event::listen(\Hypervel\Events\queueable(function (TestEvent $event) {
        })->catch(function (TestEvent $event) {
        })->onConnection(null)->onQueue(null)->withDeduplicator($deduplicator));

        Event::dispatch(new TestEvent());

        Bus::assertDispatched(CallQueuedListener::class, function ($job) {
            $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);

            return is_callable($job->deduplicator) && call_user_func($job->deduplicator, '', null) === 'deduplicator-1';
        });
    }
}

class TestEvent
{
}
