<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpServer\Router\DispatcherFactory as RouterDispatcherFactory;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Broadcasting\Channel;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactoryContract;
use Hypervel\Contracts\Broadcasting\ShouldBeUnique;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Broadcasting\UniqueBroadcastEvent;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Bus\QueueingDispatcher;
use Hypervel\Contracts\Cache\Factory as Cache;
use Hypervel\Container\DefinitionSource;
use Hypervel\Context\ApplicationContext;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Foundation\Http\Middleware\VerifyCsrfToken;
use Hypervel\Contracts\Queue\Factory as QueueFactoryContract;
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

    public function testRoutesExcludesCsrfMiddleware(): void
    {
        $capturedAttributes = null;

        $router = m::mock('router');
        $router->shouldReceive('addRoute')
            ->once()
            ->withArgs(function ($methods, $path, $handler, $attributes) use (&$capturedAttributes) {
                $capturedAttributes = $attributes;
                return true;
            });

        $routerFactory = m::mock('routerFactory');
        $routerFactory->shouldReceive('getRouter')
            ->with('http')
            ->andReturn($router);

        $config = m::mock(ConfigInterface::class);
        $config->shouldReceive('get')
            ->with('server.kernels', [])
            ->andReturn(['http' => []]);

        $app = m::mock(ContainerInterface::class);
        $app->shouldReceive('has')->with(Kernel::class)->andReturn(true);
        $app->shouldReceive('get')->with(ConfigInterface::class)->andReturn($config);
        $app->shouldReceive('get')->with(RouterDispatcherFactory::class)->andReturn($routerFactory);

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->routes();

        $this->assertSame(['web'], $capturedAttributes['middleware']);
        $this->assertSame([VerifyCsrfToken::class], $capturedAttributes['without_middleware']);
    }

    public function testUserRoutesExcludesCsrfMiddleware(): void
    {
        $capturedAttributes = null;

        $router = m::mock('router');
        $router->shouldReceive('addRoute')
            ->once()
            ->withArgs(function ($methods, $path, $handler, $attributes) use (&$capturedAttributes) {
                $capturedAttributes = $attributes;
                return true;
            });

        $routerFactory = m::mock('routerFactory');
        $routerFactory->shouldReceive('getRouter')
            ->andReturn($router);

        $app = m::mock(ContainerInterface::class);
        $app->shouldReceive('get')->with(RouterDispatcherFactory::class)->andReturn($routerFactory);

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->userRoutes();

        $this->assertSame(['web'], $capturedAttributes['middleware']);
        $this->assertSame([VerifyCsrfToken::class], $capturedAttributes['without_middleware']);
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
