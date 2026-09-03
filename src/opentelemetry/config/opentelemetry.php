<?php

declare(strict_types=1);

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
use OpenTelemetry\SDK\Common\Configuration\Configuration;
use OpenTelemetry\SDK\Common\Configuration\Variables;

$optionalString = static fn (string $name): ?string => Configuration::has($name)
    ? Configuration::getString($name)
    : null;
$optionalInt = static fn (string $name): ?int => Configuration::has($name)
    ? Configuration::getInt($name)
    : null;
$optionalMap = static fn (string $name): ?array => Configuration::has($name)
    ? Configuration::getMap($name)
    : null;
$optionalEnum = static fn (string $name): ?string => Configuration::has($name)
    ? Configuration::getEnum($name)
    : null;

$resourceAttributes = Configuration::has(Variables::OTEL_RESOURCE_ATTRIBUTES)
    ? Configuration::getMap(Variables::OTEL_RESOURCE_ATTRIBUTES)
    : [];
$knownHttpMethods = Configuration::getList(
    'OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS',
    ['CONNECT', 'DELETE', 'GET', 'HEAD', 'OPTIONS', 'PATCH', 'POST', 'PUT', 'QUERY', 'TRACE'],
);

if (Configuration::has(Variables::OTEL_SERVICE_NAME)) {
    $resourceAttributes['service.name'] = Configuration::getString(Variables::OTEL_SERVICE_NAME);
}

return [
    'enabled' => ! Configuration::getBoolean(Variables::OTEL_SDK_DISABLED, false),
    'internal_metrics' => Configuration::getBoolean(Variables::OTEL_PHP_INTERNAL_METRICS_ENABLED, false),

    'service' => [
        'name' => env('APP_NAME', 'hypervel'),
        'version' => env('APP_VERSION'),
        'environment' => env('APP_ENV'),
        'instance_id' => null,
    ],

    'resource_attributes' => $resourceAttributes,
    'propagators' => Configuration::getList(Variables::OTEL_PROPAGATORS, ['tracecontext', 'baggage']),
    'response_propagators' => Configuration::getList(Variables::OTEL_EXPERIMENTAL_RESPONSE_PROPAGATORS, ['none']),

    'metrics' => [
        'provider' => null,
        'exporter' => Configuration::getList(Variables::OTEL_METRICS_EXPORTER, ['otlp']),
        'export_interval' => Configuration::getInt(Variables::OTEL_METRIC_EXPORT_INTERVAL, 60000),
        'temporality' => Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_METRICS_TEMPORALITY_PREFERENCE, 'cumulative'),
        'exemplar_filter' => Configuration::getEnum(Variables::OTEL_METRICS_EXEMPLAR_FILTER, 'trace_based'),
        'views' => [],
    ],

    'traces' => [
        'provider' => null,
        'exporter' => Configuration::getList(Variables::OTEL_TRACES_EXPORTER, ['otlp']),
        'sampler' => Configuration::getString(Variables::OTEL_TRACES_SAMPLER, 'parentbased_always_on'),
        'sampler_arg' => Configuration::getFloat(Variables::OTEL_TRACES_SAMPLER_ARG, 1.0),
        'schedule_delay' => Configuration::getInt(Variables::OTEL_BSP_SCHEDULE_DELAY, 5000),
        'max_queue_size' => Configuration::getInt(Variables::OTEL_BSP_MAX_QUEUE_SIZE, 2048),
        'max_export_batch_size' => Configuration::getInt(Variables::OTEL_BSP_MAX_EXPORT_BATCH_SIZE, 512),
        'processors' => [],
    ],

    'logs' => [
        'provider' => null,
        'exporter' => Configuration::getList(Variables::OTEL_LOGS_EXPORTER, ['otlp']),
        'schedule_delay' => Configuration::getInt(Variables::OTEL_BLRP_SCHEDULE_DELAY, 1000),
        'max_queue_size' => Configuration::getInt(Variables::OTEL_BLRP_MAX_QUEUE_SIZE, 2048),
        'max_export_batch_size' => Configuration::getInt(Variables::OTEL_BLRP_MAX_EXPORT_BATCH_SIZE, 512),
        'processors' => [],
    ],

    'log_context' => [
        'enabled' => false,
        'trace_id_key' => 'trace_id',
        'span_id_key' => 'span_id',
    ],

    'server_processes' => [
        'except' => [],
    ],

    'exporters' => [
        'otlp' => [
            'driver' => 'otlp',
            'endpoint' => Configuration::getString(Variables::OTEL_EXPORTER_OTLP_ENDPOINT, 'http://localhost:4318'),
            'protocol' => Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_PROTOCOL, 'http/protobuf'),
            'headers' => Configuration::has(Variables::OTEL_EXPORTER_OTLP_HEADERS)
                ? Configuration::getMap(Variables::OTEL_EXPORTER_OTLP_HEADERS)
                : [],
            'compression' => Configuration::getEnum(Variables::OTEL_EXPORTER_OTLP_COMPRESSION, 'none'),
            'timeout' => Configuration::getInt(Variables::OTEL_EXPORTER_OTLP_TIMEOUT, 10000),
            'certificate' => $optionalString(Variables::OTEL_EXPORTER_OTLP_CERTIFICATE),
            'client_certificate' => $optionalString('OTEL_EXPORTER_OTLP_CLIENT_CERTIFICATE'),
            'client_key' => $optionalString('OTEL_EXPORTER_OTLP_CLIENT_KEY'),
            'traces_endpoint' => $optionalString(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT),
            'traces_protocol' => $optionalEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL),
            'traces_headers' => $optionalMap(Variables::OTEL_EXPORTER_OTLP_TRACES_HEADERS),
            'traces_compression' => $optionalEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION),
            'traces_timeout' => $optionalInt(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT),
            'traces_certificate' => $optionalString(Variables::OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE),
            'traces_client_certificate' => $optionalString('OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE'),
            'traces_client_key' => $optionalString('OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEY'),
            'metrics_endpoint' => $optionalString(Variables::OTEL_EXPORTER_OTLP_METRICS_ENDPOINT),
            'metrics_protocol' => $optionalEnum(Variables::OTEL_EXPORTER_OTLP_METRICS_PROTOCOL),
            'metrics_headers' => $optionalMap(Variables::OTEL_EXPORTER_OTLP_METRICS_HEADERS),
            'metrics_compression' => $optionalEnum(Variables::OTEL_EXPORTER_OTLP_METRICS_COMPRESSION),
            'metrics_timeout' => $optionalInt(Variables::OTEL_EXPORTER_OTLP_METRICS_TIMEOUT),
            'metrics_certificate' => $optionalString(Variables::OTEL_EXPORTER_OTLP_METRICS_CERTIFICATE),
            'metrics_client_certificate' => $optionalString('OTEL_EXPORTER_OTLP_METRICS_CLIENT_CERTIFICATE'),
            'metrics_client_key' => $optionalString('OTEL_EXPORTER_OTLP_METRICS_CLIENT_KEY'),
            'logs_endpoint' => $optionalString(Variables::OTEL_EXPORTER_OTLP_LOGS_ENDPOINT),
            'logs_protocol' => $optionalEnum(Variables::OTEL_EXPORTER_OTLP_LOGS_PROTOCOL),
            'logs_headers' => $optionalMap(Variables::OTEL_EXPORTER_OTLP_LOGS_HEADERS),
            'logs_compression' => $optionalEnum(Variables::OTEL_EXPORTER_OTLP_LOGS_COMPRESSION),
            'logs_timeout' => $optionalInt(Variables::OTEL_EXPORTER_OTLP_LOGS_TIMEOUT),
            'logs_certificate' => $optionalString(Variables::OTEL_EXPORTER_OTLP_LOGS_CERTIFICATE),
            'logs_client_certificate' => $optionalString('OTEL_EXPORTER_OTLP_LOGS_CLIENT_CERTIFICATE'),
            'logs_client_key' => $optionalString('OTEL_EXPORTER_OTLP_LOGS_CLIENT_KEY'),
            'max_retries' => 3,
        ],
        'console' => [
            'driver' => 'console',
        ],
    ],

    'instrumentation' => [
        HttpServerInstrumentation::class => [
            'traces' => true,
            'known_methods' => $knownHttpMethods,
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
            'known_methods' => $knownHttpMethods,
            'manual' => false,
            'url_query' => false,
            'sensitive_query_parameters' => [],
            'sensitive_headers' => [],
            'request_headers' => [],
            'response_headers' => [],
            'metrics' => [
                'http.client.request.duration' => true,
            ],
        ],
        DatabaseInstrumentation::class => [
            'traces' => true,
            'query_text' => true,
            'query_text_max_length' => 500,
            'metrics' => [
                'db.client.operation.duration' => true,
            ],
        ],
        RedisInstrumentation::class => [
            'traces' => true,
            'query_text' => false,
            'query_text_max_length' => 500,
            'metrics' => [
                'db.client.operation.duration' => true,
            ],
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
            'metrics' => [
                'hypervel.cache.operations' => true,
            ],
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
            'metrics' => [
                'hypervel.console.command.duration' => true,
            ],
        ],
        EventInstrumentation::class => [
            'events' => [],
        ],
        ViewInstrumentation::class => [
            'traces' => true,
            'metrics' => [
                'hypervel.view.render.duration' => true,
            ],
        ],
        ScoutInstrumentation::class => [
            'traces' => true,
            'metrics' => [
                'db.client.operation.duration' => true,
            ],
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
            'metrics' => [
                'hypervel.exceptions' => true,
            ],
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
    ],
];
