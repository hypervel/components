<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\UniqueBroadcastEvent;
use Hypervel\Config\Repository;
use Hypervel\Contracts\Broadcasting\ShouldBeUnique;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Contracts\Cache\Factory as Cache;
use Hypervel\Contracts\Container\Container;
use Hypervel\Foundation\Http\Kernel;
use Hypervel\Foundation\Http\Middleware\VerifyCsrfToken;
use Hypervel\HttpServer\Router\DispatcherFactory as RouterDispatcherFactory;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class BroadcastManagerTest extends TestCase
{
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
        $this->app->singleton(Cache::class, fn () => $cache);

        Broadcast::queue(new TestEventUnique());

        Bus::assertNotDispatched(UniqueBroadcastEvent::class);
        Queue::assertPushed(UniqueBroadcastEvent::class);
    }

    public function testThrowExceptionWhenUnknownStoreIsUsed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Broadcast connection [alien_connection] is not defined.');

        $config = m::mock(Container::class);
        $config->shouldReceive('get')->with('broadcasting.connections.alien_connection')->andReturn(null);

        $app = m::mock(Container::class);
        $app->shouldReceive('make')->with('config')->andReturn($config);

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

        $config = m::mock(Repository::class);
        $config->shouldReceive('get')
            ->with('server.kernels', [])
            ->andReturn(['http' => []]);

        $app = m::mock(Container::class);
        $app->shouldReceive('has')->with(Kernel::class)->andReturn(true);
        $app->shouldReceive('make')->with('config')->andReturn($config);
        $app->shouldReceive('make')->with(RouterDispatcherFactory::class)->andReturn($routerFactory);

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

        $app = m::mock(Container::class);
        $app->shouldReceive('make')->with(RouterDispatcherFactory::class)->andReturn($routerFactory);

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
