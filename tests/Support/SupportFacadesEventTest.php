<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\SupportFacadesEventTest;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Events\CacheFlushed;
use Hypervel\Cache\Events\CacheFlushing;
use Hypervel\Cache\Events\CacheLocksFlushed;
use Hypervel\Cache\Events\CacheLocksFlushing;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Events\Dispatcher;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

class SupportFacadesEventTest extends TestCase
{
    private Dispatcher&MockInterface $events;

    /**
     * Set up the event and cache services.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->events = m::mock(Dispatcher::class);
        $this->events->shouldReceive('hasListeners')->andReturnTrue();

        $container = new Container;
        $container->instance('events', $this->events);
        $container->alias('events', DispatcherContract::class);
        $container->instance('cache', new CacheManager($container));
        $container->instance('config', new ConfigRepository($this->getCacheConfig()));

        Facade::setFacadeApplication($container);
    }

    public function testFakeFor(): void
    {
        Event::fakeFor(function (): void {
            (new FakeForStub)->dispatch();

            Event::assertDispatched(EventStub::class);
        });

        $this->events->expects('dispatch');

        (new FakeForStub)->dispatch();
    }

    public function testFakeForSwapsDispatchers(): void
    {
        $arrayRepository = Cache::store('array');

        Event::fakeFor(function () use ($arrayRepository): void {
            $this->assertInstanceOf(EventFake::class, Event::getFacadeRoot());
            $this->assertInstanceOf(EventFake::class, Model::getEventDispatcher());
            $this->assertInstanceOf(EventFake::class, $arrayRepository->getEventDispatcher());
        });

        $this->assertSame($this->events, Event::getFacadeRoot());
        $this->assertSame($this->events, Model::getEventDispatcher());
        $this->assertSame($this->events, $arrayRepository->getEventDispatcher());
    }

    public function testFakeSwapsDispatchersInResolvedCacheRepositories(): void
    {
        $arrayRepository = Cache::store('array');

        $this->events->expects('dispatch')->times(2);
        $arrayRepository->get('foo');

        Event::fake();

        $arrayRepository->get('bar');

        Event::assertDispatched(RetrievingKey::class);
        Event::assertDispatched(CacheMissed::class);
    }

    public function testCacheFlushDispatchesEvent(): void
    {
        $arrayRepository = Cache::store('array');
        Event::fake();

        $arrayRepository->clear();

        Event::assertDispatched(CacheFlushing::class);
        Event::assertDispatched(CacheFlushed::class);
    }

    public function testCacheFlushLocksDispatchesEvent(): void
    {
        $arrayRepository = Cache::store('array');
        Event::fake();

        $arrayRepository->flushLocks();

        Event::assertDispatched(CacheLocksFlushing::class);
        Event::assertDispatched(CacheLocksFlushed::class);
    }

    public function testFakeReturnsEventFake(): void
    {
        $fake = Event::fake();

        $this->assertInstanceOf(EventFake::class, $fake);
    }

    public function testDoubleFakeUnwrapsToOriginalDispatcher(): void
    {
        $originalDispatcher = Event::getFacadeRoot();

        Event::fake();
        $secondFake = Event::fake();

        $this->assertInstanceOf(EventFake::class, $secondFake);
        $this->assertSame($originalDispatcher, $secondFake->dispatcher);
    }

    public function testFakeExceptDoubleFakeDoesNotTypeError(): void
    {
        Event::fake();
        $fake = Event::fakeExcept('some.event');

        $this->assertInstanceOf(EventFake::class, $fake);
        $this->assertSame($this->events, $fake->dispatcher);
    }

    /**
     * Get the configuration for the array cache store.
     */
    protected function getCacheConfig(): array
    {
        return [
            'cache' => [
                'stores' => [
                    'array' => [
                        'driver' => 'array',
                    ],
                ],
            ],
        ];
    }
}

class FakeForStub
{
    /**
     * Dispatch the test event.
     */
    public function dispatch(): void
    {
        Event::dispatch(EventStub::class);
    }
}

class EventStub
{
}
