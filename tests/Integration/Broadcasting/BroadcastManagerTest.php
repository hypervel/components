<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Broadcasting;

use Exception;
use Hypervel\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\UniqueBroadcastEvent;
use Hypervel\Container\Container;
use Hypervel\Contracts\Broadcasting\ShouldBeUnique;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Contracts\Broadcasting\ShouldRescue;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\Contracts\ObjectPool;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use RuntimeException;

class BroadcastManagerTest extends TestCase
{
    public function testEventCanBeBroadcastNow()
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventNow);

        Bus::assertDispatched(BroadcastEvent::class);
        Queue::assertNotPushed(BroadcastEvent::class);
    }

    public function testEventsCanBeBroadcast()
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEvent);

        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::assertPushed(BroadcastEvent::class);
    }

    public function testEventsCanBeBroadcastUsingQueueRoutes()
    {
        Bus::fake();
        Queue::fake();

        Queue::route(TestEvent::class, 'broadcast-queue', 'broadcast-connection');

        Broadcast::queue(new TestEvent);
        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::connection('broadcast-connection')->assertPushedOn('broadcast-queue', BroadcastEvent::class);
    }

    public function testEventsCanBeRescued()
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventRescue);

        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::assertPushed(BroadcastEvent::class);
    }

    public function testNowEventsCanBeRescued()
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventNowRescue);

        Bus::assertDispatched(BroadcastEvent::class);
        Queue::assertNotPushed(BroadcastEvent::class);
    }

    public function testUniqueEventsCanBeBroadcast()
    {
        Bus::fake();
        Queue::fake();

        $lockKey = 'laravel_unique_job:' . TestEventUnique::class . ':';
        $lock = m::mock(\Hypervel\Contracts\Cache\Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with($lockKey, 0)->andReturn($lock);
        $this->app->singleton(Cache::class, fn () => $cache);

        Broadcast::queue(new TestEventUnique);

        Bus::assertNotDispatched(UniqueBroadcastEvent::class);
        Queue::assertPushed(UniqueBroadcastEvent::class);
    }

    public function testUniqueEventsCanBeBroadcastWithUniqueIdFromProperty()
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventUniqueWithIdProperty);

        Bus::assertNotDispatched(UniqueBroadcastEvent::class);
        Queue::assertPushed(UniqueBroadcastEvent::class);

        $lockKey = 'laravel_unique_job:' . TestEventUniqueWithIdProperty::class . ':unique-id-property';
        $this->assertFalse($this->app->get(Cache::class)->lock($lockKey, 10)->get());
    }

    public function testUniqueEventsCanBeBroadcastWithUniqueIdFromMethod()
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventUniqueWithIdMethod);

        Bus::assertNotDispatched(UniqueBroadcastEvent::class);
        Queue::assertPushed(UniqueBroadcastEvent::class);

        $lockKey = 'laravel_unique_job:' . TestEventUniqueWithIdMethod::class . ':unique-id-method';
        $this->assertFalse($this->app->get(Cache::class)->lock($lockKey, 10)->get());
    }

    public function testThrowExceptionWhenUnknownStoreIsUsed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Broadcast connection [alien_connection] is not defined.');

        $app = new Container;
        $app->singleton('config', fn () => new \Hypervel\Config\Repository([
            'broadcasting' => [
                'connections' => [
                    'my_connection' => [
                        'driver' => 'pusher',
                    ],
                ],
            ],
        ]));

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

    public function testAuthenticatedUserResolverWorksThroughPooledManagerDriver(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new \Hypervel\Config\Repository([
            'broadcasting' => [
                'default' => 'test',
                'connections' => [
                    'test' => [
                        'driver' => 'custom',
                        'pool' => [
                            'min_connections' => 0,
                            'max_connections' => 2,
                        ],
                    ],
                ],
            ],
        ]));
        Container::setInstance($app);

        $firstBroadcaster = new ManagerUserAuthenticationBroadcaster($app);
        $secondBroadcaster = new ManagerUserAuthenticationBroadcaster($app);

        $pool = m::mock(ObjectPool::class);
        $pool->shouldReceive('get')
            ->twice()
            ->andReturn($firstBroadcaster, $secondBroadcaster);
        $pool->shouldReceive('release')
            ->once()
            ->with($firstBroadcaster);
        $pool->shouldReceive('release')
            ->once()
            ->with($secondBroadcaster);

        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('create')
            ->once()
            ->withArgs(function (string $name, callable $resolver, array $options): bool {
                $this->assertSame(BroadcastManager::class . ':test', $name);
                $this->assertSame([
                    'min_connections' => 0,
                    'max_connections' => 2,
                ], $options);

                return true;
            })
            ->andReturn($pool);
        $app->instance(PoolFactory::class, $poolFactory);

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->addPoolable('custom');

        $broadcastManager->resolveAuthenticatedUserUsing(function (Request $request): array {
            return ['id' => 'user-' . $request->input('socket_id')];
        });

        $this->assertSame(
            ['id' => 'user-1.1'],
            $broadcastManager->resolveAuthenticatedUser(Request::create('/broadcasting/user-auth', 'POST', ['socket_id' => '1.1']))
        );
        $this->assertSame(
            ['id' => 'user-2.2'],
            $broadcastManager->resolveAuthenticatedUser(Request::create('/broadcasting/user-auth', 'POST', ['socket_id' => '2.2']))
        );
    }

    public function testCustomDriverClosureBoundObjectIsBroadcastManager(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new \Hypervel\Config\Repository([
            'broadcasting' => [
                'connections' => [
                    'test' => [
                        'driver' => 'custom',
                    ],
                ],
            ],
        ]));

        $broadcastManager = new BroadcastManager($app);

        $boundInstance = null;
        $broadcastManager->extend('custom', function () use (&$boundInstance) {
            $boundInstance = $this;

            return m::mock(\Hypervel\Contracts\Broadcasting\Broadcaster::class);
        });

        $broadcastManager->connection('test');
        $this->assertSame($broadcastManager, $boundInstance);
    }

    public function testThrowExceptionWhenDriverCreationFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create broadcaster for connection "failing" with error: Redis unavailable.');

        $app = new Container;
        $app->singleton('config', fn () => new \Hypervel\Config\Repository([
            'broadcasting' => [
                'connections' => [
                    'failing' => [
                        'driver' => 'redis',
                    ],
                ],
            ],
        ]));
        $app->singleton('redis', fn () => throw new Exception('Redis unavailable.'));

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

class TestEventUniqueWithIdProperty extends TestEventUnique
{
    public string $uniqueId = 'unique-id-property';
}

class TestEventUniqueWithIdMethod extends TestEventUnique
{
    public function uniqueId(): string
    {
        return 'unique-id-method';
    }
}

class TestEventRescue implements ShouldBroadcast, ShouldRescue
{
    public function broadcastOn(): array
    {
        return [];
    }
}

class TestEventNowRescue implements ShouldBroadcastNow, ShouldRescue
{
    public function broadcastOn(): array
    {
        return [];
    }
}

class ManagerUserAuthenticationBroadcaster extends BaseBroadcaster
{
    public function __construct(
        protected ContainerContract $container
    ) {
    }

    public function auth(Request $request): mixed
    {
        return null;
    }

    public function validAuthenticationResponse(Request $request, mixed $result): mixed
    {
        return null;
    }

    public function broadcast(array $channels, string $event, array $payload = []): void
    {
    }
}
