<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\Database\Pool\PoolManager as DatabasePoolManager;
use Hypervel\ObjectPool\PoolManager as ObjectPoolManager;
use Hypervel\Redis\Pool\PoolManager as RedisPoolManager;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;

class PoolInstrumentation extends AbstractInstrumentation
{
    // Development-status conventions are defined here rather than imported, because
    // the sem-conv package removes renamed incubating constants in minor releases.
    protected const string CONNECTION_COUNT_METRIC = 'db.client.connection.count';

    protected const string CONNECTION_LIMIT_METRIC = 'db.client.connection.limit';

    protected const string CONNECTION_PENDING_REQUESTS_METRIC = 'db.client.connection.pending_requests';

    protected const string CONNECTION_POOL_NAME_ATTRIBUTE = 'db.client.connection.pool.name';

    protected const string CONNECTION_STATE_ATTRIBUTE = 'db.client.connection.state';

    protected const string STATE_IDLE = 'idle';

    protected const string STATE_USED = 'used';

    protected const string OBJECTS_METRIC = 'hypervel.object_pool.objects';

    protected const string OBJECTS_LIMIT_METRIC = 'hypervel.object_pool.limit';

    protected const string OBJECTS_PENDING_REQUESTS_METRIC = 'hypervel.object_pool.pending_requests';

    protected const string OBJECT_POOL_NAME_ATTRIBUTE = 'hypervel.object_pool.name';

    protected const string OBJECT_POOL_STATE_ATTRIBUTE = 'hypervel.object_pool.state';

    /**
     * Create pool instrumentation.
     */
    public function __construct(
        protected DatabasePoolManager $databasePools,
        protected RedisPoolManager $redisPools,
        protected ObjectPoolManager $objectPools,
        protected MeterProviderInterface $meterProvider,
    ) {
    }

    /**
     * Register pool instruments and collection callbacks.
     */
    protected function registerInstrumentation(): void
    {
        $connectionMetrics = array_values(array_filter([
            self::CONNECTION_COUNT_METRIC,
            self::CONNECTION_LIMIT_METRIC,
            self::CONNECTION_PENDING_REQUESTS_METRIC,
        ], fn (string $name): bool => $this->metricEnabled($name)));
        $objectMetrics = array_values(array_filter([
            self::OBJECTS_METRIC,
            self::OBJECTS_LIMIT_METRIC,
            self::OBJECTS_PENDING_REQUESTS_METRIC,
        ], fn (string $name): bool => $this->metricEnabled($name)));

        if ($connectionMetrics === [] && $objectMetrics === []) {
            return;
        }

        $meter = $this->meterProvider->getMeter('hypervel.pools', schemaUrl: $this->schemaUrl());

        if ($connectionMetrics !== []) {
            $instruments = array_map(
                fn (string $name): ObservableUpDownCounterInterface => $this->createInstrument($meter, $name),
                $connectionMetrics,
            );

            $meter->batchObserve(
                function (ObserverInterface ...$observers) use ($connectionMetrics): void {
                    $this->observeConnectionPools($this->indexObservers($connectionMetrics, $observers));
                },
                $instruments[0],
                ...array_slice($instruments, 1),
            );
        }

        if ($objectMetrics !== []) {
            $instruments = array_map(
                fn (string $name): ObservableUpDownCounterInterface => $this->createInstrument($meter, $name),
                $objectMetrics,
            );

            $meter->batchObserve(
                function (ObserverInterface ...$observers) use ($objectMetrics): void {
                    $this->observeObjectPools($this->indexObservers($objectMetrics, $observers));
                },
                $instruments[0],
                ...array_slice($instruments, 1),
            );
        }
    }

    /**
     * Create one observable pool instrument.
     *
     * @param self::CONNECTION_COUNT_METRIC|self::CONNECTION_LIMIT_METRIC|self::CONNECTION_PENDING_REQUESTS_METRIC|self::OBJECTS_LIMIT_METRIC|self::OBJECTS_METRIC|self::OBJECTS_PENDING_REQUESTS_METRIC $name
     */
    protected function createInstrument(MeterInterface $meter, string $name): ObservableUpDownCounterInterface
    {
        [$unit, $description] = match ($name) {
            self::CONNECTION_COUNT_METRIC => [
                '{connection}',
                'The number of connections that are currently in each state.',
            ],
            self::CONNECTION_LIMIT_METRIC => [
                '{connection}',
                'The maximum number of open connections allowed.',
            ],
            self::CONNECTION_PENDING_REQUESTS_METRIC => [
                '{request}',
                'The number of requests waiting for an open connection.',
            ],
            self::OBJECTS_METRIC => [
                '{object}',
                'The number of pooled objects that are currently in each state.',
            ],
            self::OBJECTS_LIMIT_METRIC => [
                '{object}',
                'The maximum number of objects allowed in the pool.',
            ],
            self::OBJECTS_PENDING_REQUESTS_METRIC => [
                '{request}',
                'The number of requests waiting for a pooled object.',
            ],
        };

        return $meter->createObservableUpDownCounter($name, $unit, $description);
    }

    /**
     * Record snapshots from every existing database and Redis connection pool.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeConnectionPools(array $observers): void
    {
        foreach ($this->databasePools->getPools() as $name => $pool) {
            $this->observeConnectionPool($observers, $pool, 'database:' . $name);
        }

        foreach ($this->redisPools->getPools() as $name => $pool) {
            $this->observeConnectionPool($observers, $pool, 'redis:' . $name);
        }
    }

    /**
     * Record one existing connection-pool snapshot.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeConnectionPool(array $observers, ConnectionPool $pool, string $name): void
    {
        $attributes = [self::CONNECTION_POOL_NAME_ATTRIBUTE => $name];

        if (isset($observers[self::CONNECTION_COUNT_METRIC])) {
            $idle = $pool->getIdleCount();
            $used = $pool->getManagedCount() - $idle;
            $observer = $observers[self::CONNECTION_COUNT_METRIC];
            $observer->observe($idle, $attributes + [
                self::CONNECTION_STATE_ATTRIBUTE => self::STATE_IDLE,
            ]);
            $observer->observe($used, $attributes + [
                self::CONNECTION_STATE_ATTRIBUTE => self::STATE_USED,
            ]);
        }

        if (isset($observers[self::CONNECTION_LIMIT_METRIC])) {
            $observers[self::CONNECTION_LIMIT_METRIC]->observe(
                $pool->getOptions()->maxConnections,
                $attributes,
            );
        }

        if (isset($observers[self::CONNECTION_PENDING_REQUESTS_METRIC])) {
            $observers[self::CONNECTION_PENDING_REQUESTS_METRIC]->observe(
                $pool->getWaitingCount(),
                $attributes,
            );
        }
    }

    /**
     * Record snapshots from every existing object pool.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeObjectPools(array $observers): void
    {
        foreach ($this->objectPools->getPools() as $identity => $pool) {
            $attributes = [self::OBJECT_POOL_NAME_ATTRIBUTE => $identity];
            $stats = null;

            if (isset($observers[self::OBJECTS_METRIC])) {
                $stats = $pool->getStats();
                $observer = $observers[self::OBJECTS_METRIC];
                $observer->observe($stats['idle'], $attributes + [
                    self::OBJECT_POOL_STATE_ATTRIBUTE => self::STATE_IDLE,
                ]);
                $observer->observe($stats['managed'] - $stats['idle'], $attributes + [
                    self::OBJECT_POOL_STATE_ATTRIBUTE => self::STATE_USED,
                ]);
            }

            if (isset($observers[self::OBJECTS_LIMIT_METRIC])) {
                $observers[self::OBJECTS_LIMIT_METRIC]->observe(
                    $pool->getOptions()->maxObjects,
                    $attributes,
                );
            }

            if (isset($observers[self::OBJECTS_PENDING_REQUESTS_METRIC])) {
                $stats ??= $pool->getStats();
                $observers[self::OBJECTS_PENDING_REQUESTS_METRIC]->observe(
                    $stats['waiting'],
                    $attributes,
                );
            }
        }
    }
}
