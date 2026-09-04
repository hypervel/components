<?php

declare(strict_types=1);

namespace Hypervel\Tests\Events\QueuedEventsTest;

use Exception;
use Hypervel\Bus\DebounceLock;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Cache\Store as CacheStore;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Contracts\Queue\Job;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Contracts\Queue\ShouldBeUnique;
use Hypervel\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\CallQueuedListener;
use Hypervel\Events\Dispatcher;
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\DebounceFor;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\CallQueuedHandler;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Tests\TestCase;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use Mockery as m;

class QueuedEventsTest extends TestCase
{
    public function testQueuedEventHandlersAreQueued()
    {
        $d = new Dispatcher;
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);

        $factory->shouldReceive('connection')->once()->with(null)->andReturn($queue);
        $queue->shouldReceive('pushOn')->once()->with(null, m::type(CallQueuedListener::class));

        $d->setQueueResolver(function () use ($factory) {
            return $factory;
        });

        $d->listen('some.event', TestDispatcherQueuedHandler::class . '@someMethod');
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testQueuedEnumEventsRemainSerializable(): void
    {
        $dispatcher = new Dispatcher;
        $queue = new QueueFake(new Container);

        $dispatcher->setQueueResolver(fn () => $queue);
        $dispatcher->listen(QueuedEvent::class, TestDispatcherQueuedHandler::class . '@handle');
        $dispatcher->dispatch(QueuedEvent::Created);

        $queue->assertPushed(CallQueuedListener::class, function (CallQueuedListener $job): bool {
            $clone = clone $job;

            $this->assertSame(QueuedEvent::Created, $clone->data[0]);
            $this->assertIsString(serialize($clone));

            return true;
        });
    }

    public function testCustomizedQueuedEventHandlersAreQueued()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherConnectionQueuedHandler::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushedOn('my_queue', CallQueuedListener::class);
    }

    public function testQueueIsSetByGetQueue()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherGetQueue::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushedOn('some_other_queue', CallQueuedListener::class);
    }

    public function testQueueIsSetByGetConnection()
    {
        $d = new Dispatcher;
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);

        $factory->shouldReceive('connection')->once()->with('some_other_connection')->andReturn($queue);
        $queue->shouldReceive('pushOn')->once()->with(null, m::type(CallQueuedListener::class));

        $d->setQueueResolver(function () use ($factory) {
            return $factory;
        });

        $d->listen('some.event', TestDispatcherGetConnection::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testEnumQueueAndConnectionResultsAreNormalizedBeforeDispatch(): void
    {
        $dispatcher = new Dispatcher;
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);

        $factory->shouldReceive('connection')->once()->with('0')->andReturn($queue);
        $queue->shouldReceive('pushOn')->once()->with('1', m::type(CallQueuedListener::class));

        $dispatcher->setQueueResolver(fn () => $factory);
        $dispatcher->listen('some.event', TestDispatcherGetEnumQueueAndConnection::class . '@handle');
        $dispatcher->dispatch('some.event', ['foo', 'bar']);
    }

    public function testDelayIsSetByWithDelay()
    {
        $d = new Dispatcher;
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);

        $factory->shouldReceive('connection')->once()->with(null)->andReturn($queue);
        $queue->shouldReceive('laterOn')->once()->with(null, 20, m::type(CallQueuedListener::class));

        $d->setQueueResolver(function () use ($factory) {
            return $factory;
        });

        $d->listen('some.event', TestDispatcherGetDelay::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testDelayIsSetByAttribute(): void
    {
        $d = new Dispatcher;
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);

        $factory->shouldReceive('connection')->once()->with(null)->andReturn($queue);
        $queue->shouldReceive('laterOn')->once()->with(null, 30, m::type(CallQueuedListener::class));

        $d->setQueueResolver(function () use ($factory) {
            return $factory;
        });

        $d->listen('some.event', TestDispatcherGetDelayFromAttribute::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testWithDelayOverridesDelayAttribute(): void
    {
        $d = new Dispatcher;
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);

        $factory->shouldReceive('connection')->once()->with(null)->andReturn($queue);
        $queue->shouldReceive('laterOn')->once()->with(null, 20, m::type(CallQueuedListener::class));

        $d->setQueueResolver(function () use ($factory) {
            return $factory;
        });

        $d->listen('some.event', TestDispatcherGetDelayMethodOverridesAttribute::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);
    }

    public function testQueueIsSetByGetQueueDynamically()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherGetQueueDynamically::class . '@handle');
        $d->dispatch('some.event', [['useHighPriorityQueue' => true], 'bar']);

        $fakeQueue->assertPushedOn('p0', CallQueuedListener::class);
    }

    public function testQueueIsSetByGetConnectionDynamically()
    {
        $d = new Dispatcher;
        $queueManager = $this->createMock(QueueManager::class);
        $queue = $this->createMock(Queue::class);

        $queueManager->expects($this->once())
            ->method('connection')
            ->with('redis')
            ->willReturn($queue);

        $queue->expects($this->once())
            ->method('pushOn')
            ->with(null, $this->isInstanceOf(CallQueuedListener::class));

        $d->setQueueResolver(function () use ($queueManager) {
            return $queueManager;
        });

        $d->listen('some.event', TestDispatcherGetConnectionDynamically::class . '@handle');
        $d->dispatch('some.event', [
            ['shouldUseRedisConnection' => true],
            'bar',
        ]);
    }

    public function testQueueIsSetUsingQueueRoutes()
    {
        $container = new Container;
        $d = new Dispatcher($container);

        $queueRoutes = new QueueRoutes;
        $queueRoutes->set(TestDispatcherQueueRoutes::class, 'event-queue', 'event-connection');
        $container->instance('queue.routes', $queueRoutes);

        $fakeQueue = new QueueFake($container);

        Container::setInstance($container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherQueueRoutes::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->connection('event-connection')->assertPushedOn('event-queue', CallQueuedListener::class);
    }

    public function testDelayIsSetByWithDelayDynamically()
    {
        $d = new Dispatcher;
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);

        $factory->shouldReceive('connection')->once()->with(null)->andReturn($queue);
        $queue->shouldReceive('laterOn')->once()->with(null, 60, m::type(CallQueuedListener::class));

        $d->setQueueResolver(function () use ($factory) {
            return $factory;
        });

        $d->listen('some.event', TestDispatcherGetDelayDynamically::class . '@handle');
        $d->dispatch('some.event', [['useHighDelay' => true], 'bar']);
    }

    public function testQueuePropagateRetryUntilAndMaxExceptions()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherOptions::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return $job->maxExceptions === 1 && $job->retryUntil !== null;
        });
    }

    public function testQueuePropagateTries()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherOptions::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return $job->tries === 5;
        });
    }

    public function testQueuePropagatesArrayBackoffFromMethod(): void
    {
        $dispatcher = new Dispatcher;
        $queue = new QueueFake(new Container);

        $dispatcher->setQueueResolver(fn () => $queue);
        $dispatcher->listen('some.event', TestDispatcherWithBackoffMethod::class . '@handle');
        $dispatcher->dispatch('some.event', ['foo', 'bar']);

        $queue->assertPushed(
            CallQueuedListener::class,
            fn (CallQueuedListener $job): bool => $job->backoff === [1, 5, 10]
        );
    }

    public function testQueuePropagatesVariadicBackoffAttribute(): void
    {
        $dispatcher = new Dispatcher;
        $queue = new QueueFake(new Container);

        $dispatcher->setQueueResolver(fn () => $queue);
        $dispatcher->listen('some.event', TestDispatcherWithBackoffAttribute::class . '@handle');
        $dispatcher->dispatch('some.event', ['foo', 'bar']);

        $queue->assertPushed(
            CallQueuedListener::class,
            fn (CallQueuedListener $job): bool => $job->backoff === [1, 5, 10]
        );
    }

    public function testQueuePropagateMessageGroupProperty()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherWithMessageGroupProperty::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return $job->messageGroup === 'group-property';
        });
    }

    public function testQueuePropagateMessageGroupMethodOverProperty()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherWithMessageGroupMethod::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return $job->messageGroup === 'group-method';
        });
    }

    public function testQueuePropagateDeduplicationIdMethod()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherWithDeduplicationIdMethod::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);

            return is_callable($job->deduplicator) && call_user_func($job->deduplicator, '', null) === 'deduplication-id-method';
        });
    }

    public function testQueuePropagateDeduplicatorMethodOverDeduplicationIdMethod()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherWithDeduplicatorMethod::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);

            return is_callable($job->deduplicator) && call_user_func($job->deduplicator, '', null) === 'deduplicator-method';
        });
    }

    public function testQueuePropagateMiddleware()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherMiddleware::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return count($job->middleware) === 1
                && $job->middleware[0] instanceof TestMiddleware
                && $job->middleware[0]->a === 'foo'
                && $job->middleware[0]->b === 'bar';
        });
    }

    public function testDispatchesOnQueueDefinedWithEnum()
    {
        $d = new Dispatcher;

        $fakeQueue = new QueueFake(new Container);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherViaQueueSupportsEnum::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushedOn('enumerated-queue', CallQueuedListener::class);
    }

    public function testQueuePropagatesShouldBeUnique()
    {
        $container = new Container;
        $d = new Dispatcher($container);

        $fakeQueue = new QueueFake($container);
        $cache = m::mock(Cache::class);
        $lock = m::mock(Lock::class);

        $container->instance(Cache::class, $cache);

        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
        $cache->shouldReceive('lock')->once()->andReturn($lock);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('owner')->once()->andReturn('unique-lock-owner');

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherShouldBeUnique::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return $job->shouldBeUnique === true
                && $job->shouldBeUniqueUntilProcessing === false
                && $job->uniqueId === 'unique-listener-id'
                && $job->uniqueFor === 60;
        });
    }

    public function testUniqueListenerNotQueuedWhenLockNotAcquired()
    {
        $container = new Container;
        $d = new Dispatcher($container);

        $fakeQueue = new QueueFake($container);
        $cache = m::mock(Cache::class);
        $lock = m::mock(Lock::class);

        $container->instance(Cache::class, $cache);

        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
        $cache->shouldReceive('lock')->once()->andReturn($lock);
        $lock->shouldReceive('get')->once()->andReturn(false);

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherShouldBeUnique::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertNothingPushed();
    }

    public function testQueuePropagatesShouldBeUniqueUntilProcessing()
    {
        $container = new Container;
        $d = new Dispatcher($container);

        $fakeQueue = new QueueFake($container);
        $cache = m::mock(Cache::class);
        $lock = m::mock(Lock::class);

        $container->instance(Cache::class, $cache);

        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
        $cache->shouldReceive('lock')->once()->andReturn($lock);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('owner')->once()->andReturn('unique-lock-owner');

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherShouldBeUniqueUntilProcessing::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return $job->shouldBeUnique === true
                && $job->shouldBeUniqueUntilProcessing === true;
        });
    }

    public function testQueuePropagatesUniqueIdFromMethod()
    {
        $container = new Container;
        $d = new Dispatcher($container);

        $fakeQueue = new QueueFake($container);
        $cache = m::mock(Cache::class);
        $lock = m::mock(Lock::class);

        $container->instance(Cache::class, $cache);

        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
        $cache->shouldReceive('lock')->once()->andReturn($lock);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('owner')->once()->andReturn('unique-lock-owner');

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherUniqueIdFromMethod::class . '@handle');
        $d->dispatch('some.event', [['id' => 'event-123'], 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class, function ($job) {
            return $job->uniqueId === 'unique-id-event-123';
        });
    }

    public function testUniqueLockKeyUsesListenerClassName()
    {
        $listener = new CallQueuedListener(TestDispatcherShouldBeUnique::class, 'handle', []);
        $listener->shouldBeUnique = true;
        $listener->uniqueId = 'test-id';

        $this->assertSame(TestDispatcherShouldBeUnique::class, $listener->displayName());
        $this->assertSame(
            'laravel_unique_job:' . hash('xxh128', TestDispatcherShouldBeUnique::class) . ':test-id',
            \Hypervel\Bus\UniqueLock::getKey($listener)
        );
    }

    public function testUniqueLockIsAcquiredWithListenerClassName()
    {
        $container = new Container;
        $d = new Dispatcher($container);

        $fakeQueue = new QueueFake($container);
        $cache = m::mock(Cache::class);
        $lock = m::mock(Lock::class);

        $container->instance(Cache::class, $cache);

        $expectedKey = 'laravel_unique_job:' . hash('xxh128', TestDispatcherShouldBeUnique::class) . ':unique-listener-id';

        $cache->shouldReceive('lock')
            ->once()
            ->with($expectedKey, 60)
            ->andReturn($lock);
        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('owner')->once()->andReturn('unique-lock-owner');

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherShouldBeUnique::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class);
    }

    public function testUniqueViaUsesListenerCacheRepository()
    {
        $container = new Container;
        $d = new Dispatcher($container);

        $fakeQueue = new QueueFake($container);
        $defaultCache = m::mock(Cache::class);
        $uniqueCache = m::mock(Cache::class);
        $lock = m::mock(Lock::class);

        $container->instance(Cache::class, $defaultCache);

        $defaultCache->shouldNotReceive('lock');

        TestDispatcherShouldBeUniqueWithCustomCache::$cache = $uniqueCache;

        $expectedKey = 'laravel_unique_job:' . hash('xxh128', TestDispatcherShouldBeUniqueWithCustomCache::class) . ':unique-listener-id';

        $uniqueCache->shouldReceive('lock')
            ->once()
            ->with($expectedKey, 60)
            ->andReturn($lock);
        $uniqueCache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
        $lock->shouldReceive('get')->once()->andReturn(true);
        $lock->shouldReceive('owner')->once()->andReturn('unique-lock-owner');

        $d->setQueueResolver(function () use ($fakeQueue) {
            return $fakeQueue;
        });

        $d->listen('some.event', TestDispatcherShouldBeUniqueWithCustomCache::class . '@handle');
        $d->dispatch('some.event', ['foo', 'bar']);

        $fakeQueue->assertPushed(CallQueuedListener::class);
    }

    public function testUniqueLockIsReleasedOnProcessingWithListenerClassName()
    {
        $container = new Container;
        $cache = m::mock(Cache::class);
        $lock = m::mock(Lock::class);

        $container->instance(Cache::class, $cache);
        $container->instance(BusDispatcher::class, new BusDispatcher($container));

        $listener = new CallQueuedListener(TestDispatcherShouldBeUnique::class, 'handle', ['foo', 'bar']);
        $listener->shouldBeUnique = true;
        $listener->uniqueId = 'unique-listener-id';
        $listener->uniqueFor = 60;

        $expectedKey = 'laravel_unique_job:' . hash('xxh128', TestDispatcherShouldBeUnique::class) . ':unique-listener-id';

        $cache->shouldReceive('lock')
            ->once()
            ->with($expectedKey)
            ->andReturn($lock);
        $lock->shouldReceive('forceRelease')->once();

        $job = m::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $handler = new CallQueuedHandler(new BusDispatcher($container), $container);
        $handler->call($job, ['command' => serialize($listener)]);
    }

    public function testUniqueUntilProcessingLockIsReleasedBeforeHandling()
    {
        $container = new Container;
        $cache = m::mock(Cache::class);
        $lock = m::mock(Lock::class);

        $container->instance(Cache::class, $cache);
        $container->instance(BusDispatcher::class, new BusDispatcher($container));

        TestDispatcherShouldBeUniqueUntilProcessing::$lockReleasedBeforeHandling = null;
        TestDispatcherShouldBeUniqueUntilProcessing::$cache = $cache;
        TestDispatcherShouldBeUniqueUntilProcessing::$expectedLockKey = 'laravel_unique_job:' . hash('xxh128', TestDispatcherShouldBeUniqueUntilProcessing::class) . ':until-processing-id';

        $listener = new CallQueuedListener(TestDispatcherShouldBeUniqueUntilProcessing::class, 'handle', ['foo', 'bar']);
        $listener->shouldBeUnique = true;
        $listener->shouldBeUniqueUntilProcessing = true;
        $listener->uniqueId = 'until-processing-id';

        $expectedKey = 'laravel_unique_job:' . hash('xxh128', TestDispatcherShouldBeUniqueUntilProcessing::class) . ':until-processing-id';

        $cache->shouldReceive('lock')
            ->with($expectedKey)
            ->andReturn($lock);
        $lock->shouldReceive('forceRelease')->once();

        $job = m::mock(Job::class);
        $job->shouldReceive('hasFailed')->andReturn(false);
        $job->shouldReceive('isDeleted')->andReturn(false);
        $job->shouldReceive('isReleased')->andReturn(false);
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('isDeletedOrReleased')->andReturn(false);
        $job->shouldReceive('delete')->once();

        $handler = new CallQueuedHandler(new BusDispatcher($container), $container);
        $handler->call($job, ['command' => serialize($listener)]);

        $this->assertTrue(TestDispatcherShouldBeUniqueUntilProcessing::$lockReleasedBeforeHandling);
    }

    public function testQueuePropagatesDebounceOptions(): void
    {
        $container = new Container;
        $dispatcher = new Dispatcher($container);
        $queue = new QueueFake($container);
        $cache = new Repository(new ArrayStore);

        $container->instance(Cache::class, $cache);
        $dispatcher->setQueueResolver(fn () => $queue);

        $dispatcher->listen('some.event', TestDispatcherDebouncedHandler::class . '@handle');
        $dispatcher->dispatch('some.event', [['id' => 'event-123'], 'bar']);

        $expectedKey = 'laravel_debounced_job:' . hash('xxh128', TestDispatcherDebouncedHandler::class) . ':event-123';

        $queue->assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) use ($cache, $expectedKey): bool {
            return $job->debounceId() === 'event-123'
                && $job->debounceOwner !== ''
                && $cache->get($expectedKey) === $job->debounceOwner;
        });

        $this->assertSame(1, $queue->delayedSize());
    }

    public function testExplicitListenerDelayTakesPrecedenceOverDebounceDelay(): void
    {
        $container = new Container;
        $dispatcher = new Dispatcher($container);
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);
        $cache = new Repository(new ArrayStore);

        $container->instance(Cache::class, $cache);

        $factory->shouldReceive('connection')->once()->with(null)->andReturn($queue);
        $queue->shouldReceive('laterOn')->once()->with(null, 20, m::on(function (CallQueuedListener $job) use ($cache): bool {
            $expectedKey = 'laravel_debounced_job:' . hash('xxh128', TestDispatcherDebouncedHandlerWithDelay::class) . ':event-123';

            return $job->debounceOwner !== ''
                && $cache->get($expectedKey) === $job->debounceOwner;
        }));

        $dispatcher->setQueueResolver(fn () => $factory);
        $dispatcher->listen('some.event', TestDispatcherDebouncedHandlerWithDelay::class . '@handle');
        $dispatcher->dispatch('some.event', [['id' => 'event-123'], 'bar']);
    }

    public function testDebouncedListenerMaxWaitForcesImmediateExecution(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');

        $container = new Container;
        $dispatcher = new Dispatcher($container);
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);
        $cache = new Repository(new ArrayStore);

        $container->instance(Cache::class, $cache);

        $factory->shouldReceive('connection')->twice()->with(null)->andReturn($queue);
        $queue->shouldReceive('laterOn')->once()->with(null, 30, m::on(function (CallQueuedListener $job): bool {
            DispatchLockContext::accept($job);

            return true;
        }))->ordered();
        $queue->shouldReceive('laterOn')->once()->with(null, 0, m::on(function (CallQueuedListener $job): bool {
            DispatchLockContext::accept($job);

            return true;
        }))->ordered();

        $dispatcher->setQueueResolver(fn () => $factory);
        $dispatcher->listen('some.event', TestDispatcherDebouncedHandlerWithMaxWait::class . '@handle');
        $dispatcher->dispatch('some.event', [['id' => 'event-123'], 'bar']);

        $expectedKey = 'laravel_debounced_job:' . hash('xxh128', TestDispatcherDebouncedHandlerWithMaxWait::class) . ':event-123';
        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $cache->get($expectedKey . ':first_dispatched_at'));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(60));

        $dispatcher->dispatch('some.event', [['id' => 'event-123'], 'bar']);
    }

    public function testDebounceViaUsesListenerCacheRepository(): void
    {
        $container = new Container;
        $dispatcher = new Dispatcher($container);
        $queue = new QueueFake($container);
        $defaultCache = new Repository(new ArrayStore);
        $debounceCache = new Repository(new ArrayStore);

        $container->instance(Cache::class, $defaultCache);
        Container::setInstance($container);
        TestDispatcherDebouncedHandlerWithCustomCache::$cache = ['event-123' => $debounceCache];

        $dispatcher->setQueueResolver(fn () => $queue);
        $dispatcher->listen('some.event', TestDispatcherDebouncedHandlerWithCustomCache::class . '@handle');
        $dispatcher->dispatch('some.event', [['id' => 'event-123'], 'bar']);

        $job = $queue->pushed(CallQueuedListener::class)->first();

        $this->assertInstanceOf(CallQueuedListener::class, $job);
        $this->assertNull($defaultCache->get(DebounceLock::getKey($job)));
        $this->assertSame($job->debounceOwner, $debounceCache->get(DebounceLock::getKey($job)));
    }

    public function testDebouncedListenerCannotAlsoBeUnique(): void
    {
        $container = new Container;
        $dispatcher = new Dispatcher($container);
        $queue = new QueueFake($container);
        $cache = m::mock(Cache::class);

        $cache->shouldNotReceive('put');
        $cache->shouldNotReceive('lock');

        $container->instance(Cache::class, $cache);
        $dispatcher->setQueueResolver(fn () => $queue);
        $dispatcher->listen('some.event', TestDispatcherDebouncedAndUniqueHandler::class . '@handle');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A debounced listener cannot also implement ShouldBeUnique.');

        $dispatcher->dispatch('some.event', [['id' => 'event-123'], 'bar']);
    }

    public function testUniqueListenerLockIsReleasedWhenQueueResolutionFails(): void
    {
        $container = new Container;
        $dispatcher = new Dispatcher($container);
        $factory = m::mock(QueueFactory::class);
        $cache = new Repository(new ArrayStore);
        $failure = new Exception('Queue unavailable.');
        $lockKey = 'laravel_unique_job:' . hash('xxh128', TestDispatcherShouldBeUnique::class) . ':unique-listener-id';

        $container->instance(Cache::class, $cache);
        $factory->shouldReceive('connection')->once()->andThrow($failure);
        $dispatcher->setQueueResolver(fn () => $factory);
        $dispatcher->listen('some.event', TestDispatcherShouldBeUnique::class . '@handle');

        try {
            $dispatcher->dispatch('some.event', ['foo', 'bar']);

            $this->fail('Expected queue resolution to fail.');
        } catch (Exception $exception) {
            $this->assertSame($failure, $exception);
        }

        $replacement = $cache->lock($lockKey, 10);
        $this->assertTrue($replacement->get());
        $replacement->forceRelease();
    }

    public function testDebouncedListenerPublicationFailureReleasesItsOwnership(): void
    {
        $container = new Container;
        $dispatcher = new Dispatcher($container);
        $factory = m::mock(QueueFactory::class);
        $queue = m::mock(Queue::class);
        $cache = new Repository(new ArrayStore);
        $failure = new Exception('Queue unavailable.');
        $lockKey = 'laravel_debounced_job:' . hash('xxh128', TestDispatcherDebouncedHandlerWithMaxWait::class) . ':event-123';

        $container->instance(Cache::class, $cache);
        $factory->shouldReceive('connection')->once()->with(null)->andReturn($queue);
        $queue->shouldReceive('laterOn')->once()->andThrow($failure);
        $dispatcher->setQueueResolver(fn () => $factory);
        $dispatcher->listen('some.event', TestDispatcherDebouncedHandlerWithMaxWait::class . '@handle');

        try {
            $dispatcher->dispatch('some.event', [['id' => 'event-123'], 'bar']);

            $this->fail('Expected queue publication to fail.');
        } catch (Exception $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertNull($cache->get($lockKey));
        $this->assertNull($cache->get($lockKey . ':first_dispatched_at'));
    }
}

class TestDispatcherQueuedHandler implements ShouldQueue
{
    public function handle()
    {
    }
}

enum QueuedEvent
{
    case Created;
}

class TestDispatcherConnectionQueuedHandler implements ShouldQueue
{
    public string $connection = 'redis';

    public int $delay = 10;

    public string $queue = 'my_queue';

    public function handle()
    {
    }
}

class TestDispatcherGetQueue implements ShouldQueue
{
    public string $queue = 'my_queue';

    public function handle()
    {
    }

    public function viaQueue()
    {
        return 'some_other_queue';
    }
}

class TestDispatcherGetConnection implements ShouldQueue
{
    public string $connection = 'my_connection';

    public function handle()
    {
    }

    public function viaConnection()
    {
        return 'some_other_connection';
    }
}

class TestDispatcherGetEnumQueueAndConnection implements ShouldQueue
{
    public function handle(): void
    {
    }

    public function viaConnection(): QueuedConnectionIdentifier
    {
        return QueuedConnectionIdentifier::Zero;
    }

    public function viaQueue(): QueuedQueueIdentifier
    {
        return QueuedQueueIdentifier::Primary;
    }
}

enum QueuedConnectionIdentifier: int
{
    case Zero = 0;
}

enum QueuedQueueIdentifier: int
{
    case Primary = 1;
}

class TestDispatcherGetDelay implements ShouldQueue
{
    public int $delay = 10;

    public function handle()
    {
    }

    public function withDelay()
    {
        return 20;
    }
}

#[Delay(30)]
class TestDispatcherGetDelayFromAttribute implements ShouldQueue
{
    public function handle(): void
    {
    }
}

#[Delay(30)]
class TestDispatcherGetDelayMethodOverridesAttribute implements ShouldQueue
{
    public function handle(): void
    {
    }

    public function withDelay(): int
    {
        return 20;
    }
}

class TestDispatcherOptions implements ShouldQueue
{
    public int $maxExceptions = 1;

    public function retryUntil()
    {
        return now()->addHour(1);
    }

    public function tries()
    {
        return 5;
    }

    public function handle()
    {
    }
}

class TestDispatcherWithBackoffMethod implements ShouldQueue
{
    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function handle(): void
    {
    }
}

#[Backoff(1, 5, 10)]
class TestDispatcherWithBackoffAttribute implements ShouldQueue
{
    public function handle(): void
    {
    }
}

class TestDispatcherWithMessageGroupProperty implements ShouldQueue
{
    public string $messageGroup = 'group-property';

    public function handle()
    {
    }
}

class TestDispatcherWithMessageGroupMethod implements ShouldQueue
{
    public string $messageGroup = 'group-property';

    public function handle()
    {
    }

    public function messageGroup($event)
    {
        return 'group-method';
    }
}

class TestDispatcherWithDeduplicationIdMethod implements ShouldQueue
{
    public function handle()
    {
    }

    public function deduplicationId($payload, $queue)
    {
        return 'deduplication-id-method';
    }
}

class TestDispatcherWithDeduplicatorMethod implements ShouldQueue
{
    public function handle()
    {
    }

    public function deduplicationId($payload, $queue)
    {
        return 'deduplication-id-method';
    }

    public function deduplicator($event)
    {
        return fn ($payload, $queue) => 'deduplicator-method';
    }
}

class TestDispatcherMiddleware implements ShouldQueue
{
    public function middleware($a, $b)
    {
        return [new TestMiddleware($a, $b)];
    }

    public function handle($a, $b)
    {
    }
}

class TestMiddleware
{
    public function __construct(
        public mixed $a,
        public mixed $b,
    ) {
    }

    public function handle($job, $next)
    {
        $next($job);
    }
}

class TestDispatcherGetConnectionDynamically implements ShouldQueue
{
    public function handle()
    {
    }

    public function viaConnection($event)
    {
        if ($event['shouldUseRedisConnection']) {
            return 'redis';
        }

        return 'sqs';
    }
}

class TestDispatcherGetQueueDynamically implements ShouldQueue
{
    public string $queue = 'my_queue';

    public function handle()
    {
    }

    public function viaQueue($event)
    {
        if ($event['useHighPriorityQueue']) {
            return 'p0';
        }

        return 'p99';
    }
}

class TestDispatcherGetDelayDynamically implements ShouldQueue
{
    public int $delay = 10;

    public function handle()
    {
    }

    public function withDelay($event)
    {
        if ($event['useHighDelay']) {
            return 60;
        }

        return 20;
    }
}

enum TestQueueType: string
{
    case EnumeratedQueue = 'enumerated-queue';
}

class TestDispatcherViaQueueSupportsEnum implements ShouldQueue
{
    public function viaQueue()
    {
        return TestQueueType::EnumeratedQueue;
    }
}

class TestDispatcherQueueRoutes implements ShouldQueue
{
    public function handle()
    {
    }
}

class TestDispatcherShouldBeUnique implements ShouldQueue, ShouldBeUnique
{
    public string $uniqueId = 'unique-listener-id';

    public int $uniqueFor = 60;

    public function handle()
    {
    }
}

class TestDispatcherShouldBeUniqueUntilProcessing implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use InteractsWithQueue;

    public static ?bool $lockReleasedBeforeHandling = null;

    public static ?Cache $cache = null;

    public static string $expectedLockKey = '';

    public function handle()
    {
        $lock = m::mock(Lock::class);
        $lock->shouldReceive('get')->andReturn(true);
        static::$cache->shouldReceive('lock')
            ->with(static::$expectedLockKey, 10)
            ->andReturn($lock);

        static::$lockReleasedBeforeHandling = static::$cache->lock(static::$expectedLockKey, 10)->get();
    }
}

class TestDispatcherUniqueIdFromMethod implements ShouldQueue, ShouldBeUnique
{
    public function handle()
    {
    }

    public function uniqueId($event)
    {
        return 'unique-id-' . $event['id'];
    }
}

class TestDispatcherShouldBeUniqueWithCustomCache implements ShouldQueue, ShouldBeUnique
{
    public static ?Cache $cache = null;

    public function handle()
    {
    }

    public function uniqueId()
    {
        return 'unique-listener-id';
    }

    public function uniqueFor()
    {
        return 60;
    }

    public function uniqueVia(): Cache
    {
        return static::$cache;
    }
}

#[DebounceFor(30)]
class TestDispatcherDebouncedHandler implements ShouldQueue
{
    public function debounceId(array $event): string
    {
        return $event['id'];
    }

    public function handle(): void
    {
    }
}

#[DebounceFor(30)]
class TestDispatcherDebouncedHandlerWithDelay implements ShouldQueue
{
    public string $debounceId = 'event-123';

    public function withDelay(): int
    {
        return 20;
    }

    public function handle(): void
    {
    }
}

#[DebounceFor(30, maxWait: 60)]
class TestDispatcherDebouncedHandlerWithMaxWait implements ShouldQueue
{
    public function debounceId(array $event): string
    {
        return $event['id'];
    }

    public function handle(): void
    {
    }
}

#[DebounceFor(30)]
class TestDispatcherDebouncedHandlerWithCustomCache implements ShouldQueue
{
    /** @var array<string, Cache> */
    public static array $cache = [];

    public function debounceId(array $event): string
    {
        return $event['id'];
    }

    public function debounceVia(array $event): Cache
    {
        return static::$cache[$event['id']];
    }

    public function handle(): void
    {
    }
}

#[DebounceFor(30)]
class TestDispatcherDebouncedAndUniqueHandler implements ShouldQueue, ShouldBeUnique
{
    public function debounceId(array $event): string
    {
        return $event['id'];
    }

    public function handle(): void
    {
    }
}
