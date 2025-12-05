<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\Contracts\Factory as BroadcastingFactoryContract;
use Hypervel\Broadcasting\Contracts\ShouldBeUnique;
use Hypervel\Broadcasting\Contracts\ShouldBroadcast;
use Hypervel\Broadcasting\Contracts\ShouldBroadcastNow;
use Hypervel\Broadcasting\UniqueBroadcastEvent;
use Hypervel\Bus\Contracts\Dispatcher as BusDispatcherContract;
use Hypervel\Bus\Contracts\QueueingDispatcher;
use Hypervel\Cache\Contracts\Factory as Cache;
use Hypervel\Container\DefinitionSource;
use Hypervel\Context\ApplicationContext;
use Hypervel\Foundation\Application;
use Hypervel\Queue\Contracts\Factory as QueueFactoryContract;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @internal
 * @coversNothing
 */
class BroadcastManagerTest extends TestCase
{
    protected Application $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container = new Application(
            new DefinitionSource([
                BusDispatcherContract::class => fn () => m::mock(QueueingDispatcher::class),
                ConfigInterface::class => fn () => m::mock(ConfigInterface::class),
                QueueFactoryContract::class => fn () => m::mock(QueueFactoryContract::class),
                BroadcastingFactoryContract::class => fn ($container) => new BroadcastManager($container),
            ]),
            'bath_path',
        );

        ApplicationContext::setContainer($this->container);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        m::close();

        Facade::clearResolvedInstances();
    }

    public function testEventCanBeBroadcastNow()
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventNow());

        Bus::assertDispatched(BroadcastEvent::class);
        Queue::assertNotPushed(BroadcastEvent::class);
    }

    public function testEventsCanBeBroadcast()
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEvent());

        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::assertPushed(BroadcastEvent::class);
    }

    public function testUniqueEventsCanBeBroadcast()
    {
        Bus::fake();
        Queue::fake();

        $lockKey = 'laravel_unique_job:' . UniqueBroadcastEvent::class . ':' . TestEventUnique::class;
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with($lockKey, 0)->andReturnSelf();
        $cache->shouldReceive('get')->andReturn(true);
        $this->container->bind(Cache::class, fn () => $cache);

        Broadcast::queue(new TestEventUnique());

        Bus::assertNotDispatched(UniqueBroadcastEvent::class);
        Queue::assertPushed(UniqueBroadcastEvent::class);
    }

    public function testThrowExceptionWhenUnknownStoreIsUsed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Broadcast connection [alien_connection] is not defined.');

        $config = m::mock(ContainerInterface::class);
        $config->shouldReceive('get')->with('broadcasting.connections.alien_connection')->andReturn(null);

        $app = m::mock(ContainerInterface::class);
        $app->shouldReceive('get')->with(ConfigInterface::class)->andReturn($config);

        $broadcastManager = new BroadcastManager($app);

        $broadcastManager->connection('alien_connection');
    }

    /**
     * Test that channels are stored on the manager itself, not proxied to pooled drivers.
     *
     * This is critical for coroutine-safe broadcasting: when using pooled drivers
     * (like Pusher), channels registered via Broadcast::channel() must be stored
     * on the singleton BroadcastManager, not on individual pooled driver instances.
     * Otherwise, authorization requests may fail because they hit a different
     * pooled instance that doesn't have the channel patterns registered.
     */
    public function testChannelsAreStoredOnManagerNotProxiedToDriver()
    {
        $app = m::mock(ContainerInterface::class);
        $broadcastManager = new BroadcastManager($app);

        // Register a channel on the manager
        $callback = function ($user, $id) {
            return (int) $user->getAuthIdentifier() === (int) $id;
        };

        $broadcastManager->channel('App.Models.User.{id}', $callback);

        // The manager should have a getChannels() method that returns stored channels
        // This ensures channels are stored on the manager itself (singleton),
        // not proxied to potentially different pooled driver instances
        $this->assertTrue(
            method_exists($broadcastManager, 'getChannels'),
            'BroadcastManager must have getChannels() method to expose stored channels'
        );

        $channels = $broadcastManager->getChannels();
        $this->assertCount(1, $channels);
        $this->assertArrayHasKey('App.Models.User.{id}', $channels->toArray());
    }
}

class TestEvent implements ShouldBroadcast
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel[]|string[]
     */
    public function broadcastOn(): array
    {
        return [];
    }
}

class TestEventNow implements ShouldBroadcastNow
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel[]|string[]
     */
    public function broadcastOn(): array
    {
        return [];
    }
}

class TestEventUnique implements ShouldBroadcast, ShouldBeUnique
{
    /**
     * Get the channels the event should broadcast on.
     *
     * @return Channel[]|string[]
     */
    public function broadcastOn(): array
    {
        return [];
    }
}
