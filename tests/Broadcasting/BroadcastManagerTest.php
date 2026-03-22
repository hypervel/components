<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Exception;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\UniqueBroadcastEvent;
use Hypervel\Container\Container;
use Hypervel\Contracts\Broadcasting\ShouldBeUnique;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

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

        $lockKey = 'hypervel_unique_job:' . TestEventUnique::class . ':';
        $lock = m::mock(\Hypervel\Contracts\Cache\Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with($lockKey, 0)->andReturn($lock);
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
        $route = m::mock(Route::class);
        $route->shouldReceive('withoutMiddleware')
            ->once()
            ->with([PreventRequestForgery::class])
            ->andReturnSelf();

        $router = m::mock('router');
        $router->shouldReceive('group')
            ->once()
            ->withArgs(function ($attributes, $callback) use ($router) {
                $this->assertSame(['middleware' => ['web']], $attributes);
                $callback($router);
                return true;
            });
        $router->shouldReceive('match')
            ->once()
            ->withArgs(function ($methods, $path) {
                return $methods === ['get', 'post'] && $path === '/broadcasting/auth';
            })
            ->andReturn($route);

        $app = m::mock(Container::class);
        $app->shouldReceive('offsetGet')->with('router')->andReturn($router);

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->routes();
    }

    public function testUserRoutesExcludesCsrfMiddleware(): void
    {
        $route = m::mock(Route::class);
        $route->shouldReceive('withoutMiddleware')
            ->once()
            ->with([PreventRequestForgery::class])
            ->andReturnSelf();

        $router = m::mock('router');
        $router->shouldReceive('group')
            ->once()
            ->withArgs(function ($attributes, $callback) use ($router) {
                $this->assertSame(['middleware' => ['web']], $attributes);
                $callback($router);
                return true;
            });
        $router->shouldReceive('match')
            ->once()
            ->withArgs(function ($methods, $path) {
                return $methods === ['get', 'post'] && $path === '/broadcasting/user-auth';
            })
            ->andReturn($route);

        $app = m::mock(Container::class);
        $app->shouldReceive('offsetGet')->with('router')->andReturn($router);

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->userRoutes();
    }

    public function testRoutesAreNotRegisteredWhenCached(): void
    {
        $app = m::mock(Container::class . ',' . CachesRoutes::class);
        $app->shouldReceive('routesAreCached')->once()->andReturnTrue();
        $app->shouldNotReceive('offsetGet');

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->routes();
    }

    public function testExtendBindsCallbackToManager(): void
    {
        $app = m::mock(Container::class);
        $broadcastManager = new BroadcastManager($app);

        $boundInstance = null;
        $broadcastManager->extend('custom', function () use (&$boundInstance) {
            $boundInstance = $this;

            return m::mock(\Hypervel\Contracts\Broadcasting\Broadcaster::class);
        });

        $app->shouldReceive('make')->with('config')->andReturn(
            m::mock()->shouldReceive('get')->with('broadcasting.connections.test')->andReturn([
                'driver' => 'custom',
            ])->getMock()
        );

        $broadcastManager->connection('test');
        $this->assertSame($broadcastManager, $boundInstance);
    }

    public function testDriverCreationFailureWrapsExceptionWithConnectionName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create broadcaster for connection "failing" with error: Redis unavailable.');

        $app = m::mock(Container::class);
        $app->shouldReceive('make')->with('config')->andReturn(
            m::mock()->shouldReceive('get')->with('broadcasting.connections.failing')->andReturn([
                'driver' => 'redis',
            ])->getMock()
        );
        $app->shouldReceive('make')->with(\Hypervel\Contracts\Redis\Factory::class)
            ->andThrow(new Exception('Redis unavailable.'));

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->connection('failing');
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
