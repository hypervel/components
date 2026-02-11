<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\Redis\TagMode;
use Hypervel\Cache\RedisStore;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Pool\RedisPool;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisFactory;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use Hypervel\Contracts\Container\Container;
use Psr\EventDispatcher\EventDispatcherInterface;
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
        $app->shouldReceive('has')->with(EventDispatcherInterface::class)->once()->andReturnFalse();
        $app->shouldReceive('has')->with(EventDispatcherInterface::class)->once()->andReturnTrue();
        $app->shouldReceive('get')->with(EventDispatcherInterface::class)->once()->andReturn($eventDispatcher = m::mock(EventDispatcherInterface::class));

        $cacheManager = new CacheManager($app);
        $repo = $cacheManager->repository($theStore = new NullStore(), ['events' => true]);

        $this->assertNull($repo->getEventDispatcher());
        $this->assertSame($theStore, $repo->getStore());

        // binding dispatcher after the repo's birth will have no effect.
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
        $app->shouldReceive('has')->with(EventDispatcherInterface::class)->twice()->andReturnFalse();
        $app->shouldReceive('has')->with(EventDispatcherInterface::class)->twice()->andReturnTrue();
        $app->shouldReceive('get')->with(EventDispatcherInterface::class)->twice()->andReturn($eventDispatcher = m::mock(EventDispatcherInterface::class));

        $cacheManager = new CacheManager($app);
        $repo1 = $cacheManager->store('store_1');
        $repo2 = $cacheManager->store('store_2');

        $this->assertNull($repo1->getEventDispatcher());
        $this->assertNull($repo2->getEventDispatcher());

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

        $this->assertEquals('><((((@>', $app->get('config')->get('cache.default'));
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
        $app->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturnFalse();

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

    protected function getApp(array $userConfig)
    {
        /** @var Container|MockInterface */
        $app = m::mock(Container::class);
        $app->shouldReceive('get')->with('config')->andReturn(new ConfigRepository($userConfig));

        return $app;
    }

    protected function getAppWithRedis(array $userConfig)
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

        $app->shouldReceive('get')->with(RedisFactory::class)->andReturn($redisFactory);
        $app->shouldReceive('has')->with(EventDispatcherInterface::class)->andReturnFalse();

        // Override make() to return our mocked PoolFactory
        // Since make() uses container internally, we need to handle this
        \Hyperf\Context\ApplicationContext::setContainer($app);
        $app->shouldReceive('get')->with(PoolFactory::class)->andReturn($poolFactory);
        $app->shouldReceive('make')->with(PoolFactory::class, m::any())->andReturn($poolFactory);

        return $app;
    }
}
