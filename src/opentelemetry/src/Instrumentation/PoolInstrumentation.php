<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Instrumentation;

use Hypervel\Database\Pool\PoolFactory as DatabasePoolFactory;
use Hypervel\ObjectPool\PoolManager as ObjectPoolManager;
use Hypervel\Pool\Pool;
use Hypervel\Redis\Pool\PoolFactory as RedisPoolFactory;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableUpDownCounterInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\SemConv\Incubating\Attributes\DbIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Metrics\DbIncubatingMetrics;

class PoolInstrumentation extends AbstractInstrumentation
{
    protected const string OBJECTS_METRIC = 'hypervel.object_pool.objects';

    protected const string OBJECTS_MAX_METRIC = 'hypervel.object_pool.max';

    protected const string OBJECTS_PENDING_REQUESTS_METRIC = 'hypervel.object_pool.pending_requests';

    protected const string OBJECT_POOL_NAME_ATTRIBUTE = 'hypervel.object_pool.name';

    protected const string OBJECT_POOL_STATE_ATTRIBUTE = 'hypervel.object_pool.state';

    /**
     * Create pool instrumentation.
     */
    public function __construct(
        protected DatabasePoolFactory $databasePools,
        protected RedisPoolFactory $redisPools,
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
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_COUNT,
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX,
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_PENDING_REQUESTS,
        ], fn (string $name): bool => $this->metricEnabled($name)));
        $objectMetrics = array_values(array_filter([
            self::OBJECTS_METRIC,
            self::OBJECTS_MAX_METRIC,
            self::OBJECTS_PENDING_REQUESTS_METRIC,
        ], fn (string $name): bool => $this->metricEnabled($name)));

        if ($connectionMetrics === [] && $objectMetrics === []) {
            return;
        }

        $meter = $this->meterProvider->getMeter('hypervel.pools');

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
     * @param DbIncubatingMetrics::DB_CLIENT_CONNECTION_COUNT|DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX|DbIncubatingMetrics::DB_CLIENT_CONNECTION_PENDING_REQUESTS|self::OBJECTS_MAX_METRIC|self::OBJECTS_METRIC|self::OBJECTS_PENDING_REQUESTS_METRIC $name
     */
    protected function createInstrument(MeterInterface $meter, string $name): ObservableUpDownCounterInterface
    {
        [$unit, $description] = match ($name) {
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_COUNT => [
                '{connection}',
                'The number of connections that are currently in each state.',
            ],
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX => [
                '{connection}',
                'The maximum number of open connections allowed.',
            ],
            DbIncubatingMetrics::DB_CLIENT_CONNECTION_PENDING_REQUESTS => [
                '{request}',
                'The number of requests waiting for an open connection.',
            ],
            self::OBJECTS_METRIC => [
                '{object}',
                'The number of pooled objects that are currently in each state.',
            ],
            self::OBJECTS_MAX_METRIC => [
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
        foreach ($this->databasePools->pools() as $name => $pool) {
            $this->observeConnectionPool($observers, $pool, 'database:' . $name);
        }

        foreach ($this->redisPools->pools() as $name => $pool) {
            $this->observeConnectionPool($observers, $pool, 'redis:' . $name);
        }
    }

    /**
     * Record one existing connection-pool snapshot.
     *
     * @param array<string, ObserverInterface> $observers
     */
    protected function observeConnectionPool(array $observers, Pool $pool, string $name): void
    {
        $attributes = [DbIncubatingAttributes::DB_CLIENT_CONNECTION_POOL_NAME => $name];

        if (isset($observers[DbIncubatingMetrics::DB_CLIENT_CONNECTION_COUNT])) {
            $idle = $pool->getConnectionsInChannel();
            $used = $pool->getCurrentConnections() - $idle;
            $observer = $observers[DbIncubatingMetrics::DB_CLIENT_CONNECTION_COUNT];
            $observer->observe($idle, $attributes + [
                DbIncubatingAttributes::DB_CLIENT_CONNECTION_STATE => DbIncubatingAttributes::DB_CLIENT_CONNECTION_STATE_VALUE_IDLE,
            ]);
            $observer->observe($used, $attributes + [
                DbIncubatingAttributes::DB_CLIENT_CONNECTION_STATE => DbIncubatingAttributes::DB_CLIENT_CONNECTION_STATE_VALUE_USED,
            ]);
        }

        if (isset($observers[DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX])) {
            $observers[DbIncubatingMetrics::DB_CLIENT_CONNECTION_MAX]->observe(
                $pool->getOption()->getMaxConnections(),
                $attributes,
            );
        }

        if (isset($observers[DbIncubatingMetrics::DB_CLIENT_CONNECTION_PENDING_REQUESTS])) {
            $observers[DbIncubatingMetrics::DB_CLIENT_CONNECTION_PENDING_REQUESTS]->observe(
                $pool->getWaiters(),
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
        foreach ($this->objectPools->pools() as $identity => $pool) {
            $attributes = [self::OBJECT_POOL_NAME_ATTRIBUTE => $identity];
            $stats = null;

            if (isset($observers[self::OBJECTS_METRIC])) {
                $stats = $pool->getStats();
                $observer = $observers[self::OBJECTS_METRIC];
                $observer->observe($stats['idle'], $attributes + [
                    self::OBJECT_POOL_STATE_ATTRIBUTE => DbIncubatingAttributes::DB_CLIENT_CONNECTION_STATE_VALUE_IDLE,
                ]);
                $observer->observe($stats['borrowed'], $attributes + [
                    self::OBJECT_POOL_STATE_ATTRIBUTE => DbIncubatingAttributes::DB_CLIENT_CONNECTION_STATE_VALUE_USED,
                ]);
            }

            if (isset($observers[self::OBJECTS_MAX_METRIC])) {
                $observers[self::OBJECTS_MAX_METRIC]->observe(
                    $pool->getOptions()->maxObjects,
                    $attributes,
                );
            }

            if (isset($observers[self::OBJECTS_PENDING_REQUESTS_METRIC])) {
                $stats ??= $pool->getStats();
                $observers[self::OBJECTS_PENDING_REQUESTS_METRIC]->observe(
                    $stats['waiters'],
                    $attributes,
                );
            }
        }
    }
}
