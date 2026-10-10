<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sentry;

use Hypervel\Config\Repository;
use Hypervel\Sentry\SentryConfig;
use Hypervel\Sentry\Transport\HttpPoolTransport;
use Hypervel\Sentry\Transport\HttpTransportPool;
use Hypervel\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use Sentry\ClientBuilder;

class ConfigTest extends TestCase
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

        /** @var ClientBuilder $builder */
        $builder = $this->app->make(ClientBuilder::class);

        $transport = $builder->getTransport();

        $this->assertInstanceOf(HttpPoolTransport::class, $transport);

        $pool = $this->getPoolFromTransport($transport);

        $this->assertSame(7, $pool->getOptions()->maxObjects);
        $this->assertSame(0.05, $pool->getOptions()->waitTimeout);
        $this->assertSame(120.0, $pool->getOptions()->maxLifetime);
        $this->assertNull($pool->getOptions()->maxIdleTime);
        $this->assertNull($pool->getOptions()->poolIdleTimeout);
    }

    public function testTransportLifetimeCanBeDisabled(): void
    {
        $this->resetApplicationWithConfig([
            'sentry.pool.max_lifetime' => null,
        ]);

        $builder = $this->app->make(ClientBuilder::class);
        $pool = $this->getPoolFromTransport($builder->getTransport());

        $this->assertNull($pool->getOptions()->maxLifetime);
        $this->assertNull($pool->getOptions()->maxIdleTime);
        $this->assertNull($pool->getOptions()->poolIdleTimeout);
    }

    #[DataProvider('unsupportedPoolOptions')]
    public function testUnsupportedPoolOptionsAreRejected(string $name, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs(
            "Unsupported Sentry pool option(s) [{$name}]. Supported options are [max_objects, wait_timeout, max_lifetime]."
        );

        $this->resetApplicationWithConfig([
            'sentry.dsn' => 'https://key@sentry.io/123',
            'sentry.pool' => [$name => $value],
        ]);
    }

    /**
     * Get the pool options that the transport pool does not support.
     */
    public static function unsupportedPoolOptions(): array
    {
        return [
            ['min_retained_objects', 1],
            ['max_idle_time', 30],
            ['pool_idle_timeout', 300],
            ['unknown', true],
        ];
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

    public function testStorageTelemetryIsEnabledByDefault(): void
    {
        $config = $this->withEnvironmentValues([
            'SENTRY_BREADCRUMBS_STORAGE_ENABLED' => null,
            'SENTRY_TRACE_STORAGE_ENABLED' => null,
        ], fn (): array => $this->sentryConfig());

        $this->assertTrue($config['breadcrumbs']['storage']);
        $this->assertTrue($config['tracing']['storage']);
    }

    public function testOmittedBreadcrumbAndTracingOptionsUseTheShippedDefaults(): void
    {
        $shippedConfig = $this->sentryConfig();

        $config = (new SentryConfig(new Repository([
            'sentry' => [
                ...$shippedConfig,
                'breadcrumbs' => [],
                'tracing' => [],
            ],
        ]), 'sentry'))->all();

        foreach (['breadcrumbs', 'tracing'] as $section) {
            ksort($shippedConfig[$section]);
            ksort($config[$section]);

            $this->assertSame($shippedConfig[$section], $config[$section]);
        }
    }

    public function testTraceMetricsAreEnabledByDefault(): void
    {
        $config = $this->withEnvironmentValues([
            'SENTRY_ENABLE_METRICS' => null,
        ], fn (): array => $this->sentryConfig());

        $this->assertTrue($config['enable_metrics']);
    }

    public function testValidationExceptionsAreOnlyIgnoredWhenConfigured(): void
    {
        $exception = ValidationException::withMessages(['email' => 'The email field is required.']);

        $this->getSentryHubFromContainer()->captureException($exception);

        $this->assertSentryEventCount(1);

        $this->resetApplicationWithConfig([
            'sentry.ignore_exceptions' => [ValidationException::class],
        ]);

        $this->getSentryHubFromContainer()->captureException($exception);
        $this->getSentryHubFromContainer()->captureException(new RuntimeException('Not ignored.'));

        $this->assertSentryEventCount(1);
        $this->assertSame(RuntimeException::class, $this->getLastSentryEvent()->getExceptions()[0]->getType());
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
            'SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_ENABLED' => '0',
            'SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_THRESHOLD_MS' => '0',
        ], fn (): array => $this->sentryConfig());

        $this->assertTrue($config['strict_trace_continuation']);
        $this->assertTrue($config['enable_metrics']);
        $this->assertTrue($config['send_default_pii']);
        $this->assertTrue($config['breadcrumbs']['sql_queries']);
        $this->assertTrue($config['breadcrumbs']['cache']);
        $this->assertTrue($config['tracing']['views']);
        $this->assertTrue($config['tracing']['redis_commands']);
        $this->assertSame(250, $config['tracing']['sql_origin_threshold_ms']);
        $this->assertFalse($config['tracing']['http_client_requests_origin']);
        $this->assertSame(0, $config['tracing']['http_client_requests_origin_threshold_ms']);
    }

    public function testPoolWaitTimeoutDefaultIsSetForFastFail(): void
    {
        // Default config should have a low wait_timeout for backpressure
        /** @var ClientBuilder $builder */
        $builder = $this->app->make(ClientBuilder::class);
        $pool = $this->getPoolFromTransport($builder->getTransport());

        // Should be 10ms or less for fast-fail backpressure
        $this->assertLessThanOrEqual(0.01, $pool->getOptions()->waitTimeout);
    }

    /**
     * Get the pool that backs the transport.
     */
    private function getPoolFromTransport(HttpPoolTransport $transport): HttpTransportPool
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
