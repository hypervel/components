<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisStore;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Events\Dispatcher as Event;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use Redis;

/**
 * @internal
 * @coversNothing
 */
class CacheManagerTest extends TestCase
{
    public function testCustomDriverClosureBoundObjectIsCacheManager()
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'foo' => [
                        'driver' => 'foo',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);
        $cacheManager = new CacheManager($app);
        $repository = m::mock(CacheRepository::class);
        $cacheManager->extend('foo', fn () => $repository);
        $this->assertEquals($repository, $cacheManager->store('foo'));
    }

    public function testCustomDriverOverridesInternalDrivers()
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'my_store' => [
                        'driver' => 'array',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);
        $cacheManager = new CacheManager($app);

        /** @var CacheRepository|MockInterface */
        $repository = m::mock(CacheRepository::class);
        $repository->shouldReceive('get')->with('foo')->andReturn('bar');

        $cacheManager->extend('array', fn () => $repository);

        $driver = $cacheManager->store('my_store');

        $this->assertSame('bar', $driver->get('foo'));
    }

    public function testItCanBuildRepositories()
    {
        $app = $this->getApp([]);
        $cacheManager = new CacheManager($app);

        $arrayCache = $cacheManager->build(['driver' => 'array']);
        $nullCache = $cacheManager->build(['driver' => 'null']);

        $this->assertInstanceOf(ArrayStore::class, $arrayCache->getStore());
        $this->assertInstanceOf(NullStore::class, $nullCache->getStore());
    }

    public function testItMakesRepositoryWhenContainerHasNoDispatcher()
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'my_store' => [
                        'driver' => 'array',
                        'events' => true,
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);

        $cacheManager = new CacheManager($app);
        $repo = $cacheManager->repository($theStore = new NullStore(), ['events' => true]);

        $this->assertNull($repo->getEventDispatcher());
        $this->assertSame($theStore, $repo->getStore());

        // binding dispatcher after the repo's birth will have no effect.
        $eventDispatcher = m::mock(Dispatcher::class);
        $app->instance(Dispatcher::class, $eventDispatcher);

        $this->assertNull($repo->getEventDispatcher());
        $this->assertSame($theStore, $repo->getStore());

        $cacheManager = new CacheManager($app);
        $repo = $cacheManager->repository(new NullStore(), ['events' => true]);
        // now that the $app has a Dispatcher, the newly born repository will also have one.
        $this->assertSame($eventDispatcher, $repo->getEventDispatcher());
    }

    public function testItRefreshesDispatcherOnAllStores()
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'store_1' => [
                        'driver' => 'array',
                        'events' => true,
                    ],
                    'store_2' => [
                        'driver' => 'array',
                        'events' => true,
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);

        $cacheManager = new CacheManager($app);
        $repo1 = $cacheManager->store('store_1');
        $repo2 = $cacheManager->store('store_2');

        $this->assertNull($repo1->getEventDispatcher());
        $this->assertNull($repo2->getEventDispatcher());

        $eventDispatcher = m::mock(Dispatcher::class);
        $app->instance(Dispatcher::class, $eventDispatcher);

        $cacheManager->refreshEventDispatcher();

        $this->assertNotSame($repo1, $repo2);
        $this->assertSame($eventDispatcher, $repo1->getEventDispatcher());
        $this->assertSame($eventDispatcher, $repo2->getEventDispatcher());
    }

    public function testItSetsDefaultDriverChangesGlobalConfig()
    {
        $userConfig = [
            'cache' => [
                'default' => 'store_1',
                'stores' => [
                    'store_1' => [
                        'driver' => 'array',
                    ],
                    'store_2' => [
                        'driver' => 'array',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);
        $cacheManager = new CacheManager($app);

        $cacheManager->setDefaultDriver('><((((@>');

        $this->assertEquals('><((((@>', $app->make('config')->get('cache.default'));
    }

    public function testItPurgesMemoizedStoreObjects()
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'store_1' => [
                        'driver' => 'array',
                    ],
                    'store_2' => [
                        'driver' => 'null',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);

        $cacheManager = new CacheManager($app);

        $repo1 = $cacheManager->store('store_1');
        $repo2 = $cacheManager->store('store_1');

        $repo3 = $cacheManager->store('store_2');
        $repo4 = $cacheManager->store('store_2');
        $repo5 = $cacheManager->store('store_2');

        $this->assertSame($repo1, $repo2);
        $this->assertSame($repo3, $repo4);
        $this->assertSame($repo3, $repo5);
        $this->assertNotSame($repo1, $repo5);

        $cacheManager->purge('store_1');

        // Make sure a now object is built this time.
        $repo6 = $cacheManager->store('store_1');
        $this->assertNotSame($repo1, $repo6);

        // Make sure Purge does not delete all objects.
        $repo7 = $cacheManager->store('store_2');
        $this->assertSame($repo3, $repo7);
    }

    public function testForgetDriver()
    {
        $cacheManager = m::mock(CacheManager::class)
            ->shouldAllowMockingProtectedMethods()
            ->makePartial();

        $cacheManager->shouldReceive('resolve')
            ->withArgs(['array'])
            ->times(4)
            ->andReturn(m::mock(CacheRepository::class));

        $cacheManager->shouldReceive('getDefaultDriver')
            ->once()
            ->andReturn('array');

        foreach (['array', ['array'], null] as $option) {
            $cacheManager->store('array');
            $cacheManager->store('array');
            $cacheManager->forgetDriver($option);
            $cacheManager->store('array');
            $cacheManager->store('array');
        }
    }

    public function testForgetDriverForgets()
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'forget' => [
                        'driver' => 'forget',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);

        $count = 0;

        $cacheManager = new CacheManager($app);
        $cacheManager->extend('forget', function () use (&$count) {
            /** @var CacheRepository|MockInterface */
            $repository = m::mock(CacheRepository::class);

            if ($count++ === 0) {
                $repository->shouldReceive('forever')->with('foo', 'bar')->once();
                $repository->shouldReceive('get')->with('foo')->once()->andReturn('bar');

                return $repository;
            }

            $repository->shouldReceive('get')->with('foo')->once()->andReturnNull();

            return $repository;
        });

        $cacheManager->store('forget')->forever('foo', 'bar');
        $this->assertSame('bar', $cacheManager->store('forget')->get('foo'));
        $cacheManager->forgetDriver('forget');
        $this->assertNull($cacheManager->store('forget')->get('foo'));
    }

    public function testThrowExceptionWhenUnknownDriverIsUsed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Driver [unknown_taxi_driver] is not supported.');

        $userConfig = [
            'cache' => [
                'stores' => [
                    'my_store' => [
                        'driver' => 'unknown_taxi_driver',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);

        $cacheManager = new CacheManager($app);

        $cacheManager->store('my_store');
    }

    public function testThrowExceptionWhenUnknownStoreIsUsed()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache store [alien_store] is not defined.');

        $userConfig = [
            'cache' => [
                'stores' => [
                    'my_store' => [
                        'driver' => 'array',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);

        $cacheManager = new CacheManager($app);

        $cacheManager->store('alien_store');
    }

    public function testRedisDriverDefaultsToIntersectionTaggingMode(): void
    {
        $userConfig = [
            'cache' => [
                'prefix' => 'test',
                'stores' => [
                    'redis' => [
                        'driver' => 'redis',
                        'connection' => 'default',
                    ],
                ],
            ],
        ];

        $app = $this->getAppWithRedis($userConfig);
        $cacheManager = new CacheManager($app);

        $repository = $cacheManager->store('redis');
        $store = $repository->getStore();

        $this->assertInstanceOf(RedisStore::class, $store);
        $this->assertSame(TagMode::All, $store->getTagMode());
    }

    public function testRedisDriverUsesConfiguredTagMode(): void
    {
        $userConfig = [
            'cache' => [
                'prefix' => 'test',
                'stores' => [
                    'redis' => [
                        'driver' => 'redis',
                        'connection' => 'default',
                        'tag_mode' => 'any',
                    ],
                ],
            ],
        ];

        $app = $this->getAppWithRedis($userConfig);
        $cacheManager = new CacheManager($app);

        $repository = $cacheManager->store('redis');
        $store = $repository->getStore();

        $this->assertInstanceOf(RedisStore::class, $store);
        $this->assertSame(TagMode::Any, $store->getTagMode());
    }

    public function testRedisDriverFallsBackToAllForInvalidTagMode(): void
    {
        $userConfig = [
            'cache' => [
                'prefix' => 'test',
                'stores' => [
                    'redis' => [
                        'driver' => 'redis',
                        'connection' => 'default',
                        'tag_mode' => 'invalid',
                    ],
                ],
            ],
        ];

        $app = $this->getAppWithRedis($userConfig);
        $cacheManager = new CacheManager($app);

        $repository = $cacheManager->store('redis');
        $store = $repository->getStore();

        $this->assertInstanceOf(RedisStore::class, $store);
        $this->assertSame(TagMode::All, $store->getTagMode());
    }

    public function testSessionDriverResolvesSessionStore()
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'session' => [
                        'driver' => 'session',
                        'key' => '_test_cache',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);

        $session = m::mock(\Hypervel\Contracts\Session\Session::class);
        $app->instance('session.store', $session);

        $cacheManager = new CacheManager($app);

        $repository = $cacheManager->store('session');
        $store = $repository->getStore();

        $this->assertInstanceOf(\Hypervel\Cache\SessionStore::class, $store);
    }

    public function testSessionDriverThrowsWhenSessionNotAvailable()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Session store requires session manager to be available in container.');

        $userConfig = [
            'cache' => [
                'stores' => [
                    'session' => [
                        'driver' => 'session',
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);

        $cacheManager = new CacheManager($app);
        $cacheManager->store('session');
    }

    public function testMakesRepositoryWithoutDispatcherWhenEventsDisabled()
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'my_store' => [
                        'driver' => 'array',
                    ],
                    'my_store_without_events' => [
                        'driver' => 'array',
                        'events' => false,
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);
        $app->bind(Dispatcher::class, fn () => new Event());

        $cacheManager = new CacheManager($app);

        // The repository will have an event dispatcher
        $repo = $cacheManager->store('my_store');
        $this->assertNotNull($repo->getEventDispatcher());

        // This repository will not have an event dispatcher as 'events' is false
        $repoWithoutEvents = $cacheManager->store('my_store_without_events');
        $this->assertNull($repoWithoutEvents->getEventDispatcher());
    }

    protected function getApp(array $userConfig): Container
    {
        $app = new Container();
        $app->instance('config', new ConfigRepository($userConfig));

        return $app;
    }

    protected function getAppWithRedis(array $userConfig): Container
    {
        $app = $this->getApp($userConfig);

        // Mock Redis client
        $redisClient = m::mock();
        $redisClient->shouldReceive('getOption')
            ->with(Redis::OPT_COMPRESSION)
            ->andReturn(Redis::COMPRESSION_NONE);
        $redisClient->shouldReceive('getOption')
            ->with(Redis::OPT_PREFIX)
            ->andReturn('');

        // Mock RedisConnection
        $connection = m::mock(RedisConnection::class);
        $connection->shouldReceive('release')->zeroOrMoreTimes();
        $connection->shouldReceive('serialized')->andReturn(false);
        $connection->shouldReceive('client')->andReturn($redisClient);

        // Mock RedisPool
        $pool = m::mock(RedisPool::class);
        $pool->shouldReceive('get')->andReturn($connection);

        // Mock PoolFactory
        $poolFactory = m::mock(PoolFactory::class);
        $poolFactory->shouldReceive('getPool')->with('default')->andReturn($pool);

        // Mock RedisFactory
        $redisFactory = m::mock(RedisFactory::class);

        $app->instance(RedisFactory::class, $redisFactory);
        $app->instance(PoolFactory::class, $poolFactory);

        Container::setInstance($app);

        return $app;
    }
}
