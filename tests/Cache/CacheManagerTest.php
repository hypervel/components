<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Contracts\Repository;
use Hypervel\Cache\NullStore;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Mockery\MockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

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
        $repository = m::mock(Repository::class);
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

        /** @var MockInterface|Repository */
        $repository = m::mock(Repository::class);
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
                    ],
                ],
            ],
        ];

        $app = $this->getApp($userConfig);
        $app->shouldReceive('has')->with(EventDispatcherInterface::class)->once()->andReturnFalse();
        $app->shouldReceive('has')->with(EventDispatcherInterface::class)->once()->andReturnTrue();
        $app->shouldReceive('get')->with(EventDispatcherInterface::class)->once()->andReturn($eventDispatcher = m::mock(EventDispatcherInterface::class));

        $cacheManager = new CacheManager($app);
        $repo = $cacheManager->repository($theStore = new NullStore());

        $this->assertNull($repo->getEventDispatcher());
        $this->assertSame($theStore, $repo->getStore());

        // binding dispatcher after the repo's birth will have no effect.
        $this->assertNull($repo->getEventDispatcher());
        $this->assertSame($theStore, $repo->getStore());

        $cacheManager = new CacheManager($app);
        $repo = $cacheManager->repository(new NullStore());
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
                    ],
                    'store_2' => [
                        'driver' => 'array',
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

        $this->assertEquals('><((((@>', $app->get(ConfigInterface::class)->get('cache.default'));
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
            ->andReturn(m::mock(Repository::class));

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
            /** @var MockInterface|Repository */
            $repository = m::mock(Repository::class);

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

    protected function getApp(array $userConfig)
    {
        /** @var ContainerInterface|MockInterface */
        $app = m::mock(ContainerInterface::class);
        $app->shouldReceive('get')->with(ConfigInterface::class)->andReturn(new Config($userConfig));

        return $app;
    }
}
