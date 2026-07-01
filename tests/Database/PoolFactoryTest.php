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

class PoolFactoryTest extends TestCase
{
    public function testGetPoolReturnsSameInstance()
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool1 = $factory->getPool('default');
        $pool2 = $factory->getPool('default');

        $this->assertSame($pool1, $pool2);
    }

    public function testGetPoolReturnsDifferentInstancesForDifferentNames()
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $pool1 = $factory->getPool('default');
        $pool2 = $factory->getPool('cache');

        $this->assertNotSame($pool1, $pool2);
    }

    public function testHasPool()
    {
        $container = $this->mockContainerWithPools();

        $factory = new PoolFactory($container);

        $this->assertFalse($factory->hasPool('default'));

        $factory->getPool('default');

        $this->assertTrue($factory->hasPool('default'));
        $this->assertFalse($factory->hasPool('cache'));
    }

    public function testFlushAll()
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

    public function testFlushAllClearsCachedPools()
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

    public function testFlushPoolOnlyFlushesNamedPool()
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
    protected function createConnection(): ConnectionInterface
    {
        return new PoolFactoryTestConnection($this->container, $this);
    }
}

class PoolFactoryTestConnection extends Connection
{
    public function close(): bool
    {
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
