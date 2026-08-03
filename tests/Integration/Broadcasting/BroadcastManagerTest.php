<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Broadcasting;

use Ably\AblyRest;
use Exception;
use Hypervel\Broadcasting\Broadcasters\AblyBroadcaster;
use Hypervel\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Broadcasting\Broadcasters\RedisBroadcaster;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Broadcasting\BroadcastPoolProxy;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\UniqueBroadcastEvent;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Hypervel\Contracts\Broadcasting\ShouldBeUnique;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Contracts\Broadcasting\ShouldRescue;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Pusher\Pusher;
use RuntimeException;

class BroadcastManagerTest extends TestCase
{
    public function testEventCanBeBroadcastNow(): void
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventNow);

        Bus::assertDispatched(BroadcastEvent::class);
        Queue::assertNotPushed(BroadcastEvent::class);
    }

    public function testEnumEventCanBeBroadcastNowWithoutCloning(): void
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(TestEventNowEnum::Created);

        Bus::assertDispatched(
            BroadcastEvent::class,
            static fn (BroadcastEvent $job): bool => $job->event === TestEventNowEnum::Created,
        );
        Queue::assertNotPushed(BroadcastEvent::class);
    }

    public function testEventsCanBeBroadcast(): void
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEvent);

        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::assertPushed(BroadcastEvent::class);
    }

    public function testEnumEventCanBeQueuedWithoutCloning(): void
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(TestEventEnum::Created);

        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::assertPushed(
            BroadcastEvent::class,
            static fn (BroadcastEvent $job): bool => $job->event === TestEventEnum::Created,
        );
    }

    public function testQueuedOrdinaryEventIsClonedOnce(): void
    {
        Bus::fake();
        Queue::fake();
        CloneCountingBroadcastEvent::$clones = 0;
        $event = new CloneCountingBroadcastEvent;

        Broadcast::queue($event);

        Queue::assertPushed(
            BroadcastEvent::class,
            static fn (BroadcastEvent $job): bool => $job->event !== $event,
        );
        $this->assertSame(1, CloneCountingBroadcastEvent::$clones);
    }

    public function testEventsCanBeBroadcastUsingQueueRoutes(): void
    {
        Bus::fake();
        Queue::fake();

        Queue::route(TestEvent::class, 'broadcast-queue', 'broadcast-connection');

        Broadcast::queue(new TestEvent);
        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::connection('broadcast-connection')->assertPushedOn('broadcast-queue', BroadcastEvent::class);
    }

    public function testEventsCanBeRescued(): void
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventRescue);

        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::assertPushed(BroadcastEvent::class);
    }

    public function testNowEventsCanBeRescued(): void
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventNowRescue);

        Bus::assertDispatched(BroadcastEvent::class);
        Queue::assertNotPushed(BroadcastEvent::class);
    }

    public function testUniqueEventsCanBeBroadcast(): void
    {
        Bus::fake();
        Queue::fake();

        $lockKey = 'laravel_unique_job:' . hash('xxh128', TestEventUnique::class) . ':';
        $lock = m::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with($lockKey, 0)->andReturn($lock);
        $this->app->singleton(Cache::class, fn () => $cache);

        Broadcast::queue(new TestEventUnique);

        Bus::assertNotDispatched(UniqueBroadcastEvent::class);
        Queue::assertPushed(UniqueBroadcastEvent::class);
    }

    public function testUniqueEnumEventCanBeQueuedWithoutCloning(): void
    {
        Bus::fake();
        Queue::fake();

        $lockKey = 'laravel_unique_job:' . hash('xxh128', TestEventUniqueEnum::class) . ':';
        $lock = m::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with($lockKey, 0)->andReturn($lock);
        $this->app->singleton(Cache::class, fn () => $cache);

        Broadcast::queue(TestEventUniqueEnum::Created);

        Queue::assertPushed(
            UniqueBroadcastEvent::class,
            static fn (UniqueBroadcastEvent $job): bool => $job->event === TestEventUniqueEnum::Created,
        );
    }

    public function testUniqueEventConstructsOnlyTheSelectedWrapper(): void
    {
        Bus::fake();
        Queue::fake();
        CloneCountingUniqueBroadcastEvent::$clones = 0;

        $lockKey = 'laravel_unique_job:' . hash('xxh128', CloneCountingUniqueBroadcastEvent::class) . ':';
        $lock = m::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(true);
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('lock')->with($lockKey, 0)->andReturn($lock);
        $this->app->singleton(Cache::class, fn () => $cache);

        Broadcast::queue(new CloneCountingUniqueBroadcastEvent);

        Queue::assertPushed(UniqueBroadcastEvent::class);
        $this->assertSame(1, CloneCountingUniqueBroadcastEvent::$clones);
    }

    public function testUniqueEventsCanBeBroadcastWithUniqueIdFromProperty(): void
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventUniqueWithIdProperty);

        Bus::assertNotDispatched(UniqueBroadcastEvent::class);
        Queue::assertPushed(UniqueBroadcastEvent::class);

        $lockKey = 'laravel_unique_job:' . hash('xxh128', TestEventUniqueWithIdProperty::class) . ':unique-id-property';
        $this->assertFalse($this->app->get(Cache::class)->lock($lockKey, 10)->get());
    }

    public function testUniqueEventsCanBeBroadcastWithUniqueIdFromMethod(): void
    {
        Bus::fake();
        Queue::fake();

        Broadcast::queue(new TestEventUniqueWithIdMethod);

        Bus::assertNotDispatched(UniqueBroadcastEvent::class);
        Queue::assertPushed(UniqueBroadcastEvent::class);

        $lockKey = 'laravel_unique_job:' . hash('xxh128', TestEventUniqueWithIdMethod::class) . ':unique-id-method';
        $this->assertFalse($this->app->get(Cache::class)->lock($lockKey, 10)->get());
    }

    public function testThrowExceptionWhenUnknownStoreIsUsed(): void
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

    public function testProviderResolvesManagerFactoryAndSelectedBroadcasterIdentities(): void
    {
        $manager = $this->app->make(BroadcastManager::class);
        $factory = $this->app->make(BroadcastingFactory::class);
        $broadcaster = $this->app->make(Broadcaster::class);

        $this->assertSame($manager, $factory);
        $this->assertSame($manager->connection(), $broadcaster);
    }

    public function testEnumIdentifiersResolveSetDefaultsAndPurge(): void
    {
        $app = new Container;
        $app->instance('config', new Repository([
            'broadcasting' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'null'],
                    'Primary' => ['driver' => 'null'],
                    'primary' => ['driver' => 'null'],
                    '1' => ['driver' => 'null'],
                    '0' => ['driver' => 'null'],
                ],
            ],
        ]));

        $manager = new BroadcastManager($app);

        $this->assertSame($manager->connection(BroadcastUnitIdentifier::Primary), $manager->connection('Primary'));
        $this->assertSame($manager->connection(BroadcastStringIdentifier::Primary), $manager->connection('primary'));
        $this->assertSame($manager->connection(BroadcastIntegerIdentifier::Primary), $manager->connection('1'));
        $zero = $manager->connection(BroadcastIntegerIdentifier::Zero);
        $this->assertSame($zero, $manager->connection('0'));

        $manager->setDefaultDriver(BroadcastIntegerIdentifier::Zero);
        $this->assertSame($manager->connection('0'), $manager->connection());
        $this->assertSame($manager->connection('0'), $manager->connection(''));

        $manager->purge('');
        $this->assertSame($zero, $manager->connection('0'));

        $manager->purge(BroadcastIntegerIdentifier::Zero);
        $replacement = $manager->connection('0');
        $this->assertNotSame($zero, $replacement);

        $manager->purge(null);
        $this->assertNotSame($replacement, $manager->connection('0'));
    }

    #[DataProvider('redisPrefixConfigurations')]
    public function testRedisDriverUsesCanonicalPrefixPrecedence(
        array $sharedOptions,
        array $connectionConfig,
        string $expectedPrefix,
    ): void {
        config()->set('database.redis', [
            'client' => 'phpredis',
            'options' => $sharedOptions,
            'broadcasting' => array_merge([
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
            ], $connectionConfig),
        ]);
        config()->set('broadcasting.connections.redis-test', [
            'driver' => 'redis',
            'connection' => 'broadcasting',
        ]);

        $connection = m::mock(RedisProxy::class);
        $connection->shouldReceive('isCluster')->once()->andReturnFalse();
        $connection->shouldReceive('eval')
            ->once()
            ->with(
                m::type('string'),
                0,
                m::type('string'),
                $expectedPrefix . 'orders',
            );

        $redis = m::mock(Redis::class);
        $redis->shouldReceive('connection')
            ->once()
            ->with('broadcasting')
            ->andReturn($connection);
        $this->app->instance('redis', $redis);

        $manager = new BroadcastManager($this->app);
        $broadcaster = $manager->connection('redis-test');

        $this->assertInstanceOf(RedisBroadcaster::class, $broadcaster);
        $broadcaster->broadcast(['orders'], 'OrderCreated');
    }

    public static function redisPrefixConfigurations(): array
    {
        return [
            'shared options' => [
                ['prefix' => 'shared.'],
                [],
                'shared.',
            ],
            'connection options override shared options' => [
                ['prefix' => 'shared.'],
                ['options' => ['prefix' => 'connection.']],
                'connection.',
            ],
            'top-level connection prefix overrides connection options' => [
                ['prefix' => 'shared.'],
                [
                    'options' => ['prefix' => 'connection.'],
                    'prefix' => 'top-level.',
                ],
                'top-level.',
            ],
            'empty top-level connection prefix overrides inherited prefix' => [
                ['prefix' => 'shared.'],
                ['prefix' => ''],
                '',
            ],
            'scalar prefix is normalized to string' => [
                [],
                ['prefix' => 123],
                '123',
            ],
        ];
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
        $app->shouldReceive('make')->with('router')->andReturn($router);

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
        $app->shouldReceive('make')->with('router')->andReturn($router);

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->userRoutes();
    }

    public function testRoutesAreNotRegisteredWhenCached(): void
    {
        $app = m::mock(Container::class . ',' . CachesRoutes::class);
        $app->shouldReceive('routesAreCached')->once()->andReturnTrue();
        $app->shouldNotReceive('make')->with('router');

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->routes();
    }

    public function testAuthenticatedUserResolverWorksThroughPooledManagerDriver(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new Repository([
            'broadcasting' => [
                'default' => 'test',
                'connections' => [
                    'test' => [
                        'driver' => 'custom',
                        'pool' => [
                            'min_retained_objects' => 0,
                            'max_objects' => 2,
                        ],
                    ],
                ],
            ],
        ]));
        $app->instance(ContainerContract::class, $app);
        $app->singleton(PoolFactory::class, PoolManager::class);
        Container::setInstance($app);

        $broadcastManager = new BroadcastManager($app);
        $broadcastManager->extend(
            'custom',
            fn () => new ManagerUserAuthenticationBroadcaster($app)
        );
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

    public function testEquivalentConnectionsConvergeAndCustomCreatorNeverReceivesPoolMetadata(): void
    {
        $connection = [
            'driver' => 'custom',
            'key' => 'shared',
            'pool' => ['max_objects' => 2],
        ];
        $app = $this->poolingApplication([
            'first' => $connection,
            'second' => $connection,
        ]);
        $received = null;
        $manager = new BroadcastManager($app);
        $manager->extend(
            'custom',
            function (ContainerContract $container, array $config) use (&$received): Broadcaster {
                $received = $config;

                return new ManagerUserAuthenticationBroadcaster($container);
            }
        );
        $manager->addPoolable('custom');

        $first = $manager->connection('first');
        $second = $manager->connection('second');

        $this->assertInstanceOf(BroadcastPoolProxy::class, $first);
        $this->assertInstanceOf(BroadcastPoolProxy::class, $second);
        $this->assertSame($first->getPoolName(), $second->getPoolName());

        $first->getChannels();
        $this->assertSame([
            'driver' => 'custom',
            'key' => 'shared',
        ], $received);
    }

    public function testBuiltInSdkDriversResolveDirectlyWithoutDefaultPools(): void
    {
        $app = $this->poolingApplication([
            'reverb' => [
                'driver' => 'reverb',
                'key' => 'key',
                'secret' => 'secret',
                'app_id' => 'app',
                'options' => ['host' => '127.0.0.1'],
            ],
            'pusher' => [
                'driver' => 'pusher',
                'key' => 'key',
                'secret' => 'secret',
                'app_id' => 'app',
                'options' => ['host' => '127.0.0.1'],
            ],
            'ably' => [
                'driver' => 'ably',
                'key' => 'abcd:efg',
            ],
        ]);
        $manager = new BroadcastManager($app);

        $this->assertInstanceOf(PusherBroadcaster::class, $manager->connection('reverb'));
        $pusherBroadcaster = $manager->connection('pusher');
        $ablyBroadcaster = $manager->connection('ably');

        $this->assertInstanceOf(PusherBroadcaster::class, $pusherBroadcaster);
        $this->assertInstanceOf(AblyBroadcaster::class, $ablyBroadcaster);
        $this->assertSame([], $app->make(PoolFactory::class)->pools());
        $this->assertSame([], $manager->getPoolables());

        $replacementPusher = m::mock(Pusher::class);
        $manager->setDefaultDriver('pusher');
        $manager->setPusher($replacementPusher);
        $this->assertSame($replacementPusher, $manager->getPusher());

        $replacementAbly = new AblyRest('replacement:key');
        $manager->setDefaultDriver('ably');
        $manager->setAbly($replacementAbly);
        $this->assertSame($replacementAbly, $manager->getAbly());
    }

    public function testPurgeInvalidatesCachedAndUncachedBroadcasterPoolsWhileForgetIsCacheOnly(): void
    {
        $app = $this->poolingApplication([
            'custom' => ['driver' => 'custom'],
        ]);
        $manager = new BroadcastManager($app);
        $manager->extend(
            'custom',
            fn (ContainerContract $container) => new ManagerUserAuthenticationBroadcaster($container)
        );
        $manager->addPoolable('custom');

        $driver = $manager->connection('custom');
        $this->assertInstanceOf(BroadcastPoolProxy::class, $driver);
        $identity = $driver->getPoolName();
        $pools = $app->make(PoolFactory::class);
        $driver->getChannels();
        $this->assertTrue($pools->has($identity));

        $manager->forgetDrivers();
        $this->assertTrue($pools->has($identity));

        $cachedAgain = $manager->connection('custom');
        $this->assertInstanceOf(BroadcastPoolProxy::class, $cachedAgain);

        $manager->purge('custom');
        $this->assertFalse($pools->has($identity));

        $driver->getChannels();
        $this->assertTrue($pools->has($identity));

        $manager->purge('custom');
        $this->assertFalse($pools->has($identity));
    }

    public function testPooledConstructionFailureNamesTheDriverNotAConvergedConnection(): void
    {
        $connection = ['driver' => 'redis', 'connection' => 'default'];
        $app = $this->poolingApplication([
            'first' => $connection,
            'second' => $connection,
        ]);
        $app->singleton('redis', fn () => throw new Exception('Redis unavailable.'));
        $manager = new BroadcastManager($app);
        $manager->addPoolable('redis');

        $first = $manager->connection('first');
        $second = $manager->connection('second');
        $this->assertInstanceOf(BroadcastPoolProxy::class, $first);
        $this->assertInstanceOf(BroadcastPoolProxy::class, $second);
        $this->assertSame($first->getPoolName(), $second->getPoolName());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create broadcaster for driver "redis" with error: Redis unavailable.');

        $second->auth(Request::create('/broadcasting/auth', 'POST'));
    }

    public function testCustomDriverClosureBoundObjectIsBroadcastManager(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new Repository([
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

            return m::mock(Broadcaster::class);
        });

        $broadcastManager->connection('test');
        $this->assertSame($broadcastManager, $boundInstance);
    }

    public function testCustomDriverStaticClosure(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new Repository([
            'broadcasting' => [
                'connections' => [
                    'test' => ['driver' => 'custom'],
                ],
            ],
        ]));
        $driver = m::mock(Broadcaster::class);
        $manager = new BroadcastManager($app);

        $manager->extend('custom', static fn () => $driver);

        $this->assertSame($driver, $manager->connection('test'));
    }

    public function testInvokableObjectDriverClosure(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new Repository([
            'broadcasting' => [
                'connections' => [
                    'test' => ['driver' => 'custom'],
                ],
            ],
        ]));
        $driver = m::mock(Broadcaster::class);
        $manager = new BroadcastManager($app);
        $creator = new ManagerCustomBroadcastCreator($driver);

        $manager->extend('custom', $creator(...));

        $this->assertSame($driver, $manager->connection('test'));
    }

    public function testThrowExceptionWhenDriverCreationFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create broadcaster for connection "failing" with error: Redis unavailable.');

        $app = new Container;
        $app->singleton('config', fn () => new Repository([
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

    /**
     * Create an application with object pooling and broadcast connections.
     */
    protected function poolingApplication(array $connections): Container
    {
        $app = new Container;
        $app->instance(ContainerContract::class, $app);
        $app->instance('config', new Repository([
            'broadcasting' => [
                'default' => array_key_first($connections),
                'connections' => $connections,
            ],
        ]));
        $app->singleton(PoolFactory::class, PoolManager::class);
        Container::setInstance($app);

        return $app;
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

enum TestEventNowEnum implements ShouldBroadcastNow
{
    case Created;

    public function broadcastOn(): array
    {
        return [];
    }
}

enum TestEventEnum implements ShouldBroadcast
{
    case Created;

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

enum TestEventUniqueEnum implements ShouldBroadcast, ShouldBeUnique
{
    case Created;

    public function broadcastOn(): array
    {
        return [];
    }
}

class CloneCountingBroadcastEvent extends TestEvent
{
    public static int $clones = 0;

    public function __clone(): void
    {
        ++static::$clones;
    }
}

class CloneCountingUniqueBroadcastEvent extends CloneCountingBroadcastEvent implements ShouldBeUnique
{
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

class ManagerCustomBroadcastCreator
{
    public function __construct(
        protected Broadcaster $driver,
    ) {
    }

    public function __invoke(): Broadcaster
    {
        return $this->driver;
    }
}

enum BroadcastUnitIdentifier
{
    case Primary;
}

enum BroadcastStringIdentifier: string
{
    case Primary = 'primary';
}

enum BroadcastIntegerIdentifier: int
{
    case Primary = 1;
    case Zero = 0;
}
