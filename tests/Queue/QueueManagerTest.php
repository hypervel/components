<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Queue\Connectors\ConnectorInterface;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Tests\TestCase;
use Mockery as m;

class QueueManagerTest extends TestCase
{
    public function testIntegerEnumConnectionNamesAreNormalizedWithoutTreatingZeroAsAbsent(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.0', ['driver' => 'sync']);

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('setConnectionName')->once()->with('0')->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->andReturnSelf();
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();
        $connector->shouldReceive('connect')->once()->with(['driver' => 'sync'])->andReturn($queue);
        $manager->addConnector('sync', fn () => $connector);

        $this->assertSame('sync', $manager->getName());
        $this->assertSame('0', $manager->getName('0'));
        $this->assertSame('sync', $manager->getName(''));

        $manager->setDefaultDriver(QueueManagerTestIntIdentifier::Zero);

        $this->assertSame('0', $manager->getDefaultDriver());
        $this->assertSame($queue, $manager->connection());
        $this->assertSame($queue, $manager->connection(QueueManagerTestIntIdentifier::Zero));
        $this->assertSame($queue, $manager->connection(''));
        $this->assertTrue($manager->connected(QueueManagerTestIntIdentifier::Zero));
        $this->assertTrue($manager->connected(''));

        $manager->purge('');

        $this->assertFalse($manager->connected(QueueManagerTestIntIdentifier::Zero));
    }

    public function testDefaultConnectionCanBeResolved(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.sync', ['driver' => 'sync']);

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('setConnectionName')->once()->with('sync')->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->andReturnSelf();
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();
        $connector->shouldReceive('connect')->once()->with(['driver' => 'sync'])->andReturn($queue);
        $manager->addConnector('sync', function () use ($connector) {
            return $connector;
        });

        $this->assertSame($queue, $manager->connection('sync'));
    }

    public function testOtherConnectionCanBeResolved(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.foo', ['driver' => 'bar']);

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('setConnectionName')->once()->with('foo')->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->andReturnSelf();
        $connector->shouldReceive('connect')->once()->with(['driver' => 'bar'])->andReturn($queue);
        $manager->addConnector('bar', function () use ($connector) {
            return $connector;
        });
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();

        $this->assertSame($queue, $manager->connection('foo'));
    }

    public function testNullConnectionCanBeResolved(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'null');

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(Queue::class);
        $queue->shouldReceive('setConnectionName')->once()->with('null')->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->andReturnSelf();
        $connector->shouldReceive('connect')->once()->with(['driver' => 'null'])->andReturn($queue);
        $manager->addConnector('null', function () use ($connector) {
            return $connector;
        });
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();

        $this->assertSame($queue, $manager->connection('null'));
    }

    public function testAddPoolableConnector(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.foo', ['driver' => 'bar']);

        $manager = new QueueManager($container);
        $connector = m::mock(ConnectorInterface::class);
        $manager->addConnector('bar', function () use ($connector) {
            return $connector;
        });
        $manager->addPoolable('bar');

        $this->assertInstanceOf(QueuePoolProxy::class, $manager->connection('foo'));
    }

    public function testPoolableConnectionsConvergeByConstructionConfigAndApplyTheirOwnNames(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $connectionConfig = [
            'driver' => 'custom',
            'queue' => 'shared',
            'pool' => ['max_objects' => 2],
        ];
        $config->set('queue.connections.foo', $connectionConfig);
        $config->set('queue.connections.bar', $connectionConfig);

        $manager = new QueueManager($container);
        $manager->addPoolable('custom');
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(NullQueue::class)->makePartial();
        $manager->addConnector('custom', fn () => $connector);

        $connector->shouldReceive('connect')->once()->with([
            'driver' => 'custom',
            'queue' => 'shared',
        ])->andReturn($queue);
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->with([
            'driver' => 'custom',
            'queue' => 'shared',
        ])->andReturnSelf();
        $queue->shouldReceive('setConnectionName')->once()->with('foo')->andReturnSelf();
        $queue->shouldReceive('setConnectionName')->once()->with('bar')->andReturnSelf();
        $queue->shouldReceive('size')->twice()->withNoArgs()->andReturn(1);

        $foo = $manager->connection('foo');
        $bar = $manager->connection('bar');

        $this->assertInstanceOf(QueuePoolProxy::class, $foo);
        $this->assertInstanceOf(QueuePoolProxy::class, $bar);
        $this->assertSame($foo->getPoolName(), $bar->getPoolName());
        $this->assertSame('foo', $foo->getConnectionName());
        $this->assertSame('bar', $bar->getConnectionName());
        $this->assertSame(1, $foo->size());
        $this->assertSame(1, $bar->size());
    }

    public function testPurgeInvalidatesCachedAndUncachedQueuePools(): void
    {
        $container = $this->getContainer();
        $container->make('config')->set('queue.connections.foo', [
            'driver' => 'custom',
            'queue' => 'shared',
        ]);

        $manager = new QueueManager($container);
        $manager->addPoolable('custom');
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(NullQueue::class)->makePartial();
        $manager->addConnector('custom', fn () => $connector);

        $connector->shouldReceive('connect')->twice()->with([
            'driver' => 'custom',
            'queue' => 'shared',
        ])->andReturn($queue);
        $queue->shouldReceive('setContainer')->twice()->with($container)->andReturnSelf();
        $queue->shouldReceive('setConfig')->twice()->with([
            'driver' => 'custom',
            'queue' => 'shared',
        ])->andReturnSelf();
        $queue->shouldReceive('setConnectionName')->twice()->with('foo')->andReturnSelf();
        $queue->shouldReceive('size')->twice()->withNoArgs()->andReturn(1);

        $connection = $manager->connection('foo');
        $this->assertInstanceOf(QueuePoolProxy::class, $connection);

        $identity = $connection->getPoolName();
        $pools = $container->make(PoolFactory::class);
        $connection->size();
        $this->assertTrue($pools->has($identity));

        $manager->purge('foo');
        $this->assertFalse($manager->connected('foo'));
        $this->assertFalse($pools->has($identity));

        $connection->size();
        $this->assertTrue($pools->has($identity));

        $manager->purge('foo');
        $this->assertFalse($pools->has($identity));
    }

    public function testConvergedConnectionsApplyTheirLogicalNameToPoppedJobs(): void
    {
        $container = $this->getContainer();
        $connectionConfig = ['driver' => 'custom', 'queue' => 'shared'];
        $container->make('config')->set('queue.connections.foo', $connectionConfig);
        $container->make('config')->set('queue.connections.bar', $connectionConfig);

        $manager = new QueueManager($container);
        $manager->addPoolable('custom');
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(NullQueue::class)->makePartial();
        $manager->addConnector('custom', fn () => $connector);
        $currentName = '';

        $connector->shouldReceive('connect')->once()->with($connectionConfig)->andReturn($queue);
        $queue->shouldReceive('setContainer')->once()->with($container)->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->with($connectionConfig)->andReturnSelf();
        $queue->shouldReceive('setConnectionName')->twice()
            ->andReturnUsing(function (string $name) use (&$currentName, $queue): Queue {
                $currentName = $name;

                return $queue;
            });
        $queue->shouldReceive('pop')->twice()->with(null)
            ->andReturnUsing(function () use ($container, &$currentName): QueueManagerTestJob {
                return new QueueManagerTestJob($container, $currentName);
            });

        $fooJob = $manager->connection('foo')->pop();
        $this->assertNotNull($fooJob);
        $this->assertSame('foo', $fooJob->getConnectionName());
        $fooJob->release();

        $barJob = $manager->connection('bar')->pop();
        $this->assertNotNull($barJob);
        $this->assertSame('bar', $barJob->getConnectionName());
        $barJob->release();
    }

    public function testSetApplicationEvictsPooledConnectionsAndRebindsTheirFactory(): void
    {
        $oldContainer = $this->getContainer();
        $oldContainer->make('config')->set('queue.connections.foo', [
            'driver' => 'custom',
            'queue' => 'shared',
        ]);
        $newContainer = $this->getContainer();
        $newContainer->make('config')->set('queue.connections.foo', [
            'driver' => 'custom',
            'queue' => 'shared',
        ]);

        $manager = new QueueManager($oldContainer);
        $manager->addPoolable('custom');
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(NullQueue::class)->makePartial();
        $manager->addConnector('custom', fn () => $connector);

        $connector->shouldReceive('connect')->twice()->andReturn($queue);
        $queue->shouldReceive('setContainer')->once()->with($oldContainer)->andReturnSelf();
        $queue->shouldReceive('setContainer')->once()->with($newContainer)->andReturnSelf();
        $queue->shouldReceive('setConfig')->twice()->andReturnSelf();
        $queue->shouldReceive('setConnectionName')->twice()->with('foo')->andReturnSelf();
        $queue->shouldReceive('size')->twice()->withNoArgs()->andReturn(1);

        $oldConnection = $manager->connection('foo');
        $this->assertInstanceOf(QueuePoolProxy::class, $oldConnection);
        $oldConnection->size();

        $identity = $oldConnection->getPoolName();
        $oldPools = $oldContainer->make(PoolFactory::class);
        $newPools = $newContainer->make(PoolFactory::class);
        $this->assertTrue($oldPools->has($identity));

        $manager->setApplication($newContainer);
        $this->assertFalse($manager->connected('foo'));
        $this->assertFalse($oldPools->has($identity));

        $newConnection = $manager->connection('foo');
        $this->assertInstanceOf(QueuePoolProxy::class, $newConnection);
        $this->assertNotSame($oldConnection, $newConnection);
        $newConnection->size();

        $this->assertFalse($oldPools->has($identity));
        $this->assertTrue($newPools->has($identity));
    }

    public function testSetApplicationUpdatesCachedDirectQueueInPlace(): void
    {
        $oldContainer = $this->getContainer();
        $oldContainer->make('config')->set('queue.connections.sync', ['driver' => 'sync']);
        $newContainer = $this->getContainer();

        $manager = new QueueManager($oldContainer);
        $connector = m::mock(ConnectorInterface::class);
        $queue = m::mock(Queue::class);
        $manager->addConnector('sync', fn () => $connector);

        $connector->shouldReceive('connect')->once()->with(['driver' => 'sync'])->andReturn($queue);
        $queue->shouldReceive('setContainer')->once()->with($oldContainer)->andReturnSelf();
        $queue->shouldReceive('setConfig')->once()->with(['driver' => 'sync'])->andReturnSelf();
        $queue->shouldReceive('setConnectionName')->once()->with('sync')->andReturnSelf();
        $queue->shouldReceive('setContainer')->once()->with($newContainer)->andReturnSelf();

        $this->assertSame($queue, $manager->connection('sync'));
        $this->assertSame($manager, $manager->setApplication($newContainer));
        $this->assertTrue($manager->connected('sync'));
        $this->assertSame($queue, $manager->connection('sync'));
    }

    protected function getContainer(): Container
    {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);
        $container->instance('config', new ConfigRepository([]));
        $container->instance(Encrypter::class, m::mock(Encrypter::class));
        $container->singleton(PoolFactory::class, PoolManager::class);

        Container::setInstance($container);

        return $container;
    }
}

enum QueueManagerTestIntIdentifier: int
{
    case Zero = 0;
}

class QueueManagerTestJob extends Job
{
    public function __construct(ContainerContract $container, string $connectionName)
    {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = 'shared';
    }

    public function getJobId(): string
    {
        return 'job';
    }

    public function getRawBody(): string
    {
        return '{"job":"job","data":[]}';
    }

    public function attempts(): int
    {
        return 1;
    }

    public function delete(): void
    {
        parent::delete();
        $this->releasePoolLease();
    }

    public function release(int $delay = 0): void
    {
        parent::release($delay);
        $this->releasePoolLease();
    }
}
