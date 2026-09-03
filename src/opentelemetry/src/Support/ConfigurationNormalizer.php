<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Support;

use Hypervel\Grpc\GrpcOperationRunner;
use Hypervel\OpenTelemetry\Instrumentation\CacheInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\ConsoleInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\DatabaseInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\EventInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\ExceptionInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\GrpcInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\HttpClientInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\HttpServerInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\PoolInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\QueueInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\RedisInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\RuntimeInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\SchedulerInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\ScoutInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\ViewInstrumentation;
use Hypervel\OpenTelemetry\Instrumentation\WebSocketInstrumentation;
use Hypervel\Scout\EngineOperationRunner;
use InvalidArgumentException;

class ConfigurationNormalizer
{
    public const array DEFAULT_HTTP_METHODS = [
        'CONNECT',
        'DELETE',
        'GET',
        'HEAD',
        'OPTIONS',
        'PATCH',
        'POST',
        'PUT',
        'QUERY',
        'TRACE',
    ];

    /**
     * Normalize configuration consumed by a producing process.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    public function normalize(array $configuration): array
    {
        $configuration['enabled'] = $this->boolean($configuration, 'enabled', 'opentelemetry');
        $configuration['internal_metrics'] = $this->boolean($configuration, 'internal_metrics', 'opentelemetry');
        $configuration['service'] = $this->service($configuration['service'] ?? null);
        $configuration['resource_attributes'] = $this->associativeArray(
            $configuration['resource_attributes'] ?? null,
            'opentelemetry.resource_attributes',
        );
        $configuration['propagators'] = $this->stringList(
            $configuration['propagators'] ?? null,
            'opentelemetry.propagators',
        );
        $configuration['response_propagators'] = $this->stringList(
            $configuration['response_propagators'] ?? null,
            'opentelemetry.response_propagators',
        );
        $configuration['metrics'] = $this->metrics($configuration['metrics'] ?? null);
        $configuration['traces'] = $this->traces($configuration['traces'] ?? null);
        $configuration['logs'] = $this->logs($configuration['logs'] ?? null);
        $configuration['exporters'] = $this->exporters($configuration['exporters'] ?? null);
        $configuration['log_context'] = $this->record(
            $configuration['log_context'] ?? null,
            ['enabled' => false, 'trace_id_key' => 'trace_id', 'span_id_key' => 'span_id'],
            'opentelemetry.log_context',
        );
        $configuration['server_processes'] = $this->record(
            $configuration['server_processes'] ?? null,
            ['except' => []],
            'opentelemetry.server_processes',
        );
        $configuration['instrumentation'] = $this->instrumentation(
            $configuration['instrumentation'] ?? [],
            $configuration['traces']['exporter'] !== 'none',
            $configuration['logs']['exporter'] !== 'none',
            $configuration['metrics']['exporter'] !== 'none',
        );

        $configuration['log_context']['enabled'] = $this->boolean(
            $configuration['log_context'],
            'enabled',
            'opentelemetry.log_context',
        );
        $configuration['log_context']['trace_id_key'] = $this->string(
            $configuration['log_context'],
            'trace_id_key',
            'opentelemetry.log_context',
        );
        $configuration['log_context']['span_id_key'] = $this->string(
            $configuration['log_context'],
            'span_id_key',
            'opentelemetry.log_context',
        );
        $configuration['server_processes']['except'] = $this->stringList(
            $configuration['server_processes']['except'],
            'opentelemetry.server_processes.except',
        );

        foreach (['metrics', 'traces', 'logs'] as $signal) {
            $exporter = $configuration[$signal]['exporter'];

            if ($exporter !== 'none'
                && $configuration[$signal]['provider'] === null
                && ! array_key_exists($exporter, $configuration['exporters'])
            ) {
                throw new InvalidArgumentException(
                    "OpenTelemetry exporter [{$exporter}] configured for [{$signal}] is not defined.",
                );
            }
        }

        return $configuration;
    }

    /**
     * Normalize class-keyed instrumentation settings.
     *
     * @return array<string, array<string, mixed>|false>
     */
    protected function instrumentation(
        mixed $configuration,
        bool $tracesActive,
        bool $logsActive,
        bool $metricsActive,
    ): array {
        $configuration = $this->associativeArray($configuration, 'opentelemetry.instrumentation');
        $defaults = $this->instrumentationDefaults();
        $normalized = [];

        foreach ($configuration as $class => $options) {
            if (! is_string($class) || $class === '') {
                throw new InvalidArgumentException('OpenTelemetry instrumentation names must be non-empty class strings.');
            }

            if ($options === false) {
                $normalized[$class] = false;

                continue;
            }

            if ($options === true) {
                $options = $defaults[$class] ?? [];
            } elseif (is_array($options)) {
                $defaultOptions = $defaults[$class] ?? [];

                // Metric maps contain independent switches, so unnamed built-in
                // metrics retain their shipped defaults after an outer replace.
                if (is_array($options['metrics'] ?? null) && is_array($defaultOptions['metrics'] ?? null)) {
                    $options['metrics'] = array_replace($defaultOptions['metrics'], $options['metrics']);
                }

                $options = array_replace($defaultOptions, $options);
            } else {
                throw $this->invalidType(
                    "opentelemetry.instrumentation.{$class}",
                    'a boolean or an array',
                    $options,
                );
            }

            $options = $this->instrumentationRecord($class, $options);

            if (! $tracesActive && array_key_exists('traces', $options)) {
                $options['traces'] = false;
            }

            if (! $logsActive && array_key_exists('logs', $options)) {
                $options['logs'] = false;
            }

            if (! $metricsActive && array_key_exists('metrics', $options)) {
                $options['metrics'] = false;
            }

            if ($class === QueueInstrumentation::class
                && ($options['metrics'] === true
                    || (is_array($options['metrics']) && ($options['metrics']['hypervel.queue.jobs'] ?? false)))
                && $options['depth_queues'] === []
            ) {
                throw new InvalidArgumentException(
                    "Configuration value [opentelemetry.instrumentation.{$class}.depth_queues] must contain at least one queue when [hypervel.queue.jobs] is enabled.",
                );
            }

            if (($class === GrpcInstrumentation::class && ! class_exists(GrpcOperationRunner::class))
                || ($class === ScoutInstrumentation::class && ! class_exists(EngineOperationRunner::class))
                || ! $this->instrumentationHasOutput(
                    $class,
                    $options,
                    array_key_exists($class, $defaults),
                    $tracesActive,
                    $logsActive,
                    $metricsActive,
                )
            ) {
                $normalized[$class] = false;

                continue;
            }

            $normalized[$class] = $options;
        }

        return $normalized;
    }

    /**
     * Normalize one instrumentation record.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function instrumentationRecord(string $class, array $options): array
    {
        $path = "opentelemetry.instrumentation.{$class}";

        foreach (['traces', 'logs', 'propagation', 'manual', 'user_context', 'url_query', 'query_text', 'key', 'message', 'stack_trace', 'server_metric_address'] as $key) {
            if (array_key_exists($key, $options)) {
                $options[$key] = $this->boolean($options, $key, $path);
            }
        }

        if (array_key_exists('metrics', $options)) {
            $options['metrics'] = $this->instrumentationMetrics($options['metrics'], "{$path}.metrics");
        }

        foreach (['known_methods', 'except_paths', 'except_methods', 'sensitive_query_parameters', 'sensitive_headers', 'request_headers', 'response_headers', 'commands', 'except', 'events'] as $key) {
            if (array_key_exists($key, $options)) {
                $options[$key] = $this->stringList($options[$key], "{$path}.{$key}");
            }
        }

        if ($class === EventInstrumentation::class) {
            foreach ($options['events'] as $event) {
                if (str_contains($event, '*')) {
                    throw new InvalidArgumentException(
                        "Configuration value [{$path}.events] must contain exact event names without wildcards.",
                    );
                }
            }
        }

        foreach (['query_text_max_length', 'key_max_length'] as $key) {
            if (array_key_exists($key, $options)) {
                $options[$key] = $this->nullablePositiveInteger($options[$key], "{$path}.{$key}");
            }
        }

        if (array_key_exists('depth_queues', $options)) {
            $options['depth_queues'] = $this->queueDepthTargets($options['depth_queues'], "{$path}.depth_queues");
        }

        return $options;
    }

    /**
     * Normalize a boolean or class-defined metric map.
     *
     * @return array<string, bool>|bool
     */
    protected function instrumentationMetrics(mixed $configuration, string $path): array|bool
    {
        if (is_bool($configuration)) {
            return $configuration;
        }

        $configuration = $this->associativeArray($configuration, $path);

        foreach ($configuration as $name => $enabled) {
            if (! is_string($name) || $name === '' || ! is_bool($enabled)) {
                throw $this->invalidType($path, 'a boolean or a map of metric names to booleans', $configuration);
            }
        }

        return $configuration;
    }

    /**
     * Normalize configured queue-depth targets.
     *
     * @return array<string, list<string>>
     */
    protected function queueDepthTargets(mixed $configuration, string $path): array
    {
        $configuration = $this->associativeArray($configuration, $path);

        foreach ($configuration as $connection => $queues) {
            if (! is_string($connection) || $connection === '') {
                throw new InvalidArgumentException("Configuration value [{$path}] must use non-empty connection names.");
            }

            $configuration[$connection] = $this->stringList($queues, "{$path}.{$connection}");
        }

        return $configuration;
    }

    /**
     * Normalize a nullable positive integer.
     */
    protected function nullablePositiveInteger(mixed $value, string $path): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw $this->invalidType($path, 'a positive integer or null', $value);
        }

        $this->ensurePositiveInteger($value, $path);

        return $value;
    }

    /**
     * Determine whether an instrumentation has any enabled output.
     *
     * @param array<string, mixed> $options
     */
    protected function instrumentationHasOutput(
        string $class,
        array $options,
        bool $builtIn,
        bool $tracesActive,
        bool $logsActive,
        bool $metricsActive,
    ): bool {
        if (! $tracesActive && ! $logsActive && ! $metricsActive) {
            return false;
        }

        $metrics = $options['metrics'] ?? false;
        $hasMetric = $metrics === true
            || (is_array($metrics) && in_array(true, $metrics, true));
        $hasOutput = ($options['traces'] ?? false)
            || ($options['logs'] ?? false)
            || ($options['propagation'] ?? false)
            || $hasMetric;
        $declaresOutput = array_key_exists('traces', $options)
            || array_key_exists('logs', $options)
            || array_key_exists('metrics', $options)
            || array_key_exists('propagation', $options);

        return match ($class) {
            EventInstrumentation::class => $tracesActive && $options['events'] !== [],
            HttpServerInstrumentation::class => ($options['traces'] ?? false)
                || $hasMetric
                || ($logsActive && ($options['user_context'] ?? false)),
            QueueInstrumentation::class => $options['traces'] || $options['propagation'] || $hasMetric,
            ExceptionInstrumentation::class => $options['logs'] || $hasMetric,
            PoolInstrumentation::class, RuntimeInstrumentation::class => $hasMetric,
            default => $hasOutput || (! $builtIn && ! $declaresOutput),
        };
    }

    /**
     * Return fixed defaults for replaceable built-in instrumentation records.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function instrumentationDefaults(): array
    {
        return [
            HttpServerInstrumentation::class => [
                'traces' => true,
                'known_methods' => self::DEFAULT_HTTP_METHODS,
                'except_paths' => [],
                'except_methods' => [],
                'user_context' => false,
                'url_query' => false,
                'sensitive_query_parameters' => [],
                'sensitive_headers' => [],
                'request_headers' => [],
                'response_headers' => [],
                'metrics' => [
                    'http.server.request.duration' => true,
                    'http.server.active_requests' => false,
                ],
            ],
            HttpClientInstrumentation::class => [
                'traces' => true,
                'known_methods' => self::DEFAULT_HTTP_METHODS,
                'manual' => false,
                'url_query' => false,
                'sensitive_query_parameters' => [],
                'sensitive_headers' => [],
                'request_headers' => [],
                'response_headers' => [],
                'metrics' => ['http.client.request.duration' => true],
            ],
            DatabaseInstrumentation::class => [
                'traces' => true,
                'query_text' => true,
                'query_text_max_length' => 500,
                'metrics' => ['db.client.operation.duration' => true],
            ],
            RedisInstrumentation::class => [
                'traces' => true,
                'query_text' => false,
                'query_text_max_length' => 500,
                'metrics' => ['db.client.operation.duration' => true],
            ],
            PoolInstrumentation::class => [
                'metrics' => [
                    'db.client.connection.count' => true,
                    'db.client.connection.max' => true,
                    'db.client.connection.pending_requests' => true,
                    'hypervel.object_pool.objects' => true,
                    'hypervel.object_pool.max' => true,
                    'hypervel.object_pool.pending_requests' => true,
                ],
            ],
            CacheInstrumentation::class => [
                'traces' => true,
                'key' => false,
                'key_max_length' => 500,
                'metrics' => ['hypervel.cache.operations' => true],
            ],
            QueueInstrumentation::class => [
                'traces' => true,
                'propagation' => true,
                'depth_queues' => [],
                'metrics' => [
                    'messaging.client.sent.messages' => true,
                    'messaging.client.operation.duration' => true,
                    'messaging.client.consumed.messages' => true,
                    'messaging.process.duration' => true,
                    'hypervel.queue.jobs' => false,
                ],
            ],
            SchedulerInstrumentation::class => [
                'traces' => true,
                'metrics' => [
                    'hypervel.scheduler.task.duration' => true,
                    'hypervel.scheduler.task.executions' => true,
                ],
            ],
            ConsoleInstrumentation::class => [
                'traces' => true,
                'commands' => ['*'],
                'except' => [],
                'metrics' => ['hypervel.console.command.duration' => true],
            ],
            EventInstrumentation::class => ['events' => []],
            ViewInstrumentation::class => [
                'traces' => true,
                'metrics' => ['hypervel.view.render.duration' => true],
            ],
            ScoutInstrumentation::class => [
                'traces' => true,
                'metrics' => ['db.client.operation.duration' => true],
            ],
            GrpcInstrumentation::class => [
                'traces' => true,
                'server_metric_address' => false,
                'metrics' => [
                    'rpc.client.call.duration' => true,
                    'rpc.server.call.duration' => true,
                ],
            ],
            WebSocketInstrumentation::class => [
                'traces' => true,
                'metrics' => [
                    'hypervel.websocket.message.duration' => true,
                    'hypervel.websocket.messages' => true,
                    'hypervel.websocket.active_connections' => true,
                ],
            ],
            ExceptionInstrumentation::class => [
                'logs' => true,
                'message' => true,
                'stack_trace' => true,
                'metrics' => ['hypervel.exceptions' => true],
            ],
            RuntimeInstrumentation::class => [
                'metrics' => [
                    'php.memory.usage' => true,
                    'php.memory.peak_usage' => true,
                    'php.memory.limit' => true,
                    'php.gc.runs' => true,
                    'php.gc.collected' => true,
                    'php.gc.threshold' => true,
                    'php.gc.roots' => true,
                    'php.gc.collector_time' => true,
                    'php.gc.destructor_time' => true,
                    'php.gc.free_time' => true,
                    'process.cpu.time' => true,
                    'process.context_switches' => true,
                    'php.opcache.memory_used' => true,
                    'php.opcache.memory_free' => true,
                    'php.opcache.memory_wasted' => true,
                    'php.opcache.hit_rate' => true,
                    'php.opcache.hits' => true,
                    'php.opcache.misses' => true,
                    'php.opcache.cached_scripts' => true,
                    'php.opcache.interned_strings.memory_used' => true,
                    'php.opcache.interned_strings.memory_free' => true,
                    'php.opcache.interned_strings.count' => true,
                    'hypervel.server.connections' => true,
                    'hypervel.server.requests' => true,
                    'hypervel.server.tasks.active' => true,
                    'hypervel.server.task_queue.size' => true,
                    'hypervel.worker.requests' => true,
                    'hypervel.worker.coroutines' => true,
                ],
            ],
        ];
    }

    /**
     * Normalize service resource defaults.
     *
     * @return array{name: string, version: ?string, environment: ?string, instance_id: ?string}
     */
    protected function service(mixed $configuration): array
    {
        $configuration = $this->record($configuration, [
            'name' => 'hypervel',
            'version' => null,
            'environment' => null,
            'instance_id' => null,
        ], 'opentelemetry.service');

        return [
            'name' => $this->string($configuration, 'name', 'opentelemetry.service'),
            'version' => $this->nullableString($configuration, 'version', 'opentelemetry.service'),
            'environment' => $this->nullableString($configuration, 'environment', 'opentelemetry.service'),
            'instance_id' => $this->nullableString($configuration, 'instance_id', 'opentelemetry.service'),
        ];
    }

    /**
     * Normalize metrics signal configuration.
     *
     * @return array<string, mixed>
     */
    protected function metrics(mixed $configuration): array
    {
        $configuration = $this->record($configuration, [
            'provider' => null,
            'exporter' => ['otlp'],
            'export_interval' => 60000,
            'temporality' => 'cumulative',
            'exemplar_filter' => 'trace_based',
            'views' => [],
        ], 'opentelemetry.metrics');
        $configuration['provider'] = $this->nullableClass($configuration, 'provider', 'opentelemetry.metrics');
        $configuration['exporter'] = $this->exporterName($configuration['exporter'], 'opentelemetry.metrics.exporter');
        $configuration['export_interval'] = $this->integer(
            $configuration,
            'export_interval',
            'opentelemetry.metrics',
        );
        $configuration['temporality'] = $this->string($configuration, 'temporality', 'opentelemetry.metrics');
        $configuration['exemplar_filter'] = $this->string($configuration, 'exemplar_filter', 'opentelemetry.metrics');
        $configuration['views'] = $this->stringList($configuration['views'], 'opentelemetry.metrics.views');

        if ($configuration['exporter'] !== 'none') {
            $this->ensurePositiveInteger(
                $configuration['export_interval'],
                'opentelemetry.metrics.export_interval',
            );
        }

        if ($configuration['exporter'] !== 'none'
            && $configuration['provider'] === null
            && ! in_array(strtolower($configuration['exemplar_filter']), ['trace_based', 'always_on', 'always_off'], true)
        ) {
            throw new InvalidArgumentException(
                "Unsupported OpenTelemetry exemplar filter [{$configuration['exemplar_filter']}].",
            );
        }

        return $configuration;
    }

    /**
     * Normalize trace signal configuration.
     *
     * @return array<string, mixed>
     */
    protected function traces(mixed $configuration): array
    {
        $configuration = $this->record($configuration, [
            'provider' => null,
            'exporter' => ['otlp'],
            'sampler' => 'parentbased_always_on',
            'sampler_arg' => 1.0,
            'schedule_delay' => 5000,
            'max_queue_size' => 2048,
            'max_export_batch_size' => 512,
            'processors' => [],
        ], 'opentelemetry.traces');
        $configuration['provider'] = $this->nullableClass($configuration, 'provider', 'opentelemetry.traces');
        $configuration['exporter'] = $this->exporterName($configuration['exporter'], 'opentelemetry.traces.exporter');
        $configuration['sampler'] = $this->string($configuration, 'sampler', 'opentelemetry.traces');
        $samplerArgument = $configuration['sampler_arg'];

        if (! is_int($samplerArgument) && ! is_float($samplerArgument)) {
            throw $this->invalidType('opentelemetry.traces.sampler_arg', 'a number', $samplerArgument);
        }

        $configuration['sampler_arg'] = (float) $samplerArgument;
        $configuration['schedule_delay'] = $this->integer(
            $configuration,
            'schedule_delay',
            'opentelemetry.traces',
        );
        $configuration['max_queue_size'] = $this->integer(
            $configuration,
            'max_queue_size',
            'opentelemetry.traces',
        );
        $configuration['max_export_batch_size'] = $this->integer(
            $configuration,
            'max_export_batch_size',
            'opentelemetry.traces',
        );
        $configuration['processors'] = $this->stringList(
            $configuration['processors'],
            'opentelemetry.traces.processors',
        );

        if ($configuration['exporter'] === 'none') {
            return $configuration;
        }

        $this->ensurePositiveInteger(
            $configuration['schedule_delay'],
            'opentelemetry.traces.schedule_delay',
        );

        if ($configuration['provider'] !== null) {
            return $configuration;
        }

        $this->ensurePositiveInteger(
            $configuration['max_queue_size'],
            'opentelemetry.traces.max_queue_size',
        );
        $this->ensurePositiveInteger(
            $configuration['max_export_batch_size'],
            'opentelemetry.traces.max_export_batch_size',
        );

        if ($configuration['max_export_batch_size'] > $configuration['max_queue_size']) {
            throw new InvalidArgumentException(
                'Configuration value [opentelemetry.traces.max_export_batch_size] must not exceed [max_queue_size].',
            );
        }

        if (in_array(strtolower($configuration['sampler']), ['traceidratio', 'parentbased_traceidratio'], true)) {
            if ($configuration['sampler_arg'] < 0.0 || $configuration['sampler_arg'] > 1.0) {
                throw new InvalidArgumentException(
                    'Configuration value [opentelemetry.traces.sampler_arg] must be between 0.0 and 1.0.',
                );
            }
        }

        return $configuration;
    }

    /**
     * Normalize log signal configuration.
     *
     * @return array<string, mixed>
     */
    protected function logs(mixed $configuration): array
    {
        $configuration = $this->record($configuration, [
            'provider' => null,
            'exporter' => ['otlp'],
            'schedule_delay' => 1000,
            'max_queue_size' => 2048,
            'max_export_batch_size' => 512,
            'processors' => [],
        ], 'opentelemetry.logs');
        $configuration['provider'] = $this->nullableClass($configuration, 'provider', 'opentelemetry.logs');
        $configuration['exporter'] = $this->exporterName($configuration['exporter'], 'opentelemetry.logs.exporter');
        $configuration['schedule_delay'] = $this->integer(
            $configuration,
            'schedule_delay',
            'opentelemetry.logs',
        );
        $configuration['max_queue_size'] = $this->integer(
            $configuration,
            'max_queue_size',
            'opentelemetry.logs',
        );
        $configuration['max_export_batch_size'] = $this->integer(
            $configuration,
            'max_export_batch_size',
            'opentelemetry.logs',
        );
        $configuration['processors'] = $this->stringList(
            $configuration['processors'],
            'opentelemetry.logs.processors',
        );

        if ($configuration['exporter'] === 'none') {
            return $configuration;
        }

        $this->ensurePositiveInteger(
            $configuration['schedule_delay'],
            'opentelemetry.logs.schedule_delay',
        );

        if ($configuration['provider'] !== null) {
            return $configuration;
        }

        $this->ensurePositiveInteger(
            $configuration['max_queue_size'],
            'opentelemetry.logs.max_queue_size',
        );
        $this->ensurePositiveInteger(
            $configuration['max_export_batch_size'],
            'opentelemetry.logs.max_export_batch_size',
        );

        if ($configuration['max_export_batch_size'] > $configuration['max_queue_size']) {
            throw new InvalidArgumentException(
                'Configuration value [opentelemetry.logs.max_export_batch_size] must not exceed [max_queue_size].',
            );
        }

        return $configuration;
    }

    /**
     * Normalize named exporter configurations.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function exporters(mixed $configuration): array
    {
        $configuration = $this->associativeArray($configuration, 'opentelemetry.exporters');
        $normalized = [];

        foreach ($configuration as $name => $exporter) {
            if (! is_string($name) || $name === '') {
                throw new InvalidArgumentException('OpenTelemetry exporter names must be non-empty strings.');
            }

            if (! is_array($exporter)) {
                throw $this->invalidType("opentelemetry.exporters.{$name}", 'an array', $exporter);
            }

            if ($name === 'otlp') {
                $exporter = $this->record($exporter, $this->otlpDefaults(), "opentelemetry.exporters.{$name}");
            } elseif ($name === 'console') {
                $exporter = $this->record($exporter, ['driver' => 'console'], "opentelemetry.exporters.{$name}");
            }

            $exporter['driver'] = $this->string($exporter, 'driver', "opentelemetry.exporters.{$name}");
            $normalized[$name] = $exporter;
        }

        return $normalized;
    }

    /**
     * Return default OTLP exporter settings used to restore replaced records.
     *
     * @return array<string, mixed>
     */
    protected function otlpDefaults(): array
    {
        $defaults = [
            'driver' => 'otlp',
            'endpoint' => 'http://localhost:4318',
            'protocol' => 'http/protobuf',
            'headers' => [],
            'compression' => 'none',
            'timeout' => 10000,
            'certificate' => null,
            'client_certificate' => null,
            'client_key' => null,
            'max_retries' => 3,
        ];

        foreach (['traces', 'metrics', 'logs'] as $signal) {
            foreach (['endpoint', 'protocol', 'headers', 'compression', 'timeout', 'certificate', 'client_certificate', 'client_key'] as $option) {
                $defaults["{$signal}_{$option}"] = null;
            }
        }

        return $defaults;
    }

    /**
     * Normalize an exporter list to its single selected name.
     */
    protected function exporterName(mixed $configuration, string $path): string
    {
        if (is_string($configuration)) {
            if ($configuration === '') {
                throw new InvalidArgumentException("Configuration value [{$path}] must not be empty.");
            }

            return $configuration;
        }

        $exporters = $this->stringList($configuration, $path);

        if (count($exporters) !== 1) {
            throw new InvalidArgumentException(
                "Configuration value [{$path}] must contain exactly one exporter; use a Collector for fan-out.",
            );
        }

        return $exporters[0];
    }

    /**
     * Merge a replaceable configuration record with its fixed defaults.
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    protected function record(mixed $configuration, array $defaults, string $path): array
    {
        if (! is_array($configuration)) {
            throw $this->invalidType($path, 'an array', $configuration);
        }

        return array_replace($defaults, $configuration);
    }

    /**
     * Read a required boolean value.
     */
    protected function boolean(array $configuration, string $key, string $path): bool
    {
        $value = $configuration[$key] ?? null;

        if (! is_bool($value)) {
            throw $this->invalidType("{$path}.{$key}", 'a boolean', $value);
        }

        return $value;
    }

    /**
     * Read a required string value.
     */
    protected function string(array $configuration, string $key, string $path): string
    {
        $value = $configuration[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw $this->invalidType("{$path}.{$key}", 'a non-empty string', $value);
        }

        return $value;
    }

    /**
     * Read a nullable string value.
     */
    protected function nullableString(array $configuration, string $key, string $path): ?string
    {
        $value = $configuration[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw $this->invalidType("{$path}.{$key}", 'a string or null', $value);
        }

        return $value;
    }

    /**
     * Read a nullable class string.
     */
    protected function nullableClass(array $configuration, string $key, string $path): ?string
    {
        return $this->nullableString($configuration, $key, $path);
    }

    /**
     * Read a required integer value.
     */
    protected function integer(array $configuration, string $key, string $path): int
    {
        $value = $configuration[$key] ?? null;

        if (! is_int($value)) {
            throw $this->invalidType("{$path}.{$key}", 'an integer', $value);
        }

        return $value;
    }

    /**
     * Ensure an integer value is positive when its setting is active.
     */
    protected function ensurePositiveInteger(int $value, string $path): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException("Configuration value [{$path}] must be a positive integer.");
        }
    }

    /**
     * Normalize a list of strings.
     *
     * @return list<string>
     */
    protected function stringList(mixed $configuration, string $path): array
    {
        if (! is_array($configuration) || ! array_is_list($configuration)) {
            throw $this->invalidType($path, 'a list of strings', $configuration);
        }

        foreach ($configuration as $value) {
            if (! is_string($value) || $value === '') {
                throw $this->invalidType($path, 'a list of non-empty strings', $configuration);
            }
        }

        return $configuration;
    }

    /**
     * Normalize an associative array.
     *
     * @return array<string, mixed>
     */
    protected function associativeArray(mixed $configuration, string $path): array
    {
        if (! is_array($configuration) || ($configuration !== [] && array_is_list($configuration))) {
            throw $this->invalidType($path, 'an associative array', $configuration);
        }

        return $configuration;
    }

    /**
     * Create a consistent invalid-configuration exception.
     */
    protected function invalidType(string $path, string $expected, mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Configuration value [%s] must be %s, %s given.',
            $path,
            $expected,
            get_debug_type($value),
        ));
    }
}
