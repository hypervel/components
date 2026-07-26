<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use __PHP_Incomplete_Class;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\StorageStore;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\SwooleTableManager;
use Hypervel\Cache\TagMode;
use Hypervel\Cache\WorkerArrayStore;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Events\Dispatcher as Event;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Tests\Cache\Fixtures\ArrayFilesystem;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use Redis;
use stdClass;

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

    public function testItCanCreateStorageDriver(): void
    {
        $disk = new ArrayFilesystem;

        $filesystem = m::mock(FilesystemFactory::class);
        $filesystem->shouldReceive('disk')->with('s3')->once()->andReturn($disk);

        $app = $this->getApp([
            'cache' => [
                'prefix' => 'cache:',
                'serializable_classes' => false,
                'stores' => [
                    'storage' => [
                        'driver' => 'storage',
                        'disk' => 's3',
                        'path' => 'cache',
                    ],
                ],
            ],
        ]);
        $app->instance('filesystem', $filesystem);

        $store = (new CacheManager($app))->store('storage')->getStore();

        $this->assertInstanceOf(StorageStore::class, $store);
        $this->assertSame($disk, $store->getDisk());
        $this->assertSame('cache', $store->getDirectory());
        $this->assertSame('cache:', $store->getPrefix());
        $this->assertTrue($store->put('foo', new stdClass, 60));
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $store->get('foo'));
    }

    public function testItCanBuildWorkerArrayRepositories(): void
    {
        $app = $this->getApp([]);
        $cacheManager = new CacheManager($app);

        $repository = $cacheManager->build(['driver' => 'worker-array']);

        $this->assertInstanceOf(WorkerArrayStore::class, $repository->getStore());
    }

    public function testSwooleDriverUsesConfiguredSerializableClasses(): void
    {
        $app = $this->getApp([
            'cache' => [
                'serializable_classes' => false,
                'stores' => [
                    'swoole' => [
                        'driver' => 'swoole',
                        'table' => 'default',
                    ],
                ],
                'swoole_tables' => [
                    'default' => [
                        'rows' => 128,
                        'bytes' => 10240,
                        'conflict_proportion' => 0.2,
                    ],
                ],
            ],
        ]);
        $app->instance(SwooleTableManager::class, new SwooleTableManager($app));

        $store = (new CacheManager($app))->store('swoole')->getStore();

        $this->assertInstanceOf(SwooleStore::class, $store);
        $this->assertTrue($store->put('foo', new stdClass, 60));
        $this->assertInstanceOf(__PHP_Incomplete_Class::class, $store->get('foo'));
    }

    public function testItResolvesMultiWordInternalDriversUsingStudlyNames(): void
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'worker' => [
                        'driver' => 'worker-array',
                    ],
                ],
            ],
        ];

        $cacheManager = new CacheManager($this->getApp($userConfig));

        $this->assertInstanceOf(WorkerArrayStore::class, $cacheManager->store('worker')->getStore());
    }

    public function testCustomCreatorsStillOverrideMultiWordInternalDrivers(): void
    {
        $userConfig = [
            'cache' => [
                'stores' => [
                    'worker' => [
                        'driver' => 'worker-array',
                    ],
                ],
            ],
        ];

        $cacheManager = new CacheManager($this->getApp($userConfig));
        $repository = m::mock(CacheRepository::class);

        $cacheManager->extend('worker-array', fn () => $repository);

        $this->assertSame($repository, $cacheManager->store('worker'));
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
        $repo = $cacheManager->repository($theStore = new NullStore, ['events' => true]);

        $this->assertNull($repo->getEventDispatcher());
        $this->assertSame($theStore, $repo->getStore());

        // binding dispatcher after the repo's birth will have no effect.
        $eventDispatcher = m::mock(Dispatcher::class);
        $app->instance(Dispatcher::class, $eventDispatcher);

        $this->assertNull($repo->getEventDispatcher());
        $this->assertSame($theStore, $repo->getStore());

        $cacheManager = new CacheManager($app);
        $repo = $cacheManager->repository(new NullStore, ['events' => true]);
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
        $app->bind(Dispatcher::class, fn () => new Event);

        $cacheManager = new CacheManager($app);

        // The repository will have an event dispatcher
        $repo = $cacheManager->store('my_store');
        $this->assertNotNull($repo->getEventDispatcher());

        // This repository will not have an event dispatcher as 'events' is false
        $repoWithoutEvents = $cacheManager->store('my_store_without_events');
        $this->assertNull($repoWithoutEvents->getEventDispatcher());
    }

    public function testEnumStoreCanBeResolved(): void
    {
        $app = $this->getApp([
            'cache' => [
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]);
        $cacheManager = new CacheManager($app);

        $store = $cacheManager->store(CacheStoreName::ArrayStore);

        $this->assertInstanceOf(ArrayStore::class, $store->getStore());
        $this->assertSame($store, $cacheManager->store(CacheStoreName::ArrayStore));
    }

    public function testZeroStoreNameCanBeResolvedFromEnumAndString(): void
    {
        $app = $this->getApp([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    '0' => ['driver' => 'array'],
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]);
        $cacheManager = new CacheManager($app);

        $store = $cacheManager->store(NumericCacheStoreName::Zero);

        $this->assertInstanceOf(ArrayStore::class, $store->getStore());
        $this->assertSame($store, $cacheManager->store('0'));
        $this->assertNotSame($store, $cacheManager->store());
    }

    public function testEmptyStoreNameRemainsExplicit(): void
    {
        $cacheManager = new CacheManager($this->getApp([
            'cache' => [
                'default' => 'array',
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cache store [] is not defined.');

        $cacheManager->store('');
    }

    public function testEnumDriverCanBeResolved(): void
    {
        $app = $this->getApp([
            'cache' => [
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]);
        $cacheManager = new CacheManager($app);

        $store = $cacheManager->driver(CacheStoreName::ArrayStore);

        $this->assertInstanceOf(ArrayStore::class, $store->getStore());
    }

    public function testEnumMemoStoreCanBeResolved(): void
    {
        $app = $this->getApp([
            'cache' => [
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]);
        $cacheManager = new CacheManager($app);

        $store = $cacheManager->memo(CacheStoreName::ArrayStore);

        $this->assertSame($store, $cacheManager->memo(CacheStoreName::ArrayStore));
    }

    public function testForgetDriverAcceptsEnum(): void
    {
        $app = $this->getApp([
            'cache' => [
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]);
        $cacheManager = new CacheManager($app);

        $repo1 = $cacheManager->store(CacheStoreName::ArrayStore);
        $cacheManager->forgetDriver(CacheStoreName::ArrayStore);
        $repo2 = $cacheManager->store(CacheStoreName::ArrayStore);

        $this->assertNotSame($repo1, $repo2);
    }

    public function testPurgeAcceptsEnum(): void
    {
        $app = $this->getApp([
            'cache' => [
                'stores' => [
                    'array' => ['driver' => 'array'],
                ],
            ],
        ]);
        $cacheManager = new CacheManager($app);

        $repo1 = $cacheManager->store(CacheStoreName::ArrayStore);
        $cacheManager->purge(CacheStoreName::ArrayStore);
        $repo2 = $cacheManager->store(CacheStoreName::ArrayStore);

        $this->assertNotSame($repo1, $repo2);
    }

    public function testSetDefaultDriverAcceptsEnum(): void
    {
        $app = $this->getApp([
            'cache' => [
                'default' => 'old',
                'stores' => [],
            ],
        ]);
        $cacheManager = new CacheManager($app);

        $cacheManager->setDefaultDriver(CacheStoreName::ArrayStore);

        $this->assertSame('array', $app->get('config')->get('cache.default'));
    }

    protected function getApp(array $userConfig): Container
    {
        $app = new Container;
        $config = new ConfigRepository($userConfig);
        $app->instance('config', $config);

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
        $connection = m::mock(PhpRedisConnection::class);
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

        $app->instance('redis', $redisFactory);
        $app->instance(PoolFactory::class, $poolFactory);

        Container::setInstance($app);

        return $app;
    }
}

enum CacheStoreName: string
{
    case ArrayStore = 'array';
}

enum NumericCacheStoreName: int
{
    case Zero = 0;
}
