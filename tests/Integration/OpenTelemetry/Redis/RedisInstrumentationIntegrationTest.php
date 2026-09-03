<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\OpenTelemetry\Redis;

use ArrayObject;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\OpenTelemetry\Contracts\ExporterFactory;
use Hypervel\OpenTelemetry\Instrumentation\RedisInstrumentation;
use Hypervel\OpenTelemetry\OpenTelemetryManager;
use Hypervel\OpenTelemetry\OpenTelemetryServiceProvider;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SemConv\Metrics\DbMetrics;

class RedisInstrumentationIntegrationTest extends TestCase
{
    use InteractsWithRedis;

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [OpenTelemetryServiceProvider::class];
    }

    public function testBindingRefreshesAWarmedPoolAndRecordsTheNextRealCommand(): void
    {
        $initialPool = $this->app->make(PoolFactory::class)->getPool('default');
        $exporters = new RedisInstrumentationExporterFactory;
        $config = $this->app->make('config');
        $config->set('opentelemetry.metrics.exporter', 'none');
        $config->set('opentelemetry.traces.exporter', 'redis-integration');
        $config->set('opentelemetry.logs.exporter', 'none');
        $config->set('opentelemetry.exporters.redis-integration', [
            'driver' => 'redis-integration',
        ]);
        $config->set('opentelemetry.instrumentation', [
            RedisInstrumentation::class => [
                'traces' => true,
                'query_text' => false,
                'query_text_max_length' => 500,
                'metrics' => [DbMetrics::DB_CLIENT_OPERATION_DURATION => false],
            ],
        ]);

        $manager = $this->app->make(OpenTelemetryManager::class);
        $manager->extend(
            'redis-integration',
            static fn (): ExporterFactory => $exporters,
        );

        try {
            $manager->bind(ProcessIdentity::cli());

            $this->assertArrayNotHasKey('default', $this->app->make(PoolFactory::class)->pools());

            Redis::set('opentelemetry-redis-integration', 'value');

            $this->assertTrue($manager->flush());
            $this->assertNotSame(
                $initialPool,
                $this->app->make(PoolFactory::class)->pools()['default'],
            );
            $this->assertSame(
                ['SET'],
                array_map(
                    static fn ($span): string => $span->getName(),
                    $exporters->spanExporter->getSpans(),
                ),
            );
        } finally {
            $manager->shutdown();
        }
    }
}

class RedisInstrumentationExporterFactory implements ExporterFactory
{
    public InMemorySpanExporter $spanExporter;

    public InMemoryMetricExporter $metricExporter;

    public InMemoryLogExporter $logExporter;

    /**
     * Create in-memory exporters for each signal.
     */
    public function __construct()
    {
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->metricExporter = new InMemoryMetricExporter(new ArrayObject);
        $this->logExporter = new InMemoryLogExporter(new ArrayObject);
    }

    /**
     * Return the span exporter.
     */
    public function spanExporter(array $config): SpanExporterInterface
    {
        return $this->spanExporter;
    }

    /**
     * Return the metric exporter.
     */
    public function metricExporter(array $config): MetricExporterInterface
    {
        return $this->metricExporter;
    }

    /**
     * Return the log exporter.
     */
    public function logExporter(array $config): LogRecordExporterInterface
    {
        return $this->logExporter;
    }
}
