<?php

declare(strict_types=1);

namespace Hypervel\Tests\OpenTelemetry;

use ArrayObject;
use Hypervel\Contracts\Container\Container;
use Hypervel\OpenTelemetry\Contracts\ExporterFactory;
use Hypervel\OpenTelemetry\Contracts\MetricView;
use Hypervel\OpenTelemetry\ProviderFactory;
use Hypervel\OpenTelemetry\Support\ConfigurationNormalizer;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as InMemoryLogExporter;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as InMemoryMetricExporter;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\View\SelectionCriteria\InstrumentNameCriteria;
use OpenTelemetry\SDK\Metrics\View\ViewTemplate;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as InMemorySpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\DeploymentIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\ServiceIncubatingAttributes;
use RuntimeException;
use stdClass;

class ProviderFactoryTest extends TestCase
{
    public function testNoneSignalsBuildNoProviderOrExporterGraph(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['exporter'] = 'none';
        $configuration['traces']['exporter'] = 'none';
        $configuration['logs']['exporter'] = 'none';
        $resolved = false;

        $providers = (new ProviderFactory(m::mock(Container::class)))->create(
            (new ConfigurationNormalizer)->normalize($configuration),
            ProcessIdentity::eventWorker(0),
            function () use (&$resolved): ExporterFactory {
                $resolved = true;

                return new RecordingExporterFactory;
            },
        );

        $this->assertNull($providers->metrics);
        $this->assertNull($providers->traces);
        $this->assertNull($providers->logs);
        $this->assertFalse($resolved);
    }

    public function testBuildsProvidersThatExportAllThreeSignals(): void
    {
        $exporters = new RecordingExporterFactory;
        $providers = (new ProviderFactory(m::mock(Container::class)))->create(
            (new ConfigurationNormalizer)->normalize($this->configuration()),
            ProcessIdentity::eventWorker(2),
            static fn (): ExporterFactory => $exporters,
        );

        $counter = $providers->metrics?->getMeter('test')->createCounter('test.counter');
        $counter?->add(1);
        $span = $providers->traces?->getTracer('test')->spanBuilder('test.span')->startSpan();
        $span?->end();
        $providers->logs?->getLogger('test')->logRecordBuilder()->setBody('test log')->emit();

        $this->assertTrue($providers->metrics?->forceFlush());
        $this->assertTrue($providers->traces?->forceFlush());
        $this->assertTrue($providers->logs?->forceFlush());
        $this->assertCount(1, $exporters->metricExporter->collect());
        $this->assertCount(1, $exporters->spanExporter->getSpans());
        $this->assertCount(1, $exporters->logExporter->getStorage());

        $this->assertTrue($providers->traces?->shutdown());
        $this->assertTrue($providers->logs?->shutdown());
        $this->assertTrue($providers->metrics?->shutdown());
    }

    public function testResourceAttributesApplyExplicitPrecedenceAndUniqueProcessIdentity(): void
    {
        $configuration = $this->configuration();
        $configuration['service'] = [
            'name' => 'application-default',
            'version' => '1.2.3',
            'environment' => 'testing',
            'instance_id' => 'configured-base',
        ];
        $configuration['resource_attributes'] = [
            ServiceAttributes::SERVICE_NAME => 'explicit-service',
            ServiceIncubatingAttributes::SERVICE_INSTANCE_ID => 'explicit-base',
            'deployment.region' => 'eu-west',
            'hypervel.worker.type' => 'spoofed',
            'hypervel.worker.id' => 999,
        ];
        $configuration['metrics']['exporter'] = 'none';
        $configuration['logs']['exporter'] = 'none';
        $exporters = new RecordingExporterFactory;
        $providers = (new ProviderFactory(m::mock(Container::class)))->create(
            (new ConfigurationNormalizer)->normalize($configuration),
            ProcessIdentity::taskWorker(5),
            static fn (): ExporterFactory => $exporters,
        );

        $providers->traces?->getTracer('test')->spanBuilder('resource')->startSpan()->end();
        $providers->traces?->forceFlush();
        $attributes = $exporters->spanExporter->getSpans()[0]->getResource()->getAttributes()->toArray();

        $this->assertSame('explicit-service', $attributes[ServiceAttributes::SERVICE_NAME]);
        $this->assertSame('1.2.3', $attributes[ServiceAttributes::SERVICE_VERSION]);
        $this->assertSame('testing', $attributes[DeploymentIncubatingAttributes::DEPLOYMENT_ENVIRONMENT_NAME]);
        $this->assertSame('eu-west', $attributes['deployment.region']);
        $this->assertSame('task', $attributes['hypervel.worker.type']);
        $this->assertSame(5, $attributes['hypervel.worker.id']);
        $this->assertSame(
            'explicit-base:task:5:' . getmypid(),
            $attributes[ServiceIncubatingAttributes::SERVICE_INSTANCE_ID],
        );
        $schemaUrl = $exporters->spanExporter->getSpans()[0]->getResource()->getSchemaUrl();

        $this->assertNotNull($schemaUrl);
        $this->assertSame(ResourceInfoFactory::defaultResource()->getSchemaUrl(), $schemaUrl);

        $providers->traces?->shutdown();
    }

    public function testCompleteProviderOverrideSkipsThePackageExporterGraph(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['provider'] = 'custom.metrics';
        $configuration['traces']['exporter'] = 'none';
        $configuration['logs']['exporter'] = 'none';
        $provider = m::mock(MeterProviderInterface::class);
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('custom.metrics')->andReturn($provider);
        $resolved = false;

        $providers = (new ProviderFactory($container))->create(
            (new ConfigurationNormalizer)->normalize($configuration),
            ProcessIdentity::cli(),
            function () use (&$resolved): ExporterFactory {
                $resolved = true;

                return new RecordingExporterFactory;
            },
        );

        $this->assertSame($provider, $providers->metrics);
        $this->assertFalse($resolved);
    }

    public function testProviderOverridesMustImplementTheSdkLifecycleContract(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['provider'] = 'invalid.metrics';
        $configuration['traces']['exporter'] = 'none';
        $configuration['logs']['exporter'] = 'none';
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('invalid.metrics')->andReturn(new stdClass);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement [OpenTelemetry\SDK\Metrics\MeterProviderInterface]');

        (new ProviderFactory($container))->create(
            (new ConfigurationNormalizer)->normalize($configuration),
            ProcessIdentity::cli(),
            static fn (): ExporterFactory => new RecordingExporterFactory,
        );
    }

    public function testResolvesCustomSamplerAndTypedMetricViewsThroughTheContainer(): void
    {
        $configuration = $this->configuration();
        $configuration['traces']['sampler'] = 'custom.sampler';
        $configuration['metrics']['views'] = ['custom.view'];
        $sampler = m::mock(SamplerInterface::class);
        $view = m::mock(MetricView::class);
        $view->shouldReceive('criteria')->once()->andReturn(new InstrumentNameCriteria('original.metric'));
        $view->shouldReceive('template')->once()->andReturn(ViewTemplate::create()->withName('renamed.metric'));
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('custom.view')->andReturn($view);
        $container->shouldReceive('make')->once()->with('custom.sampler')->andReturn($sampler);
        $exporters = new RecordingExporterFactory;

        $providers = (new ProviderFactory($container))->create(
            (new ConfigurationNormalizer)->normalize($configuration),
            ProcessIdentity::cli(),
            static fn (): ExporterFactory => $exporters,
        );

        $this->assertInstanceOf(TracerProvider::class, $providers->traces);
        $this->assertSame($sampler, $providers->traces->getSampler());
        $providers->metrics?->getMeter('test')->createCounter('original.metric')->add(1);
        $providers->metrics?->forceFlush();

        $this->assertSame(['renamed.metric'], array_column($exporters->metricExporter->collect(), 'name'));

        $providers->traces?->shutdown();
        $providers->logs?->shutdown();
        $providers->metrics?->shutdown();
    }

    public function testInternalMetricsUseTheActiveMetricsProvider(): void
    {
        $configuration = $this->configuration();
        $configuration['internal_metrics'] = true;
        $exporters = new RecordingExporterFactory;
        $providers = (new ProviderFactory(m::mock(Container::class)))->create(
            (new ConfigurationNormalizer)->normalize($configuration),
            ProcessIdentity::cli(),
            static fn (): ExporterFactory => $exporters,
        );

        $providers->traces?->getTracer('test')->spanBuilder('internal-metrics')->startSpan()->end();
        $providers->logs?->getLogger('test')->logRecordBuilder()->setBody('internal metrics')->emit();
        $providers->traces?->forceFlush();
        $providers->logs?->forceFlush();
        $providers->metrics?->forceFlush();
        $metricNames = array_column($exporters->metricExporter->collect(), 'name');

        $this->assertContains('otel.sdk.processor.span.processed', $metricNames);
        $this->assertContains('otel.sdk.processor.log.processed', $metricNames);
        $this->assertContains('otel.sdk.span.started', $metricNames);

        $providers->traces?->shutdown();
        $providers->logs?->shutdown();
        $providers->metrics?->shutdown();
    }

    public function testResolvesMetricViewsBeforeCreatingTheExporter(): void
    {
        $configuration = $this->configuration();
        $configuration['metrics']['views'] = ['failing.view'];
        $expected = new RuntimeException('Unable to build metric view.');
        $view = m::mock(MetricView::class);
        $view->shouldReceive('criteria')->once()->andThrow($expected);
        $container = m::mock(Container::class);
        $container->shouldReceive('make')->once()->with('failing.view')->andReturn($view);
        $exporterCreated = false;

        try {
            (new ProviderFactory($container))->create(
                (new ConfigurationNormalizer)->normalize($configuration),
                ProcessIdentity::cli(),
                function () use (&$exporterCreated): ExporterFactory {
                    $exporterCreated = true;

                    return new RecordingExporterFactory;
                },
            );

            $this->fail('A failing metric view was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expected, $exception);
        }

        $this->assertFalse($exporterCreated);
    }

    /**
     * Return a complete configuration fixture.
     *
     * @return array<string, mixed>
     */
    private function configuration(): array
    {
        return [
            'enabled' => true,
            'internal_metrics' => false,
            'service' => [
                'name' => 'test-service',
                'version' => null,
                'environment' => null,
                'instance_id' => 'instance',
            ],
            'resource_attributes' => [],
            'propagators' => ['tracecontext'],
            'response_propagators' => ['none'],
            'metrics' => [
                'provider' => null,
                'exporter' => 'fixture',
                'export_interval' => 60000,
                'temporality' => 'cumulative',
                'exemplar_filter' => 'trace_based',
                'views' => [],
            ],
            'traces' => [
                'provider' => null,
                'exporter' => 'fixture',
                'sampler' => 'parentbased_always_on',
                'sampler_arg' => 1.0,
                'schedule_delay' => 5000,
                'max_queue_size' => 2048,
                'max_export_batch_size' => 512,
                'processors' => [],
            ],
            'logs' => [
                'provider' => null,
                'exporter' => 'fixture',
                'schedule_delay' => 1000,
                'max_queue_size' => 2048,
                'max_export_batch_size' => 512,
                'processors' => [],
            ],
            'log_context' => [
                'enabled' => false,
                'trace_id_key' => 'trace_id',
                'span_id_key' => 'span_id',
            ],
            'server_processes' => ['except' => []],
            'exporters' => [
                'fixture' => ['driver' => 'fixture'],
            ],
        ];
    }
}

class RecordingExporterFactory implements ExporterFactory
{
    public InMemorySpanExporter $spanExporter;

    public InMemoryMetricExporter $metricExporter;

    public InMemoryLogExporter $logExporter;

    /**
     * Create recording exporters for every signal.
     */
    public function __construct()
    {
        $this->spanExporter = new InMemorySpanExporter(new ArrayObject);
        $this->metricExporter = new InMemoryMetricExporter(new ArrayObject);
        $this->logExporter = new InMemoryLogExporter(new ArrayObject);
    }

    /**
     * Return the recording span exporter.
     */
    public function spanExporter(array $config): SpanExporterInterface
    {
        return $this->spanExporter;
    }

    /**
     * Return the recording metric exporter.
     */
    public function metricExporter(array $config): MetricExporterInterface
    {
        return $this->metricExporter;
    }

    /**
     * Return the recording log exporter.
     */
    public function logExporter(array $config): LogRecordExporterInterface
    {
        return $this->logExporter;
    }
}
