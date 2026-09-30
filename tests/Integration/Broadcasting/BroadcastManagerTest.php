<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Broadcasting;

use Ably\AblyRest;
use Exception;
use Hypervel\Broadcasting\Broadcasters\AblyBroadcaster;
use Hypervel\Broadcasting\Broadcasters\Broadcaster as BaseBroadcaster;
use Hypervel\Broadcasting\Broadcasters\MercureBroadcaster;
use Hypervel\Broadcasting\Broadcasters\PusherBroadcaster;
use Hypervel\Broadcasting\Broadcasters\RedisBroadcaster;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Broadcasting\BroadcastManager;
use Hypervel\Broadcasting\BroadcastPoolProxy;
use Hypervel\Broadcasting\Channel;
use Hypervel\Broadcasting\Mercure\Hub as MercureHub;
use Hypervel\Broadcasting\UniqueBroadcastEvent;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Contracts\Broadcasting\Broadcaster;
use Hypervel\Contracts\Broadcasting\Factory as BroadcastingFactory;
use Hypervel\Contracts\Broadcasting\ShouldBeUnique;
use Hypervel\Contracts\Broadcasting\ShouldBroadcast;
use Hypervel\Contracts\Broadcasting\ShouldBroadcastNow;
use Hypervel\Contracts\Broadcasting\ShouldRescue;
use Hypervel\Contracts\Cache\Lock;
use Hypervel\Contracts\Cache\LockProvider;
use Hypervel\Contracts\Cache\Repository as Cache;
use Hypervel\Contracts\Cache\Store as CacheStore;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Foundation\CachesRoutes;
use Hypervel\Contracts\ObjectPool\Factory as PoolFactory;
use Hypervel\Contracts\Queue\Factory as QueueFactory;
use Hypervel\Contracts\Redis\Factory as Redis;
use Hypervel\Contracts\Routing\UrlGenerator as UrlGeneratorContract;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Redis\RedisProxy;
use Hypervel\Routing\Route;
use Hypervel\Routing\RouteCollection;
use Hypervel\Routing\UrlGenerator;
use Hypervel\Support\Facades\Broadcast;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Testing\Fakes\QueueFake;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Pusher\Pusher;
use RuntimeException;
use stdClass;
use Symfony\Component\Mercure\Exception\InvalidArgumentException as MercureInvalidArgumentException;

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
        // QueueFake ignores connection names, so verify selection separately from its push assertions.
        $queue = m::mock(QueueFake::class, [$this->app])->makePartial();
        $queue->shouldReceive('connection')->once()->with('broadcast-connection')->andReturnSelf();
        Queue::swap($queue);

        Queue::route(TestEvent::class, 'broadcast-queue', 'broadcast-connection');

        Broadcast::queue(new TestEvent);
        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::assertPushedOn('broadcast-queue', BroadcastEvent::class);
    }

    public function testEventsCanBeBroadcastWhenForwardingQueue(): void
    {
        Bus::fake();
        $queue = m::mock(QueueFake::class, [$this->app])->makePartial();
        $queue->shouldReceive('connection')->once()->with('broadcast-connection')->andReturnSelf();
        Queue::swap($queue);

        Queue::forward('broadcast-queue', 'events', 'broadcast-connection');

        Broadcast::queue(new TestForwardedEvent);
        Bus::assertNotDispatched(BroadcastEvent::class);
        Queue::assertPushedOn('broadcast-queue', BroadcastEvent::class);
    }

    #[TestWith([null, 'cloud'])]
    #[TestWith(['explicit', 'explicit'])]
    public function testForwardedConnectionUsesTheBroadcastQueue(?string $connection, string $expectedConnection): void
    {
        $queue = m::mock(QueueFake::class, [$this->app])->makePartial();
        $queue->shouldReceive('connection')->once()->with($expectedConnection)->andReturnSelf();
        Queue::swap($queue);
        Queue::forward('broadcast-queue', 'unused', 'wrong-connection');
        Queue::forward('updates', 'events', 'cloud');
        $event = new class extends TestForwardedEvent {
            public ?string $connection = null;

            /**
             * Select the queue used to broadcast this event.
             */
            public function broadcastQueue(): string
            {
                return 'updates';
            }
        };
        $event->connection = $connection;

        Broadcast::queue($event);

        Queue::assertPushedOn('updates', BroadcastEvent::class);
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
        $lock->shouldReceive('owner')->once()->andReturn('unique-lock-owner');
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
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
        $lock->shouldReceive('owner')->once()->andReturn('unique-lock-owner');
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
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
        $lock->shouldReceive('owner')->once()->andReturn('unique-lock-owner');
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('getStore')->once()->andReturn(m::mock(CacheStore::class, LockProvider::class));
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

    public function testUniqueEventLockIsReleasedWhenQueueResolutionFails(): void
    {
        $cache = new CacheRepository(new ArrayStore);
        $queue = m::mock(QueueFactory::class);
        $failure = new Exception('Queue unavailable.');
        $lockKey = 'laravel_unique_job:' . hash('xxh128', TestEventUnique::class) . ':';

        $this->app->instance(Cache::class, $cache);
        $this->app->instance(QueueFactory::class, $queue);
        $queue->shouldReceive('connection')->once()->andThrow($failure);

        try {
            Broadcast::queue(new TestEventUnique);

            $this->fail('Expected queue resolution to fail.');
        } catch (Exception $exception) {
            $this->assertSame($failure, $exception);
        }

        $replacement = $cache->lock($lockKey, 10);
        $this->assertTrue($replacement->get());
        $replacement->forceRelease();
    }

    public function testRescuedUniqueEventPublicationFailureReleasesItsLock(): void
    {
        $cache = new CacheRepository(new ArrayStore);
        $queue = m::mock(QueueFactory::class);
        $lockKey = 'laravel_unique_job:' . hash('xxh128', TestEventUniqueRescue::class) . ':';

        $this->app->instance(Cache::class, $cache);
        $this->app->instance(QueueFactory::class, $queue);
        $queue->shouldReceive('connection')->once()->andThrow(new Exception('Queue unavailable.'));

        Broadcast::queue(new TestEventUniqueRescue);

        $replacement = $cache->lock($lockKey, 10);
        $this->assertTrue($replacement->get());
        $replacement->forceRelease();
    }

    public function testUniqueEventWithNullableCacheResolverUsesDefaultCache(): void
    {
        Queue::fake();

        $event = new TestEventUniqueWithNullableCache;
        Broadcast::queue($event);

        Queue::assertPushed(UniqueBroadcastEvent::class);

        $lockKey = 'laravel_unique_job:' . hash('xxh128', TestEventUniqueWithNullableCache::class) . ':';
        $this->assertFalse($this->app->get(Cache::class)->lock($lockKey, 10)->get());
    }

    public function testThrowExceptionWhenUnknownStoreIsUsed(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Broadcast connection [alien_connection] is not defined.'));

        $app = new Container;
        $app->singleton('config', fn (): Repository => new Repository([
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
                    '1' => ['driver' => 'null'],
                    '0' => ['driver' => 'null'],
                ],
            ],
        ]));

        $manager = new BroadcastManager($app);

        $this->assertSame($manager->connection(BroadcastUnitIdentifier::Primary), $manager->connection('Primary'));
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
        $broadcastManager->addPoolableDriver('custom');

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
        $manager->addPoolableDriver('custom');

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
        $this->assertSame([], $app->make(PoolFactory::class)->getPools());
        $this->assertSame([], $manager->getPoolableDrivers());

        $replacementPusher = m::mock(Pusher::class);
        $manager->setDefaultDriver('pusher');
        $manager->setPusher($replacementPusher);
        $this->assertSame($replacementPusher, $manager->getPusher());

        $replacementAbly = new AblyRest('replacement:key');
        $manager->setDefaultDriver('ably');
        $manager->setAbly($replacementAbly);
        $this->assertSame($replacementAbly, $manager->getAbly());
    }

    public function testPublicPusherFactoryAcceptsAPartialRecord(): void
    {
        $manager = new BroadcastManager(new Container);

        $this->assertInstanceOf(Pusher::class, $manager->pusher([
            'key' => 'key',
            'secret' => 'secret',
            'app_id' => 'app',
        ]));
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
        $manager->addPoolableDriver('custom');

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

    public function testPurgeAllowsMercurePoolReconfigurationAndClosesAnUncachedPool(): void
    {
        $app = $this->getApp(['broadcasting' => ['connections' => ['mercure' => $this->mercureConfig([
            'driver' => 'mercure',
            'pool' => ['max_objects' => 2],
        ])]]]);
        $manager = new BroadcastManager($app);
        $hub = $manager->connection('mercure')->getHub();
        $pools = $app->make(PoolFactory::class);
        $pools->getOrCreate($hub->getDefinition(), static fn (): stdClass => new stdClass);

        $app->make('config')->set('broadcasting.connections.mercure.pool.max_objects', 3);
        $manager->purge('mercure');
        $hub = $manager->connection('mercure')->getHub();
        $pool = $pools->getOrCreate($hub->getDefinition(), static fn (): stdClass => new stdClass);
        $this->assertSame(3, $pool->getOptions()->maxObjects);

        $manager->forgetDrivers();
        $this->assertTrue($pools->has($hub->getPoolName()));
        $manager->purge('mercure');
        $this->assertFalse($pools->has($hub->getPoolName()));
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
        $manager->addPoolableDriver('redis');

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

    public function testBroadcastManagerCanResolveBackedEnumConnection(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new Repository([
            'broadcasting' => [
                'connections' => [
                    'log' => ['driver' => 'log'],
                ],
            ],
        ]));

        $driver = m::mock(Broadcaster::class);
        $manager = new BroadcastManager($app);
        $manager->extend('log', static fn () => $driver);

        $this->assertSame($driver, $manager->connection(BroadcastConnectionName::Log));
        $this->assertSame($manager->connection('log'), $manager->connection(BroadcastConnectionName::Log));
    }

    public function testBroadcastManagerCanResolveBackedEnumDriver(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new Repository([
            'broadcasting' => [
                'connections' => [
                    'log' => ['driver' => 'log'],
                ],
            ],
        ]));

        $driver = m::mock(Broadcaster::class);
        $manager = new BroadcastManager($app);
        $manager->extend('log', static fn () => $driver);

        $this->assertSame($driver, $manager->driver(BroadcastConnectionName::Log));
        $this->assertSame($manager->driver('log'), $manager->driver(BroadcastConnectionName::Log));
    }

    public function testSetDefaultDriverAcceptsBackedEnum(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new Repository([
            'broadcasting' => [
                'default' => 'null',
                'connections' => [],
            ],
        ]));

        $manager = new BroadcastManager($app);
        $manager->setDefaultDriver(BroadcastConnectionName::Log);

        $this->assertSame('log', $app->make('config')->get('broadcasting.default'));
    }

    public function testPurgeAcceptsBackedEnum(): void
    {
        $app = new Container;
        $app->singleton('config', fn () => new Repository([
            'broadcasting' => [
                'connections' => [
                    'log' => ['driver' => 'log'],
                ],
            ],
        ]));

        $manager = new BroadcastManager($app);
        $manager->extend('log', static fn () => m::mock(Broadcaster::class));

        $instance1 = $manager->connection(BroadcastConnectionName::Log);
        $manager->purge(BroadcastConnectionName::Log);
        $instance2 = $manager->connection(BroadcastConnectionName::Log);

        $this->assertNotSame($instance1, $instance2);
    }

    public function testMercureRequiresAUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"url"');

        (new BroadcastManager($this->getApp([])))->mercure(['secret' => str_repeat('s', 32)]);
    }

    public function testMercureRequiresASecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"secret"');

        (new BroadcastManager($this->getApp([])))->mercure(['url' => 'https://hub.test/.well-known/mercure']);
    }

    public function testMercureRejectsAShortHmacSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');

        (new BroadcastManager($this->getApp([])))->mercure($this->mercureConfig(['secret' => 'too-short']));
    }

    public function testMercureRejectsANegativePublishExpiration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('publish_expiration');

        (new BroadcastManager($this->getApp([])))->mercure($this->mercureConfig(['publish_expiration' => -1]));
    }

    public function testMercureRejectsAPublishExpirationTruncatingToZeroSeconds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('publish_expiration');

        (new BroadcastManager($this->getApp([])))->mercure($this->mercureConfig(['publish_expiration' => 0.01]));
    }

    public function testMercureAcceptsASubMinutePublishExpiration(): void
    {
        $hub = (new BroadcastManager($this->getApp([])))->mercure($this->mercureConfig(['publish_expiration' => 0.5]));

        $claims = $this->decodeJwtClaims($hub->getProvider()->getJwt());

        $this->assertEqualsWithDelta(30, $claims['exp'] - $claims['iat'], 1);
    }

    public function testMercureRejectsANonPositiveSubscribeExpiration(): void
    {
        $manager = new BroadcastManager($this->getApp([
            'broadcasting' => ['connections' => ['mercure' => $this->mercureConfig(['driver' => 'mercure', 'subscribe_expiration' => 0])]],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('subscribe_expiration');

        $manager->connection('mercure');
    }

    public function testMercureRejectsASubscribeExpirationTruncatingToZeroSeconds(): void
    {
        $manager = new BroadcastManager($this->getApp([
            'broadcasting' => ['connections' => ['mercure' => $this->mercureConfig(['driver' => 'mercure', 'subscribe_expiration' => 0.01])]],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('subscribe_expiration');

        $manager->connection('mercure');
    }

    public function testMercureAcceptsASubMinuteSubscribeExpiration(): void
    {
        $manager = new BroadcastManager($this->getApp([
            'broadcasting' => ['connections' => ['mercure' => $this->mercureConfig(['driver' => 'mercure', 'subscribe_expiration' => 0.5])]],
        ]));

        $this->assertInstanceOf(MercureBroadcaster::class, $manager->connection('mercure'));
    }

    public function testMercureRejectsAMalformedEncryptionKey(): void
    {
        $manager = new BroadcastManager($this->getApp([
            'broadcasting' => ['connections' => ['mercure' => $this->mercureConfig(['driver' => 'mercure', 'encryption_key' => 'not-a-valid-key'])]],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('encryption_key');

        $manager->connection('mercure');
    }

    #[TestWith([null])]
    #[TestWith(['__host-mercure'])]
    public function testMercureRejectsASecurePrefixedCookieOverAPlainHttpPublicUrl(?string $cookieName): void
    {
        $manager = new BroadcastManager($this->getApp([
            'broadcasting' => ['connections' => ['mercure' => $this->mercureConfig([
                'driver' => 'mercure',
                'public_url' => 'http://localhost/.well-known/mercure',
                'cookie_name' => $cookieName,
            ])]],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cookie_name');

        $manager->connection('mercure');
    }

    #[TestWith(['mercureAuthorization'])]
    #[TestWith(['__mercure'])]
    public function testMercureAcceptsAPlainHttpPublicUrlWithAnUnprefixedCookieName(string $cookieName): void
    {
        $manager = new BroadcastManager($this->getApp([
            'broadcasting' => ['connections' => ['mercure' => $this->mercureConfig([
                'driver' => 'mercure',
                'public_url' => 'http://localhost/.well-known/mercure',
                'cookie_name' => $cookieName,
            ])]],
        ]));

        $this->assertInstanceOf(MercureBroadcaster::class, $manager->connection('mercure'));
    }

    public function testMercureAcceptsABase64PrefixedEncryptionKey(): void
    {
        $manager = new BroadcastManager($this->getApp([
            'broadcasting' => ['connections' => ['mercure' => $this->mercureConfig([
                'driver' => 'mercure',
                'encryption_key' => 'base64:' . base64_encode(random_bytes(32)),
            ])]],
        ]));

        $this->assertInstanceOf(MercureBroadcaster::class, $manager->connection('mercure'));
    }

    public function testMercureDefaultsTheRfc9068Claims(): void
    {
        $manager = new BroadcastManager($this->getApp(['app' => ['url' => 'https://app.test']]));

        $hub = $manager->mercure($this->mercureConfig());

        $claims = $this->decodeJwtClaims($hub->getFactory()->create());

        $this->assertSame('https://app.test', $claims['iss']);
        $this->assertSame('https://app.test', $claims['client_id']);
        $this->assertSame('https://hub.test/.well-known/mercure', $claims['aud']);
        $this->assertSame('anonymous', $claims['sub']);

        $publishClaims = $this->decodeJwtClaims($hub->getProvider()->getJwt());

        $this->assertSame('https://app.test', $publishClaims['iss']);
        $this->assertSame('https://app.test', $publishClaims['client_id']);
    }

    public function testMercureExplicitClaimsWinOverTheDefaults(): void
    {
        $manager = new BroadcastManager($this->getApp(['app' => ['url' => 'https://app.test']]));

        $hub = $manager->mercure($this->mercureConfig([
            'claims' => ['iss' => 'https://issuer.test', 'aud' => 'https://audience.test', 'client_id' => 'my-app'],
        ]));

        $claims = $this->decodeJwtClaims($hub->getFactory()->create());

        $this->assertSame('https://issuer.test', $claims['iss']);
        $this->assertSame('https://audience.test', $claims['aud']);
        $this->assertSame('my-app', $claims['client_id']);
    }

    public function testMercureSideSpecificSecretsTakePrecedence(): void
    {
        $manager = new BroadcastManager($this->getApp([]));

        $hub = $manager->mercure($this->mercureConfig([
            'subscribe_secret' => str_repeat('a', 32),
            'publish_secret' => str_repeat('b', 32),
        ]));

        $this->assertJwtSignedWith($hub->getFactory()->create(), str_repeat('a', 32));
        $this->assertJwtSignedWith($hub->getProvider()->getJwt(), str_repeat('b', 32));
    }

    // REMOVED: FrankenPHP's in-process hub, missing-URL fallback and mercure_publish fixture are unsupported on Swoole.
    // The shared audience cases are retained against the remote hub below.

    #[DataProvider('mercureAudienceProvider')]
    public function testMercureAudience(string $appUrl, array $config, string|array $audience): void
    {
        $manager = new BroadcastManager($this->getApp(['app' => ['url' => $appUrl]]));
        $hub = $manager->mercure($config + ['url' => '/.well-known/mercure', 'secret' => str_repeat('s', 32)]);

        $claims = $this->decodeJwtClaims($hub->getFactory()->create());
        $publishClaims = $this->decodeJwtClaims($hub->getProvider()->getJwt());

        $this->assertSame($audience, $claims['aud']);
        $this->assertSame($audience, $publishClaims['aud']);
    }

    public static function mercureAudienceProvider(): array
    {
        return [
            'trailing slash' => [
                'https://app.test/', [], 'https://app.test/.well-known/mercure',
            ],
            'application path' => [
                'https://app.test/app/', [], 'https://app.test/.well-known/mercure',
            ],
            'application query and fragment' => [
                'https://app.test/app/?source=test#section', [], 'https://app.test/.well-known/mercure',
            ],
            'non-default port' => [
                'https://app.test:8443/app', [], 'https://app.test:8443/.well-known/mercure',
            ],
            'IPv6 host' => [
                'https://[::1]:8443/app', [], 'https://[::1]:8443/.well-known/mercure',
            ],
            'plain HTTP' => [
                'http://localhost:8080', [], 'http://localhost:8080/.well-known/mercure',
            ],
            'empty public URL' => [
                'https://app.test', ['public_url' => ''], 'https://app.test/.well-known/mercure',
            ],
            'explicit public URL' => [
                'https://app.test', ['public_url' => 'https://hub.test/events'], 'https://hub.test/events',
            ],
            'explicit audience' => [
                'https://app.test', ['claims' => ['aud' => 'urn:mercure:hub']], 'urn:mercure:hub',
            ],
            'multiple explicit audiences' => [
                'https://app.test',
                ['claims' => ['aud' => ['https://hub.test/.well-known/mercure', 'urn:mercure:hub']]],
                ['https://hub.test/.well-known/mercure', 'urn:mercure:hub'],
            ],
            'different issuer' => [
                'https://app.test', ['claims' => ['iss' => 'https://issuer.test']], 'https://app.test/.well-known/mercure',
            ],
        ];
    }

    #[TestWith(['urn:mercure:one'])]
    #[TestWith([['urn:mercure:one', 'urn:mercure:two']])]
    public function testMercurePreservesOverriddenAudiences(string|array $audience): void
    {
        $manager = new class($this->getApp(['app' => ['url' => 'https://app.test']]), $audience) extends BroadcastManager {
            /**
             * Create a manager with application-specific audience claims.
             */
            public function __construct(ContainerContract $app, protected string|array $audience)
            {
                parent::__construct($app);
            }

            /**
             * Supply the application's audience through the upstream extension point.
             */
            protected function mercureClaims(array $config): array
            {
                return ['aud' => $this->audience] + parent::mercureClaims($config);
            }
        };
        $hub = $manager->mercure($this->mercureConfig(['url' => '/.well-known/mercure']));

        $this->assertSame($audience, $this->decodeJwtClaims($hub->getFactory()->create())['aud']);
        $this->assertSame($audience, $this->decodeJwtClaims($hub->getProvider()->getJwt())['aud']);
    }

    public function testMercureAcceptsAUrlGeneratorContractImplementation(): void
    {
        $application = $this->getApp([]);
        $urls = m::mock(UrlGeneratorContract::class);
        $urls->shouldReceive('to')->with('/.well-known/mercure')->andReturn('https://hub.test/events');
        $application->instance('url', $urls);
        $hub = (new BroadcastManager($application))->mercure($this->mercureConfig(['url' => '/.well-known/mercure']));

        $this->assertSame('https://hub.test/events', $hub->getUrl());
        $this->assertSame('https://hub.test/events', $hub->getPublicUrl());
        $this->assertSame('https://hub.test/events', $this->decodeJwtClaims($hub->getFactory()->create())['aud']);
        $this->assertSame('https://hub.test/events', $this->decodeJwtClaims($hub->getProvider()->getJwt())['aud']);
    }

    public function testExplicitMercureTokenAudienceDoesNotResolveTheDefaultUrl(): void
    {
        $application = $this->getApp([]);
        $urls = m::mock(UrlGeneratorContract::class);
        $urls->shouldNotReceive('to');
        $application->instance('url', $urls);
        $hub = (new BroadcastManager($application))->mercure($this->mercureConfig(['url' => '/.well-known/mercure']));

        $claims = $this->decodeJwtClaims($hub->getFactory()->create([], ['aud' => 'urn:mercure:custom']));

        $this->assertSame('urn:mercure:custom', $claims['aud']);
    }

    public function testRelativeMercureUrlsAndAudienceFollowTheCurrentOrigin(): void
    {
        $application = $this->getApp(['app' => ['url' => 'https://app.test']]);
        $manager = new BroadcastManager($application);
        $hub = $manager->mercure($this->mercureConfig(['url' => '/.well-known/mercure']));
        $urls = $application->make('url');

        foreach (['https://first.test', 'https://second.test'] as $origin) {
            $urls->useOrigin($origin);
            $this->assertSame($origin . '/.well-known/mercure', $hub->getUrl());
            $this->assertSame($origin . '/.well-known/mercure', $hub->getPublicUrl());
            $this->assertSame($origin . '/.well-known/mercure', $this->decodeJwtClaims($hub->getFactory()->create())['aud']);
            $this->assertSame($origin . '/.well-known/mercure', $this->decodeJwtClaims($hub->getProvider()->getJwt())['aud']);
        }
    }

    public function testRelativeMercureCookieSchemeIsValidatedDuringAuthorization(): void
    {
        $manager = new BroadcastManager($this->getApp([
            'app' => ['url' => 'http://app.test'],
            'broadcasting' => ['connections' => ['mercure' => $this->mercureConfig([
                'driver' => 'mercure', 'url' => '/.well-known/mercure',
            ])]],
        ]));
        $broadcaster = $manager->connection('mercure');
        $request = Request::create('https://app.test/broadcasting/auth', 'POST', ['channel_names' => ['news']]);
        $request->setUserResolver(static fn (): null => null);
        RequestContext::set($request);

        $response = $broadcaster->auth($request);
        $this->assertTrue($response->headers->getCookies()[0]->isSecure());

        $request = Request::create('http://app.test/broadcasting/auth', 'POST', ['channel_names' => ['news']]);
        $request->setUserResolver(static fn (): null => null);
        RequestContext::set($request);
        $manager->getApplication()->make('url')->setRequest($request);
        $this->expectException(MercureInvalidArgumentException::class);
        $broadcaster->auth($request);
    }

    public function testMercureKeepsPoolControlsAndCustomCreatorsDoNotReceiveThem(): void
    {
        $config = $this->mercureConfig([
            'driver' => 'mercure',
            'pool' => ['max_objects' => 2],
        ]);
        $manager = new BroadcastManager($this->getApp(['broadcasting' => ['connections' => ['mercure' => $config]]]));
        $hub = $manager->connection('mercure')->getHub();

        $this->assertInstanceOf(MercureHub::class, $hub);
        $this->assertSame(2, $hub->getDefinition()->options->maxObjects);

        $manager->forgetDrivers();
        $driver = m::mock(Broadcaster::class);
        $received = null;
        $manager->extend('mercure', static function (ContainerContract $app, array $config) use (&$received, $driver): Broadcaster {
            $received = $config;
            return $driver;
        });

        $this->assertSame($driver, $manager->connection('mercure'));
        $this->assertArrayNotHasKey('pool', $received);
    }

    /**
     * Build a remote Mercure connection configuration.
     */
    protected function mercureConfig(array $overrides = []): array
    {
        return $overrides + [
            'url' => 'https://hub.test/.well-known/mercure',
            'secret' => str_repeat('s', 32),
        ];
    }

    /**
     * Decode generated token claims for assertions.
     */
    protected function decodeJwtClaims(string $jwt): array
    {
        $payload = explode('.', $jwt)[1];

        return json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    }

    /**
     * Check that a token uses the configured signing secret.
     */
    protected function assertJwtSignedWith(string $jwt, string $secret): void
    {
        [$header, $payload, $signature] = explode('.', $jwt);

        $this->assertSame(
            rtrim(strtr(base64_encode(hash_hmac('sha256', $header . '.' . $payload, $secret, true)), '+/', '-_'), '='),
            $signature
        );
    }

    /**
     * Create an isolated application with URL generation and client pooling.
     */
    protected function getApp(array $userConfig): Container
    {
        $app = new Container;
        $app->instance('config', new Repository($userConfig));
        $app->instance('url', new UrlGenerator(new RouteCollection, Request::create($userConfig['app']['url'] ?? 'http://localhost')));
        $app->singleton(PoolFactory::class, PoolManager::class);

        return $app;
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

enum BroadcastConnectionName: string
{
    case Log = 'log';
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

class TestForwardedEvent implements ShouldBroadcast
{
    public string $queue = 'broadcast-queue';

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

class TestEventUniqueWithNullableCache extends TestEventUnique
{
    public function uniqueVia(): ?Cache
    {
        return null;
    }
}

class TestEventUniqueRescue extends TestEventUnique implements ShouldRescue
{
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

enum BroadcastIntegerIdentifier: int
{
    case Primary = 1;
    case Zero = 0;
}
