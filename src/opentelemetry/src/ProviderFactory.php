<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\OpenTelemetry\Contracts\ExporterFactory;
use Hypervel\OpenTelemetry\Contracts\MetricView;
use Hypervel\OpenTelemetry\Support\ProcessIdentity;
use Hypervel\OpenTelemetry\Support\ProviderSet;
use InvalidArgumentException;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Metrics\MeterProviderInterface as ApiMeterProviderInterface;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Common\InstrumentationScope\Configurator;
use OpenTelemetry\SDK\Logs\LoggerProviderBuilder;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\AllExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\NoneExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilter\WithSampledTraceExemplarFilter;
use OpenTelemetry\SDK\Metrics\Exemplar\ExemplarFilterInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\StalenessHandler\NoopStalenessHandlerFactory;
use OpenTelemetry\SDK\Metrics\View\CriteriaViewRegistry;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessorInterface;
use OpenTelemetry\SDK\Trace\TracerProviderBuilder;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\DeploymentIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\ServiceIncubatingAttributes;
use OpenTelemetry\SemConv\Version;
use RuntimeException;

class ProviderFactory
{
    /**
     * Create a provider factory.
     */
    public function __construct(protected Container $container)
    {
    }

    /**
     * Build the enabled worker-local signal providers.
     *
     * @param array<string, mixed> $configuration
     * @param Closure(string): ExporterFactory $resolveExporterFactory
     */
    public function create(
        array $configuration,
        ProcessIdentity $identity,
        Closure $resolveExporterFactory,
    ): ProviderSet {
        $resource = null;
        $resolveResource = function () use (&$resource, $configuration, $identity): ResourceInfo {
            return $resource ??= $this->resource($configuration, $identity);
        };

        $metrics = $this->metrics($configuration, $resolveResource, $resolveExporterFactory);
        $internalMeterProvider = $configuration['internal_metrics'] ? $metrics : null;
        $traces = $this->traces(
            $configuration,
            $resolveResource,
            $resolveExporterFactory,
            $internalMeterProvider,
        );
        $logs = $this->logs(
            $configuration,
            $resolveResource,
            $resolveExporterFactory,
            $internalMeterProvider,
        );

        return new ProviderSet($metrics, $traces, $logs);
    }

    /**
     * Build or resolve the metrics provider.
     *
     * @param array<string, mixed> $configuration
     * @param Closure(): ResourceInfo $resolveResource
     * @param Closure(string): ExporterFactory $resolveExporterFactory
     */
    protected function metrics(
        array $configuration,
        Closure $resolveResource,
        Closure $resolveExporterFactory,
    ): ?MeterProviderInterface {
        $options = $configuration['metrics'];

        if ($options['exporter'] === 'none') {
            return null;
        }

        if ($options['provider'] !== null) {
            return $this->resolve($options['provider'], MeterProviderInterface::class, 'metrics provider');
        }

        $views = new CriteriaViewRegistry;

        foreach ($options['views'] as $viewClass) {
            $view = $this->resolve($viewClass, MetricView::class, 'metric view');
            $views->register($view->criteria(), $view->template());
        }

        $resource = $resolveResource();
        $exemplarFilter = $this->exemplarFilter($options['exemplar_filter']);
        $exporterConfiguration = $this->exporterConfiguration($configuration, $options['exporter']);
        $exporterConfiguration['temporality'] = $options['temporality'];
        $exporter = $resolveExporterFactory($exporterConfiguration['driver'])
            ->metricExporter($exporterConfiguration);
        $reader = new ExportingReader($exporter);
        $attributesFactory = Attributes::factory();

        return new MeterProvider(
            null,
            $resource,
            Clock::getDefault(),
            $attributesFactory,
            new InstrumentationScopeFactory($attributesFactory),
            [$reader],
            $views,
            $exemplarFilter,
            new NoopStalenessHandlerFactory,
            configurator: Configurator::meter(),
        );
    }

    /**
     * Build or resolve the trace provider.
     *
     * @param array<string, mixed> $configuration
     * @param Closure(): ResourceInfo $resolveResource
     * @param Closure(string): ExporterFactory $resolveExporterFactory
     */
    protected function traces(
        array $configuration,
        Closure $resolveResource,
        Closure $resolveExporterFactory,
        ?ApiMeterProviderInterface $internalMeterProvider,
    ): ?TracerProviderInterface {
        $options = $configuration['traces'];

        if ($options['exporter'] === 'none') {
            return null;
        }

        if ($options['provider'] !== null) {
            return $this->resolve($options['provider'], TracerProviderInterface::class, 'trace provider');
        }

        $builder = (new TracerProviderBuilder)
            ->setResource($resolveResource())
            ->setSampler($this->sampler($options['sampler'], $options['sampler_arg']));

        if ($internalMeterProvider !== null) {
            $builder->setMeterProvider($internalMeterProvider);
        }

        foreach ($options['processors'] as $processorClass) {
            $builder->addSpanProcessor(
                $this->resolve($processorClass, SpanProcessorInterface::class, 'span processor'),
            );
        }

        $exporterConfiguration = $this->exporterConfiguration($configuration, $options['exporter']);
        $builder->addSpanProcessor(new BatchSpanProcessor(
            $resolveExporterFactory($exporterConfiguration['driver'])->spanExporter($exporterConfiguration),
            Clock::getDefault(),
            $options['max_queue_size'],
            $options['schedule_delay'],
            maxExportBatchSize: $options['max_export_batch_size'],
            autoFlush: false,
            meterProvider: $internalMeterProvider,
        ));

        return $builder->build();
    }

    /**
     * Build or resolve the log provider.
     *
     * @param array<string, mixed> $configuration
     * @param Closure(): ResourceInfo $resolveResource
     * @param Closure(string): ExporterFactory $resolveExporterFactory
     */
    protected function logs(
        array $configuration,
        Closure $resolveResource,
        Closure $resolveExporterFactory,
        ?ApiMeterProviderInterface $internalMeterProvider,
    ): ?LoggerProviderInterface {
        $options = $configuration['logs'];

        if ($options['exporter'] === 'none') {
            return null;
        }

        if ($options['provider'] !== null) {
            return $this->resolve($options['provider'], LoggerProviderInterface::class, 'log provider');
        }

        $builder = (new LoggerProviderBuilder)->setResource($resolveResource());

        if ($internalMeterProvider !== null) {
            $builder->setMeterProvider($internalMeterProvider);
        }

        foreach ($options['processors'] as $processorClass) {
            $builder->addLogRecordProcessor(
                $this->resolve($processorClass, LogRecordProcessorInterface::class, 'log-record processor'),
            );
        }

        $exporterConfiguration = $this->exporterConfiguration($configuration, $options['exporter']);
        $builder->addLogRecordProcessor(new BatchLogRecordProcessor(
            $resolveExporterFactory($exporterConfiguration['driver'])->logExporter($exporterConfiguration),
            Clock::getDefault(),
            $options['max_queue_size'],
            $options['schedule_delay'],
            maxExportBatchSize: $options['max_export_batch_size'],
            autoFlush: false,
            meterProvider: $internalMeterProvider,
        ));

        return $builder->build();
    }

    /**
     * Build the standard resource for one producing process.
     *
     * @param array<string, mixed> $configuration
     */
    protected function resource(array $configuration, ProcessIdentity $identity): ResourceInfo
    {
        $service = $configuration['service'];
        $explicit = $configuration['resource_attributes'];
        $baseInstanceId = $explicit[ServiceIncubatingAttributes::SERVICE_INSTANCE_ID]
            ?? $service['instance_id']
            ?? gethostname();

        if (! is_string($baseInstanceId) || $baseInstanceId === '') {
            throw new RuntimeException('Unable to resolve a base OpenTelemetry service instance ID.');
        }

        $processId = getmypid();

        if ($processId === false) {
            throw new RuntimeException('Unable to resolve the current process ID for OpenTelemetry resources.');
        }

        $attributes = array_filter([
            ServiceAttributes::SERVICE_NAME => $service['name'],
            ServiceAttributes::SERVICE_VERSION => $service['version'],
            DeploymentIncubatingAttributes::DEPLOYMENT_ENVIRONMENT_NAME => $service['environment'],
        ], static fn (mixed $value): bool => $value !== null);
        $attributes = array_replace($attributes, $explicit, $identity->resourceAttributes());
        $attributes[ServiceIncubatingAttributes::SERVICE_INSTANCE_ID] = sprintf(
            '%s:%s:%s:%d',
            $baseInstanceId,
            $identity->type,
            $identity->stableId(),
            $processId,
        );

        return ResourceInfoFactory::defaultResource()->merge(ResourceInfo::create(
            Attributes::create($attributes),
            Version::VERSION_1_38_0->url(),
        ));
    }

    /**
     * Resolve one configured exporter record.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    protected function exporterConfiguration(array $configuration, string $name): array
    {
        return $configuration['exporters'][$name];
    }

    /**
     * Resolve a standard or application sampler.
     */
    protected function sampler(string $sampler, mixed $argument): SamplerInterface
    {
        $normalized = strtolower($sampler);

        return match ($normalized) {
            'always_on' => new AlwaysOnSampler,
            'always_off' => new AlwaysOffSampler,
            'parentbased_always_on' => new ParentBased(new AlwaysOnSampler),
            'parentbased_always_off' => new ParentBased(new AlwaysOffSampler),
            'traceidratio' => new TraceIdRatioBasedSampler((float) $argument),
            'parentbased_traceidratio' => new ParentBased(new TraceIdRatioBasedSampler((float) $argument)),
            default => $this->resolve($sampler, SamplerInterface::class, 'sampler'),
        };
    }

    /**
     * Resolve the configured exemplar filter.
     */
    protected function exemplarFilter(string $filter): ExemplarFilterInterface
    {
        return match (strtolower($filter)) {
            'trace_based' => new WithSampledTraceExemplarFilter,
            'always_on' => new AllExemplarFilter,
            'always_off' => new NoneExemplarFilter,
            default => throw new InvalidArgumentException("Unsupported OpenTelemetry exemplar filter [{$filter}]."),
        };
    }

    /**
     * Resolve and validate a configured service.
     *
     * @template T of object
     * @param class-string $class
     * @param class-string<T> $contract
     * @return T
     */
    protected function resolve(string $class, string $contract, string $description): object
    {
        $instance = $this->container->make($class);

        if (! $instance instanceof $contract) {
            throw new InvalidArgumentException(
                "OpenTelemetry {$description} [{$class}] must implement [{$contract}].",
            );
        }

        return $instance;
    }
}
