<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Config\Repository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Pool\DbPool;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Pool\Connection;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class PoolFactoryTest extends TestCase
{
    public function testGetPoolReturnsSameInstance(): void
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool1 = $factory->getPool('default');
        $pool2 = $factory->getPool('default');

        $this->assertSame($pool1, $pool2);
    }

    public function testGetPoolReturnsDifferentInstancesForDifferentNames(): void
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool1 = $factory->getPool('default');
        $pool2 = $factory->getPool('cache');

        $this->assertNotSame($pool1, $pool2);
    }

    public function testPoolsReturnsOnlyExistingPhysicalPools(): void
    {
        $factory = new PoolFactory($this->mockContainerWithPools());

        $this->assertSame([], $factory->pools());

        $default = $factory->getPool('default');
        $cache = $factory->getPool('cache');

        $this->assertSame([
            'default' => $default,
            'cache' => $cache,
        ], $factory->pools());
    }

    public function testHasPool(): void
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $this->assertFalse($factory->hasPool('default'));

        $factory->getPool('default');

        $this->assertTrue($factory->hasPool('default'));
        $this->assertFalse($factory->hasPool('cache'));
    }

    public function testFlushAll(): void
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool1 = $factory->getPool('default');
        $pool2 = $factory->getPool('cache');

        $connection1 = $pool1->get();
        $connection2 = $pool1->get();
        $connection3 = $pool2->get();

        $pool1->release($connection1);
        $pool1->release($connection2);
        $pool2->release($connection3);

        $this->assertSame(2, $pool1->getConnectionsInChannel());
        $this->assertSame(1, $pool2->getConnectionsInChannel());

        $factory->flushAll();

        $this->assertSame(0, $pool1->getConnectionsInChannel());
        $this->assertSame(0, $pool2->getConnectionsInChannel());
    }

    public function testFlushAllClearsCachedPools(): void
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $original = $factory->getPool('default');

        $factory->flushAll();

        // After flushAll, the cached pool entry should be evicted so the next
        // getPool() returns a fresh instance. This lets the previous Pool's
        // Channel/Connection graph be refcount-collected instead of trapped.
        $fresh = $factory->getPool('default');

        $this->assertNotSame($original, $fresh);
    }

    public function testFlushAllDetachesPoolsBeforeClosingThem(): void
    {
        $container = m::mock(ContainerContract::class);
        $original = m::mock(DbPool::class);
        $replacement = m::mock(DbPool::class);
        $container->shouldReceive('make')
            ->with(DbPool::class, ['name' => 'default'])
            ->twice()
            ->andReturn($original, $replacement);
        $factory = new PoolFactory($container);
        $resolvedDuringClose = null;
        $original->shouldReceive('close')->once()->andReturnUsing(
            function () use ($factory, &$resolvedDuringClose): void {
                $resolvedDuringClose = $factory->getPool('default');
            }
        );

        $this->assertSame($original, $factory->getPool('default'));

        $factory->flushAll();

        $this->assertSame($replacement, $resolvedDuringClose);
        $this->assertSame($replacement, $factory->getPool('default'));
    }

    public function testFlushAllContinuesClosingAndPreservesFirstFailure(): void
    {
        $firstFailure = new RuntimeException('first close failed');
        $secondFailure = new RuntimeException('second close failed');
        $firstPool = m::mock(DbPool::class);
        $secondPool = m::mock(DbPool::class);
        $thirdPool = m::mock(DbPool::class);
        $firstPool->shouldReceive('close')->once()->andThrow($firstFailure);
        $secondPool->shouldReceive('close')->once()->andThrow($secondFailure);
        $thirdPool->shouldReceive('close')->once();

        $container = m::mock(ContainerContract::class);
        $container->shouldReceive('make')->with(DbPool::class, ['name' => 'first'])->once()->andReturn($firstPool);
        $container->shouldReceive('make')->with(DbPool::class, ['name' => 'second'])->once()->andReturn($secondPool);
        $container->shouldReceive('make')->with(DbPool::class, ['name' => 'third'])->once()->andReturn($thirdPool);

        $factory = new PoolFactory($container);
        $factory->getPool('first');
        $factory->getPool('second');
        $factory->getPool('third');

        try {
            $factory->flushAll();
            $this->fail('Expected the first pool close failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstFailure, $exception);
        }

        $this->assertSame([], $factory->pools());
    }

    public function testFlushPoolOnlyFlushesNamedPool(): void
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $defaultPool = $factory->getPool('default');
        $cachePool = $factory->getPool('cache');

        $defaultConn1 = $defaultPool->get();
        $defaultConn2 = $defaultPool->get();
        $cacheConn = $cachePool->get();

        $defaultPool->release($defaultConn1);
        $defaultPool->release($defaultConn2);
        $cachePool->release($cacheConn);

        $this->assertSame(2, $defaultPool->getConnectionsInChannel());
        $this->assertSame(1, $cachePool->getConnectionsInChannel());

        $factory->flushPool('default');

        // Default pool should be flushed
        $this->assertSame(0, $defaultPool->getConnectionsInChannel());

        // Cache pool should be untouched
        $this->assertSame(1, $cachePool->getConnectionsInChannel());
        $this->assertSame($cachePool, $factory->getPool('cache'));

        // Getting default pool again should return a fresh instance
        $freshDefaultPool = $factory->getPool('default');
        $this->assertNotSame($defaultPool, $freshDefaultPool);
    }

    public function testFlushPoolDetachesPoolBeforeClosingIt(): void
    {
        $container = m::mock(ContainerContract::class);
        $original = m::mock(DbPool::class);
        $replacement = m::mock(DbPool::class);
        $container->shouldReceive('make')
            ->with(DbPool::class, ['name' => 'default'])
            ->twice()
            ->andReturn($original, $replacement);
        $factory = new PoolFactory($container);
        $resolvedDuringClose = null;
        $original->shouldReceive('close')->once()->andReturnUsing(
            function () use ($factory, &$resolvedDuringClose): void {
                $resolvedDuringClose = $factory->getPool('default');
            }
        );

        $this->assertSame($original, $factory->getPool('default'));

        $factory->flushPool('default');

        $this->assertSame($replacement, $resolvedDuringClose);
        $this->assertSame($replacement, $factory->getPool('default'));
    }

    public function testFlushPoolGivesReplacementPoolIndependentCapacity(): void
    {
        $container = $this->mockContainerWithPools([
            'default' => $this->connectionConfig([
                'pool' => [
                    'min_connections' => 1,
                    'max_connections' => 1,
                    'connect_timeout' => 10.0,
                    'wait_timeout' => 3.0,
                    'heartbeat' => -1,
                    'max_idle_time' => 60.0,
                ],
            ]),
        ]);
        $factory = new PoolFactory($container);
        $oldPool = $factory->getPool('default');
        $oldConnection = $oldPool->get();

        $factory->flushPool('default');

        $newPool = $factory->getPool('default');
        $newConnection = $newPool->get();

        $this->assertTrue($oldPool->isClosed());
        $this->assertNotSame($oldPool, $newPool);
        $this->assertSame(1, $oldPool->getCurrentConnections());
        $this->assertSame(1, $newPool->getCurrentConnections());

        $oldPool->release($oldConnection);

        $this->assertSame(0, $oldPool->getCurrentConnections());
        $this->assertSame(1, $oldConnection->closeCount);

        $newPool->release($newConnection);
    }

    public function testWriteConnectionUsesBasePool(): void
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);
        $pool = $factory->getPool('default::write');

        $this->assertSame(
            $factory->getPool('default'),
            $pool
        );
        $this->assertTrue($factory->hasPool('default::write'));
    }

    public function testReadConnectionUsesSeparatePoolWhenReadConfigExists(): void
    {
        $container = $this->mockContainerWithPools([
            'default' => $this->connectionConfig([
                'read' => [
                    'host' => '127.0.0.2',
                ],
            ]),
        ]);

        $factory = new PoolFactory($container);

        $this->assertNotSame(
            $factory->getPool('default'),
            $factory->getPool('default::read')
        );
        $this->assertTrue($factory->hasPool('default'));
        $this->assertTrue($factory->hasPool('default::read'));
    }

    public function testReadConnectionUsesBasePoolWhenReadConfigIsMissingOrNull(): void
    {
        $container = $this->mockContainerWithPools([
            'default' => $this->connectionConfig([
                'read' => null,
            ]),
        ]);

        $factory = new PoolFactory($container);

        $this->assertSame(
            $factory->getPool('default'),
            $factory->getPool('default::read')
        );
        $this->assertTrue($factory->hasPool('default::read'));
    }

    public function testPoolConnectTimeoutIsExposedWithoutLosingFractionalPrecision(): void
    {
        foreach (['mysql', 'mariadb', 'pgsql', 'sqlite'] as $driver) {
            $config = $this->connectionConfig(['driver' => $driver]);
            $config['pool']['connect_timeout'] = 1.25;
            $pool = (new PoolFactory($this->mockContainerWithPools(['default' => $config])))->getPool('default');

            $this->assertInstanceOf(PoolFactoryTestPool::class, $pool);
            $this->assertSame(1.25, $pool->configForTest()['connect_timeout']);

            $config['connect_timeout'] = 7.5;
            $pool = (new PoolFactory($this->mockContainerWithPools(['default' => $config])))->getPool('default');

            $this->assertInstanceOf(PoolFactoryTestPool::class, $pool);
            $this->assertSame(7.5, $pool->configForTest()['connect_timeout']);
        }
    }

    public function testFlushPoolResolvesWriteAliasToBasePool(): void
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool = $factory->getPool('default::write');
        $pool->release($pool->get());

        $factory->flushPool('default::write');

        $this->assertSame(0, $pool->getConnectionsInChannel());
        $this->assertNotSame($pool, $factory->getPool('default'));
    }

    public function testFlushPoolsForConnectionFlushesBaseAndRolePools(): void
    {
        $container = $this->mockContainerWithPools([
            'default' => $this->connectionConfig([
                'read' => [
                    'host' => '127.0.0.2',
                ],
            ]),
            'cache' => $this->connectionConfig(),
        ]);

        $factory = new PoolFactory($container);

        $defaultPool = $factory->getPool('default');
        $readPool = $factory->getPool('default::read');
        $cachePool = $factory->getPool('cache');

        $defaultPool->release($defaultPool->get());
        $readPool->release($readPool->get());
        $cachePool->release($cachePool->get());

        $factory->flushPoolsForConnection('default::read');

        $this->assertSame(0, $defaultPool->getConnectionsInChannel());
        $this->assertSame(0, $readPool->getConnectionsInChannel());
        $this->assertSame(1, $cachePool->getConnectionsInChannel());
        $this->assertNotSame($defaultPool, $factory->getPool('default'));
        $this->assertNotSame($readPool, $factory->getPool('default::read'));
        $this->assertSame($cachePool, $factory->getPool('cache'));
    }

    public function testFlushPoolsForConnectionDetachesSelectionAndPrioritizesCancellation(): void
    {
        $ordinaryFailure = new RuntimeException('write pool close failed');
        $cancellation = new CanceledException;
        $writePool = m::mock(DbPool::class);
        $readPool = m::mock(DbPool::class);
        $cachePool = m::mock(DbPool::class);
        $factory = new PoolFactory(m::mock(ContainerContract::class));
        $detachedPools = null;

        $writePool->shouldReceive('close')->once()->andReturnUsing(
            function () use ($factory, &$detachedPools, $ordinaryFailure): never {
                $detachedPools = $factory->pools();

                throw $ordinaryFailure;
            }
        );
        $readPool->shouldReceive('close')->once()->andThrow($cancellation);
        $cachePool->shouldNotReceive('close');

        $pools = new ReflectionProperty($factory, 'pools');
        $pools->setValue($factory, [
            'default' => $writePool,
            'default::read' => $readPool,
            'cache' => $cachePool,
        ]);

        try {
            $factory->flushPoolsForConnection('default::read');
            $this->fail('Expected pool close cancellation to propagate.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }

        $this->assertSame(['cache' => $cachePool], $detachedPools);
        $this->assertSame(['cache' => $cachePool], $factory->pools());
    }

    private function mockContainerWithPools(?array $connections = null): m\MockInterface|ContainerContract
    {
        $connections ??= [
            'default' => $this->connectionConfig(),
            'cache' => $this->connectionConfig(),
        ];

        $config = new Repository([
            'database' => [
                'connections' => $connections,
            ],
        ]);

        $container = m::mock(ContainerContract::class);
        $factory = new ConnectionFactory($container);

        $container->shouldReceive('make')->with('config')->andReturn($config);
        $container->shouldReceive('make')->with('db.factory')->andReturn($factory);
        $container->shouldReceive('has')->with(StdoutLoggerInterface::class)->andReturn(false);
        $container->shouldReceive('bound')->with('events')->andReturn(false);
        $container->shouldReceive('make')->with(DbPool::class, m::any())->andReturnUsing(
            fn ($class, $args) => new PoolFactoryTestPool($container, $args['name'])
        );

        return $container;
    }

    private function connectionConfig(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'test',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
                'heartbeat' => -1,
                'max_idle_time' => 60.0,
            ],
        ], $overrides);
    }
}

class PoolFactoryTestPool extends DbPool
{
    public function configForTest(): array
    {
        return $this->config;
    }

    protected function createConnection(): ConnectionInterface
    {
        return new PoolFactoryTestConnection($this->container, $this);
    }
}

class PoolFactoryTestConnection extends Connection
{
    public int $closeCount = 0;

    public function close(): bool
    {
        ++$this->closeCount;

        return true;
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function getActiveConnection(): mixed
    {
        return $this;
    }
}
