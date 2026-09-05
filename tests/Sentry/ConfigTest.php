<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Sentry\Features\RedisFeature;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\Pool;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Sentry\ClientBuilder;
use Sentry\Transport\TransportInterface;

class ConfigTest extends SentryTestCase
{
    public function testPoolIsConstructedFromSentryPoolConfig(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.dsn' => 'https://key@sentry.io/123',
            'sentry.pool' => [
                'max_objects' => 7,
                'wait_timeout' => 0.05,
                'max_lifetime' => 120,
            ],
        ]);

        // Verify the Pool is actually constructed with the config values from sentry.pool.
        // The old bug read from 'pools.sentry' which didn't exist, so the Pool always got defaults.
        /** @var ClientBuilder $builder */
        $builder = $this->app->make(ClientBuilder::class);

        $transport = $this->getTransportFromBuilder($builder);

        $this->assertInstanceOf(HttpPoolTransport::class, $transport);

        $pool = $this->getPoolFromTransport($transport);

        $this->assertSame(7, $pool->getOptions()->maxObjects);
        $this->assertSame(0.05, $pool->getOptions()->waitTimeout);
        $this->assertSame(120.0, $pool->getOptions()->maxLifetime);
        $this->assertSame(0.0, $pool->getOptions()->maxIdleTime);
        $this->assertNull($pool->getOptions()->idleTtl);
    }

    #[DataProvider('unsupportedPoolOptions')]
    public function testUnsupportedPoolOptionsAreRejected(string $name, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unsupported Sentry pool option(s) [{$name}]. Supported options are [max_objects, wait_timeout, max_lifetime]."
        );

        $this->resetApplicationWithConfig([
            'sentry.dsn' => 'https://key@sentry.io/123',
            'sentry.pool' => [$name => $value],
        ]);
    }

    public static function unsupportedPoolOptions(): array
    {
        return [
            ['min_retained_objects', 1],
            ['max_idle_time', 30],
            ['idle_ttl', 300],
            ['unknown', true],
        ];
    }

    public function testOldPoolsKeyIsNotUsed(): void
    {
        $this->assertNull($this->app->make('config')->get('pools.sentry'));
    }

    public function testLogsChannelLevelUsesCurrentEnvironmentNames(): void
    {
        $this->assertSame('warning', $this->withEnvironmentValues([
            'SENTRY_LOG_LEVEL' => 'warning',
            'SENTRY_LOGS_LEVEL' => 'error',
            'LOG_LEVEL' => 'info',
        ], fn (): string => $this->sentryConfig()['logs_channel_level']));

        $this->assertSame('info', $this->withEnvironmentValues([
            'SENTRY_LOG_LEVEL' => null,
            'SENTRY_LOGS_LEVEL' => 'error',
            'LOG_LEVEL' => 'info',
        ], fn (): string => $this->sentryConfig()['logs_channel_level']));
    }

    public function testRedisFeatureIsInDefaultFeaturesConfig(): void
    {
        $features = $this->app->make('config')->array('sentry.features');

        $this->assertContains(RedisFeature::class, $features);
    }

    public function testStorageTelemetryIsEnabledByDefault(): void
    {
        $config = $this->withEnvironmentValues([
            'SENTRY_BREADCRUMBS_STORAGE_ENABLED' => null,
            'SENTRY_TRACE_STORAGE_ENABLED' => null,
        ], fn (): array => $this->sentryConfig());

        $this->assertTrue($config['breadcrumbs']['storage']);
        $this->assertTrue($config['tracing']['storage']);
    }

    public function testTraceMetricsAreEnabledByDefault(): void
    {
        $config = $this->withEnvironmentValues([
            'SENTRY_ENABLE_METRICS' => null,
        ], fn (): array => $this->sentryConfig());

        $this->assertTrue($config['enable_metrics']);
    }

    public function testBooleanEnvironmentValuesAreNormalized(): void
    {
        $config = $this->withEnvironmentValues([
            'SENTRY_STRICT_TRACE_CONTINUATION' => '1',
            'SENTRY_ENABLE_METRICS' => '1',
            'SENTRY_SEND_DEFAULT_PII' => '1',
            'SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED' => '1',
            'SENTRY_BREADCRUMBS_CACHE_ENABLED' => '1',
            'SENTRY_TRACE_VIEWS_ENABLED' => '1',
            'SENTRY_TRACE_REDIS_COMMANDS' => '1',
            'SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS' => '250',
        ], fn (): array => $this->sentryConfig());

        $this->assertTrue($config['strict_trace_continuation']);
        $this->assertTrue($config['enable_metrics']);
        $this->assertTrue($config['send_default_pii']);
        $this->assertTrue($config['breadcrumbs']['sql_queries']);
        $this->assertTrue($config['breadcrumbs']['cache']);
        $this->assertTrue($config['tracing']['views']);
        $this->assertTrue($config['tracing']['redis_commands']);
        $this->assertSame(250, $config['tracing']['sql_origin_threshold_ms']);
    }

    public function testPoolWaitTimeoutDefaultIsSetForFastFail(): void
    {
        // Default config should have a low wait_timeout for backpressure
        /** @var ClientBuilder $builder */
        $builder = $this->app->make(ClientBuilder::class);
        $transport = $this->getTransportFromBuilder($builder);
        $pool = $this->getPoolFromTransport($transport);

        // Should be 10ms or less for fast-fail backpressure
        $this->assertLessThanOrEqual(0.01, $pool->getOptions()->waitTimeout);
    }

    private function getTransportFromBuilder(ClientBuilder $builder): TransportInterface
    {
        $reflection = new ReflectionProperty($builder, 'transport');

        return $reflection->getValue($builder);
    }

    private function getPoolFromTransport(HttpPoolTransport $transport): Pool
    {
        $reflection = new ReflectionProperty($transport, 'pool');

        return $reflection->getValue($transport);
    }

    /**
     * Load the package configuration.
     */
    private function sentryConfig(): array
    {
        return require dirname(__DIR__, 2) . '/src/sentry/config/sentry.php';
    }
}
