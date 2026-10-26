<?php

declare(strict_types=1);

namespace Hypervel\Tests\Queue;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Encryption\Encrypter as EncrypterContract;
use Hypervel\Contracts\ObjectPool\Factory as PoolFactory;
use Hypervel\Contracts\Queue\ClearableQueue;
use Hypervel\Contracts\Queue\Queue;
use Hypervel\Encryption\Encrypter;
use Hypervel\Events\Dispatcher;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Queue\ClearableQueuePoolProxy;
use Hypervel\Queue\Connectors\ConnectorInterface;
use Hypervel\Queue\Connectors\FailoverConnector;
use Hypervel\Queue\Connectors\NullConnector;
use Hypervel\Queue\Connectors\SyncConnector;
use Hypervel\Queue\Jobs\Job;
use Hypervel\Queue\NullQueue;
use Hypervel\Queue\Queue as BaseQueue;
use Hypervel\Queue\QueueManager;
use Hypervel\Queue\QueuePoolProxy;
use Hypervel\Queue\SyncQueue;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;

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
        $manager->addConnector('sync', fn (): ConnectorInterface => new SyncConnector);

        $queue = $manager->connection('sync');

        $this->assertInstanceOf(SyncQueue::class, $queue);
        $this->assertSame('sync', $queue->getConnectionName());
        $this->assertSame($container, $queue->getContainer());
        $this->assertSame(['driver' => 'sync'], $queue->getConfig());
    }

    public function testOtherConnectionCanBeResolved(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.foo', ['driver' => 'bar']);

        $manager = new QueueManager($container);
        $manager->addConnector('bar', fn (): ConnectorInterface => new SyncConnector);

        $queue = $manager->connection('foo');

        $this->assertInstanceOf(SyncQueue::class, $queue);
        $this->assertSame('foo', $queue->getConnectionName());
        $this->assertSame($container, $queue->getContainer());
        $this->assertSame(['driver' => 'bar'], $queue->getConfig());
    }

    public function testNullConnectionCanBeResolved(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'null');

        $manager = new QueueManager($container);
        $manager->addConnector('null', fn (): ConnectorInterface => new SyncConnector);

        $queue = $manager->connection('null');

        $this->assertInstanceOf(SyncQueue::class, $queue);
        $this->assertSame('null', $queue->getConnectionName());
        $this->assertSame($container, $queue->getContainer());
        $this->assertSame(['driver' => 'null'], $queue->getConfig());
    }

    public function testEnumConnectionCanBeResolved(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.sync', ['driver' => 'sync']);

        $manager = new QueueManager($container);
        $manager->addConnector('sync', fn (): ConnectorInterface => new SyncConnector);

        $queue = $manager->connection(QueueConnectionName::Sync);

        $this->assertInstanceOf(SyncQueue::class, $queue);
        $this->assertSame('sync', $queue->getConnectionName());
        $this->assertSame($container, $queue->getContainer());
        $this->assertSame(['driver' => 'sync'], $queue->getConfig());
    }

    public function testEnumConnectionCanBeChecked(): void
    {
        $container = $this->getContainer();
        $config = $container->make('config');
        $config->set('queue.default', 'sync');
        $config->set('queue.connections.sync', ['driver' => 'sync']);

        $manager = new QueueManager($container);
        $manager->addConnector('sync', fn (): ConnectorInterface => new SyncConnector);

        $this->assertFalse($manager->connected(QueueConnectionName::Sync));
        $manager->connection(QueueConnectionName::Sync);
        $this->assertTrue($manager->connected(QueueConnectionName::Sync));
    }

    public function testCreatePayloadUsingDoesNotResolveTheDefaultConnection(): void
    {
        $container = $this->getContainer();
        $container->make('config')->set('queue.default', 'missing');

        $manager = new QueueManager($container);
        $manager->createPayloadUsing(static fn (?string $connection, ?string $queue, ?array $payload): array => ['foo' => 'bar']);

        $this->assertFalse($manager->connected('missing'));

        $callbacks = (new ReflectionProperty(BaseQueue::class, 'createPayloadCallbacks'))->getValue();

        $this->assertSame(['foo' => 'bar'], $callbacks[array_key_last($callbacks)](null, null, null));
    }

    public function testSetDefaultDriverAcceptsBackedEnum(): void
    {
        $container = $this->getContainer();

        $manager = new QueueManager($container);
        $manager->setDefaultDriver(QueueConnectionName::Sync);

        $this->assertSame('sync', $container->make('config')->get('queue.default'));
    }

    #[DataProvider('circularConnections')]
    public function testRejectsCircularFailoverDefinitions(array $connections, string $cycle): void
    {
        $manager = $this->getFailoverManager($connections);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Circular queue connection definition detected: ' . $cycle . '.');

        $manager->connection('main');
    }

    /**
     * Provide circular failover configurations.
     */
    public static function circularConnections(): array
    {
        return [
            'self' => [[
                'main' => ['driver' => 'failover', 'connections' => ['main']],
            ], 'main -> main'],
            'indirect' => [[
                'main' => ['driver' => 'failover', 'connections' => ['first']],
                'first' => ['driver' => 'failover', 'connections' => ['second']],
                'second' => ['driver' => 'failover', 'connections' => ['first']],
            ], 'first -> second -> first'],
        ];
    }

    public function testNestedFailoverConnectionsCanShareAChild(): void
    {
        $manager = $this->getFailoverManager([
            'main' => ['driver' => 'failover', 'connections' => ['first', 'second']],
            'first' => ['driver' => 'failover', 'connections' => ['leaf']],
            'second' => ['driver' => 'failover', 'connections' => ['leaf']],
            'leaf' => ['driver' => 'null'],
        ]);

        $this->assertSame(0, $manager->connection('main')->size());
        $this->assertSame(0, $manager->connection('second')->size());
    }

    public function testCustomConnectorCanReplaceFailover(): void
    {
        $manager = $this->getFailoverManager([
            'main' => ['driver' => 'failover', 'connections' => ['main']],
        ]);
        $manager->extend('failover', fn (): NullConnector => new NullConnector);

        $this->assertSame(0, $manager->connection('main')->size());
    }

    public function testMissingBackupConnectionRemainsDeferred(): void
    {
        $manager = $this->getFailoverManager([
            'main' => ['driver' => 'failover', 'connections' => ['healthy', 'missing']],
            'healthy' => ['driver' => 'null'],
        ]);

        $this->assertSame(0, $manager->connection('main')->size());
    }

    public function testBackupConnectorFactoryFailureRemainsDeferred(): void
    {
        $manager = $this->getFailoverManager([
            'main' => ['driver' => 'failover', 'connections' => ['healthy', 'backup']],
            'healthy' => ['driver' => 'null'],
            'backup' => ['driver' => 'custom', 'connections' => ['remote']],
        ]);
        $failure = new RuntimeException('Backup dependency unavailable');
        $manager->extend('custom', fn () => throw $failure);

        $this->assertSame(0, $manager->connection('main')->size());

        $this->expectExceptionObject($failure);
        $manager->connection('backup');
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
        $manager->addPoolableDriver('bar');

        $this->assertInstanceOf(QueuePoolProxy::class, $manager->connection('foo'));
    }

    #[DataProvider('clearablePoolProxyDriverProvider')]
    public function testBuiltInClearablePooledDriversResolveClearableProxies(
        string $driver,
        bool $poolableByDefault,
    ): void {
        $container = $this->getContainer();
        $container->make('config')->set('queue.connections.foo', ['driver' => $driver]);
        $manager = new QueueManager($container);

        if (! $poolableByDefault) {
            $manager->addPoolableDriver($driver);
        }

        $connection = $manager->connection('foo');

        $this->assertInstanceOf(ClearableQueuePoolProxy::class, $connection);
        $this->assertInstanceOf(ClearableQueue::class, $connection);
    }

    public static function clearablePoolProxyDriverProvider(): array
    {
        return [
            'database' => ['database', false],
            'redis' => ['redis', false],
            'sqs' => ['sqs', true],
        ];
    }

    public function testDefaultPooledBeanstalkdConnectionRemainsNonClearable(): void
    {
        $container = $this->getContainer();
        $container->make('config')->set('queue.connections.foo', ['driver' => 'beanstalkd']);
        $manager = new QueueManager($container);

        $connection = $manager->connection('foo');

        $this->assertInstanceOf(QueuePoolProxy::class, $connection);
        $this->assertNotInstanceOf(ClearableQueue::class, $connection);
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
        $manager->addPoolableDriver('custom');
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
        $manager->addPoolableDriver('custom');
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
        $manager->addPoolableDriver('custom');
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
        $manager->addPoolableDriver('custom');
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

    /**
     * Create a manager with real failover and null connectors.
     */
    protected function getFailoverManager(array $connections): QueueManager
    {
        $container = $this->getContainer();
        $container->make('config')->set('queue.connections', $connections);
        $manager = new QueueManager($container);
        $manager->addConnector('failover', fn (): FailoverConnector => new FailoverConnector($manager, new Dispatcher($container)));
        $manager->addConnector('null', fn (): NullConnector => new NullConnector);

        return $manager;
    }

    protected function getContainer(): Container
    {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);
        $container->instance('config', new ConfigRepository([]));
        $container->instance(EncrypterContract::class, new Encrypter(str_repeat('a', 16)));
        $container->singleton(PoolFactory::class, PoolManager::class);

        Container::setInstance($container);

        return $container;
    }
}

enum QueueConnectionName: string
{
    case Sync = 'sync';
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
