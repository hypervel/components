<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry\Instrumentation;

use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\ConnectionPool\PoolOptions as ConnectionPoolOptions;
use Hypervel\Contracts\ObjectPool\Factory as ObjectPoolFactory;
use Hypervel\Contracts\ObjectPool\ObjectPool;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Pool\PoolManager as DatabasePoolManager;
use Hypervel\ObjectPool\CallbackObjectPool;
use Hypervel\ObjectPool\Concerns\HasPoolProxy;
use Hypervel\ObjectPool\PoolDefinition;
use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\ObjectPool\PoolManager as ObjectPoolManager;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\OpenTelemetry\Instrumentation\PoolInstrumentation;
use Hypervel\Redis\Pool\PoolManager as RedisPoolManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SemConv\Incubating\Metrics\DbIncubatingMetrics;
use Swoole\Coroutine\Channel;
use WeakReference;

class PoolInstrumentationTest extends TestCase
{
    private InMemoryExporter $exporter;

    private ExportingReader $reader;

    private MeterProvider $meterProvider;

    protected function setUpInCoroutine(): void
    {
        $this->exporter = new InMemoryExporter;
        $this->reader = new ExportingReader($this->exporter);
        $this->meterProvider = (new MeterProviderBuilder)
            ->addReader($this->reader)
            ->build();
    }

    protected function tearDownInCoroutine(): void
    {
        $this->meterProvider->shutdown();
    }

    public function testRecordsExistingConnectionAndObjectPoolsWithExactWireIdentities(): void
    {
        $databasePool = $this->connectionPool(current: 4, idle: 2, max: 10, waiters: 1);
        $redisPool = $this->connectionPool(current: 3, idle: 1, max: 8, waiters: 2);
        $databasePools = m::mock(DatabasePoolManager::class);
        $databasePools->shouldReceive('getPools')->once()->andReturn(['default' => $databasePool]);
        $redisPools = m::mock(RedisPoolManager::class);
        $redisPools->shouldReceive('getPools')->once()->andReturn(['default' => $redisPool]);
        $objectPools = new ObjectPoolManager;
        $definitions = new PoolDefinitionManagerStub($objectPools);
        $autoDefinition = $definitions->definition(
            'mailer',
            ['max_objects' => 4],
            ['dsn' => 'smtp://mail.test'],
        );
        $namedDefinition = $definitions->definition(
            'filesystem',
            ['name' => 'shared-cloud', 'max_objects' => 5],
            ['bucket' => 'documents'],
        );
        $autoPool = $objectPools->getOrCreate($autoDefinition, static fn (): object => new PoolMetricObject);
        $namedPool = $objectPools->getOrCreate($namedDefinition, static fn (): object => new PoolMetricObject);
        $directPool = $objectPools->pool(
            'app:reports',
            static fn (): object => new PoolMetricObject,
            ['max_objects' => 6],
        );
        $autoIdle = $autoPool->borrow();
        $autoBorrowed = $autoPool->borrow();
        $namedBorrowed = $namedPool->borrow();
        $directIdle = $directPool->borrow();
        $autoPool->release($autoIdle);
        $directPool->release($directIdle);

        try {
            $instrumentation = $this->instrumentation($databasePools, $redisPools, $objectPools);
            $instrumentation->register($this->options());
            $metrics = $this->collect();

            $connectionCount = $metrics['db.client.connection.count'];
            $this->assertPoint($connectionCount, 2, [
                'db.client.connection.pool.name' => 'database:default',
                'db.client.connection.state' => 'idle',
            ]);
            $this->assertPoint($connectionCount, 2, [
                'db.client.connection.pool.name' => 'database:default',
                'db.client.connection.state' => 'used',
            ]);
            $this->assertPoint($connectionCount, 1, [
                'db.client.connection.pool.name' => 'redis:default',
                'db.client.connection.state' => 'idle',
            ]);
            $this->assertPoint($connectionCount, 2, [
                'db.client.connection.pool.name' => 'redis:default',
                'db.client.connection.state' => 'used',
            ]);
            $this->assertPoint($metrics['db.client.connection.max'], 10, [
                'db.client.connection.pool.name' => 'database:default',
            ]);
            $this->assertPoint($metrics['db.client.connection.max'], 8, [
                'db.client.connection.pool.name' => 'redis:default',
            ]);
            $this->assertPoint($metrics['db.client.connection.pending_requests'], 1, [
                'db.client.connection.pool.name' => 'database:default',
            ]);
            $this->assertPoint($metrics['db.client.connection.pending_requests'], 2, [
                'db.client.connection.pool.name' => 'redis:default',
            ]);

            $autoIdentity = PoolDefinitionManagerStub::class
                . ':auto:mailer:'
                . PoolFingerprint::fromConfig(['dsn' => 'smtp://mail.test']);
            $namedIdentity = PoolDefinitionManagerStub::class . ':named:shared-cloud';
            $objects = $metrics['hypervel.object_pool.objects'];
            $this->assertPoint($objects, 1, [
                'hypervel.object_pool.name' => $autoIdentity,
                'hypervel.object_pool.state' => 'idle',
            ]);
            $this->assertPoint($objects, 1, [
                'hypervel.object_pool.name' => $autoIdentity,
                'hypervel.object_pool.state' => 'used',
            ]);
            $this->assertPoint($objects, 0, [
                'hypervel.object_pool.name' => $namedIdentity,
                'hypervel.object_pool.state' => 'idle',
            ]);
            $this->assertPoint($objects, 1, [
                'hypervel.object_pool.name' => $namedIdentity,
                'hypervel.object_pool.state' => 'used',
            ]);
            $this->assertPoint($objects, 1, [
                'hypervel.object_pool.name' => 'app:reports',
                'hypervel.object_pool.state' => 'idle',
            ]);
            $this->assertPoint($objects, 0, [
                'hypervel.object_pool.name' => 'app:reports',
                'hypervel.object_pool.state' => 'used',
            ]);
            $this->assertPoint($metrics['hypervel.object_pool.max'], 4, [
                'hypervel.object_pool.name' => $autoIdentity,
            ]);
            $this->assertPoint($metrics['hypervel.object_pool.max'], 5, [
                'hypervel.object_pool.name' => $namedIdentity,
            ]);
            $this->assertPoint($metrics['hypervel.object_pool.max'], 6, [
                'hypervel.object_pool.name' => 'app:reports',
            ]);
            $this->assertPoint($metrics['hypervel.object_pool.pending_requests'], 0, [
                'hypervel.object_pool.name' => $autoIdentity,
            ]);
        } finally {
            $autoPool->release($autoBorrowed);
            $namedPool->release($namedBorrowed);
            $objectPools->purgeAll();
        }
    }

    public function testUsedObjectCountIncludesCapacityHeldDuringDestruction(): void
    {
        $destroyStarted = new Channel(1);
        $finishDestroy = new Channel(1);
        $pool = new CallbackObjectPool(
            static fn (): object => new PoolMetricObject,
            PoolOptions::fromArray([]),
            static function () use ($destroyStarted, $finishDestroy): void {
                $destroyStarted->push(true);
                $finishDestroy->pop(1.0);
            },
        );
        $objectPools = m::mock(ObjectPoolManager::class);
        $objectPools->shouldReceive('getPools')->twice()->andReturn(['app:reports' => $pool]);
        $databasePools = m::mock(DatabasePoolManager::class);
        $databasePools->shouldNotReceive('getPools');
        $redisPools = m::mock(RedisPoolManager::class);
        $redisPools->shouldNotReceive('getPools');
        $instrumentation = $this->instrumentation($databasePools, $redisPools, $objectPools);
        $instrumentation->register($this->options(enabled: ['hypervel.object_pool.objects']));
        $borrowed = $pool->borrow();
        $child = Coroutine::create(static fn () => $pool->discard($borrowed));

        try {
            $this->assertTrue($destroyStarted->pop(1.0));
            $this->assertSame(0, $pool->getBorrowedCount());
            $during = $this->collect();

            $this->assertPoint($during['hypervel.object_pool.objects'], 1, [
                'hypervel.object_pool.name' => 'app:reports',
                'hypervel.object_pool.state' => 'used',
            ]);
            $this->assertPoint($during['hypervel.object_pool.objects'], 0, [
                'hypervel.object_pool.name' => 'app:reports',
                'hypervel.object_pool.state' => 'idle',
            ]);
        } finally {
            $finishDestroy->close();
            Coroutine::join([$child], 1.0);
            $pool->close();
        }

        $this->assertFalse(Coroutine::exists($child));
        $after = $this->collect();
        $this->assertPoint($after['hypervel.object_pool.objects'], 0, [
            'hypervel.object_pool.name' => 'app:reports',
            'hypervel.object_pool.state' => 'used',
        ]);
    }

    public function testDisabledMetricsDoNotResolveTheMeterOrInspectPoolRegistries(): void
    {
        $databasePools = m::mock(DatabasePoolManager::class);
        $databasePools->shouldNotReceive('getPools');
        $redisPools = m::mock(RedisPoolManager::class);
        $redisPools->shouldNotReceive('getPools');
        $objectPools = m::mock(ObjectPoolManager::class);
        $objectPools->shouldNotReceive('getPools');
        $meterProvider = m::mock(MeterProviderInterface::class);
        $meterProvider->shouldNotReceive('getMeter');

        $instrumentation = new PoolInstrumentation(
            $databasePools,
            $redisPools,
            $objectPools,
            $meterProvider,
        );
        $instrumentation->register($this->options(enabled: []));

        $this->addToAssertionCount(1);
    }

    public function testIndividualConnectionMetricsReadOnlyTheirRequiredSourceValues(): void
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->shouldReceive('getOptions')->once()->andReturn(ConnectionPoolOptions::fromArray(['max_connections' => 12]));
        $pool->shouldNotReceive('getManagedCount');
        $pool->shouldNotReceive('getIdleCount');
        $pool->shouldNotReceive('getWaitingCount');
        $pool->shouldNotReceive('getStats');
        $databasePools = m::mock(DatabasePoolManager::class);
        $databasePools->shouldReceive('getPools')->once()->andReturn(['primary' => $pool]);
        $redisPools = m::mock(RedisPoolManager::class);
        $redisPools->shouldReceive('getPools')->once()->andReturn([]);
        $objectPools = m::mock(ObjectPoolManager::class);
        $objectPools->shouldNotReceive('getPools');

        $instrumentation = $this->instrumentation($databasePools, $redisPools, $objectPools);
        $instrumentation->register($this->options(
            enabled: [DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX],
        ));
        $metrics = $this->collect();

        $this->assertSame([DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX], array_keys($metrics));
        $this->assertPoint($metrics[DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX], 12, [
            'db.client.connection.pool.name' => 'database:primary',
        ]);
    }

    public function testIndividualObjectMetricsReadOnlyTheirRequiredSourceValues(): void
    {
        $objectPool = m::mock(ObjectPool::class);
        $objectPool->shouldReceive('getOptions')->once()->andReturn(PoolOptions::fromArray(['max_objects' => 7]));
        $objectPool->shouldNotReceive('getStats');
        $objectPools = m::mock(ObjectPoolManager::class);
        $objectPools->shouldReceive('getPools')->once()->andReturn(['app:bounded' => $objectPool]);
        $databasePools = m::mock(DatabasePoolManager::class);
        $databasePools->shouldNotReceive('getPools');
        $redisPools = m::mock(RedisPoolManager::class);
        $redisPools->shouldNotReceive('getPools');

        $instrumentation = $this->instrumentation($databasePools, $redisPools, $objectPools);
        $instrumentation->register($this->options(enabled: ['hypervel.object_pool.max']));
        $metrics = $this->collect();

        $this->assertSame(['hypervel.object_pool.max'], array_keys($metrics));
        $this->assertPoint($metrics['hypervel.object_pool.max'], 7, [
            'hypervel.object_pool.name' => 'app:bounded',
        ]);
    }

    public function testBoundCallbacksStopCollectingAfterTheInstrumentationIsDestroyed(): void
    {
        $databasePools = m::mock(DatabasePoolManager::class);
        $databasePools->shouldReceive('getPools')->once()->andReturn([]);
        $redisPools = m::mock(RedisPoolManager::class);
        $redisPools->shouldReceive('getPools')->once()->andReturn([]);
        $objectPools = m::mock(ObjectPoolManager::class);
        $objectPools->shouldNotReceive('getPools');
        $instrumentation = $this->instrumentation($databasePools, $redisPools, $objectPools);
        $reference = WeakReference::create($instrumentation);
        $instrumentation->register($this->options(enabled: [
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_COUNT,
        ]));

        $this->collect();
        unset($instrumentation);
        gc_collect_cycles();
        $this->collect();

        $this->assertNull($reference->get());
    }

    public function testRemovedObjectPoolDisappearsFromTheNextCollection(): void
    {
        $databasePools = m::mock(DatabasePoolManager::class);
        $databasePools->shouldNotReceive('getPools');
        $redisPools = m::mock(RedisPoolManager::class);
        $redisPools->shouldNotReceive('getPools');
        $objectPools = new ObjectPoolManager;
        $objectPools->pool('app:ephemeral', static fn (): object => new PoolMetricObject);
        $instrumentation = $this->instrumentation($databasePools, $redisPools, $objectPools);
        $instrumentation->register($this->options(enabled: ['hypervel.object_pool.objects']));

        $first = $this->collect();
        $this->assertPoint($first['hypervel.object_pool.objects'], 0, [
            'hypervel.object_pool.name' => 'app:ephemeral',
            'hypervel.object_pool.state' => 'idle',
        ]);
        $objectPools->purge('app:ephemeral');
        $second = $this->collect();

        $this->assertInstanceOf(Sum::class, $second['hypervel.object_pool.objects']->data);
        $this->assertCount(0, $second['hypervel.object_pool.objects']->data->dataPoints);
    }

    /**
     * Create pool instrumentation.
     */
    private function instrumentation(
        DatabasePoolManager $databasePools,
        RedisPoolManager $redisPools,
        ObjectPoolManager $objectPools,
    ): PoolInstrumentation {
        return new PoolInstrumentation(
            $databasePools,
            $redisPools,
            $objectPools,
            $this->meterProvider,
        );
    }

    /**
     * Create a connection pool with one exact snapshot.
     */
    private function connectionPool(int $current, int $idle, int $max, int $waiters): ConnectionPool
    {
        $pool = m::mock(ConnectionPool::class);
        $pool->shouldReceive('getManagedCount')->once()->andReturn($current);
        $pool->shouldReceive('getIdleCount')->once()->andReturn($idle);
        $pool->shouldReceive('getOptions')->once()->andReturn(ConnectionPoolOptions::fromArray(['max_connections' => $max]));
        $pool->shouldReceive('getWaitingCount')->once()->andReturn($waiters);

        return $pool;
    }

    /**
     * Return normalized metric switches.
     *
     * @param null|list<string> $enabled
     * @return array{metrics: array<string, bool>}
     */
    private function options(?array $enabled = null): array
    {
        $names = [
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_COUNT,
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX,
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_PENDING_REQUESTS,
            'hypervel.object_pool.objects',
            'hypervel.object_pool.max',
            'hypervel.object_pool.pending_requests',
        ];

        $metrics = [];

        foreach ($names as $name) {
            $metrics[$name] = $enabled === null || in_array($name, $enabled, true);
        }

        return ['metrics' => $metrics];
    }

    /**
     * Collect and index one export by metric name.
     *
     * @return array<string, Metric>
     */
    private function collect(): array
    {
        $this->reader->collect();
        $indexed = [];

        foreach ($this->exporter->collect(true) as $metric) {
            $indexed[$metric->name] = $metric;
        }

        return $indexed;
    }

    /**
     * Assert one exact numeric point exists.
     *
     * @param array<string, string> $attributes
     */
    private function assertPoint(Metric $metric, int $value, array $attributes): void
    {
        $this->assertInstanceOf(Sum::class, $metric->data);

        foreach ($metric->data->dataPoints as $point) {
            if ($this->hasAttributes($point, $attributes)) {
                $this->assertSame($value, $point->value);

                return;
            }
        }

        $this->fail('The expected metric point was not exported.');
    }

    /**
     * Determine whether a point has every expected attribute.
     *
     * @param array<string, string> $attributes
     */
    private function hasAttributes(NumberDataPoint $point, array $attributes): bool
    {
        foreach ($attributes as $name => $value) {
            if ($point->attributes->get($name) !== $value) {
                return false;
            }
        }

        return true;
    }
}

class PoolDefinitionManagerStub
{
    use HasPoolProxy;

    /**
     * Create a pool-definition manager stub.
     */
    public function __construct(protected ObjectPoolFactory $factory)
    {
    }

    /**
     * Build one framework-style pool definition.
     */
    public function definition(string $resource, array $poolConfig, array $fingerprintSource): PoolDefinition
    {
        return $this->poolDefinition($resource, $poolConfig, $fingerprintSource);
    }

    /**
     * Get the object-pool factory.
     */
    protected function poolFactory(): ObjectPoolFactory
    {
        return $this->factory;
    }
}

class PoolMetricObject
{
}
