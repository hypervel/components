# OpenTelemetry

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
    - [OTLP Export](#otlp-export)
    - [Signals](#signals)
    - [Resource Attributes](#resource-attributes)
    - [Configuration Lifecycle](#configuration-lifecycle)
    - [Batching and Shutdown](#batching-and-shutdown)
- [Instrumentation](#instrumentation)
    - [HTTP](#http)
    - [Database, Redis, and Cache](#database-redis-cache)
    - [Queues](#queues)
    - [Application Operations](#application-operations)
    - [Runtime and Pool Metrics](#runtime-pool-metrics)
- [Exceptions and Logs](#exceptions-logs)
    - [Exception Enrichment](#exception-enrichment)
    - [Application Logs](#application-logs)
- [Custom Telemetry](#custom-telemetry)
    - [Metrics](#custom-metrics)
    - [Traces](#custom-traces)
    - [Logs](#custom-logs)
    - [Propagation](#propagation)
- [Extending OpenTelemetry](#extending-opentelemetry)
    - [Custom Instrumentation](#custom-instrumentation)
    - [Metric Views](#metric-views)
    - [Custom Exporters](#custom-exporters)
    - [Provider Overrides](#provider-overrides)
- [Deployment](#deployment)
    - [Per-Worker Export](#per-worker-export)
    - [Collectors and Relays](#collectors-relays)
    - [Custom Server Processes](#custom-server-processes)
    - [Performance](#performance)

<a name="introduction"></a>
## Introduction

[OpenTelemetry](https://opentelemetry.io) is a vendor-neutral standard for collecting metrics, traces, and logs. Hypervel's OpenTelemetry integration provides coroutine-safe context propagation, framework instrumentation, and OTLP export for long-running Swoole workers.

<a name="installation"></a>
## Installation

You may install the OpenTelemetry integration using Composer:

```shell
composer require hypervel/opentelemetry
```

The default OTLP/HTTP protobuf exporter requires the `ext-protobuf` PHP extension. See [OTLP Export](#otlp-export) when using another exporter or protocol.

You may publish the package configuration using the `vendor:publish` Artisan command:

```shell
php artisan vendor:publish --tag=opentelemetry-config
```

<a name="configuration"></a>
## Configuration

The published `config/opentelemetry.php` file contains signal exporters, batch settings, resource attributes, and independently configurable framework instrumentation.

OpenTelemetry reads supported standard `OTEL_*` settings through the upstream PHP SDK configuration system. This includes environment variables, PHP configuration values, and resolvers installed through the SDK's SPI system. Hypervel's `APP_NAME`, `APP_VERSION`, and `APP_ENV` values provide application defaults when the matching OpenTelemetry resource attributes are absent.

The SDK accepts only the case-insensitive value `true` for `OTEL_SDK_DISABLED`. Values commonly accepted by Laravel's `env` helper, such as `1`, `yes`, and `on`, are invalid OpenTelemetry boolean values. The SDK reports a diagnostic and leaves telemetry enabled when one of those values is used.

<a name="otlp-export"></a>
### OTLP Export

The package exports OTLP over HTTP using protobuf by default:

```ini
OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
```

You may select OTLP JSON instead:

```ini
OTEL_EXPORTER_OTLP_PROTOCOL=http/json
```

The `ext-protobuf` extension is required whenever an active built-in OTLP exporter resolves to `http/protobuf`, including through a signal-specific protocol override. Without it, telemetry startup fails: the server does not reach a serving state, and a standalone Artisan command or custom server process fails when it binds. JSON, `console`, `none`, custom exporter drivers, and complete provider overrides do not require the extension.

Shared endpoint, header, compression, timeout, certificate, client certificate, and client key settings may be overridden for each signal using the standard `OTEL_EXPORTER_OTLP_TRACES_*`, `OTEL_EXPORTER_OTLP_METRICS_*`, and `OTEL_EXPORTER_OTLP_LOGS_*` variables. A shared endpoint receives the standard `/v1/traces`, `/v1/metrics`, or `/v1/logs` path. A signal-specific endpoint is used exactly as configured.

Compression defaults to `none`. Set `OTEL_EXPORTER_OTLP_COMPRESSION=gzip` when reduced network use is worth the additional worker CPU cost. Extension-backed protobuf is the recommended high-throughput path. JSON remains useful for lower-volume or heavily sampled telemetry, but it performed materially worse in sustained tracing benchmarks.

The `max_retries` option in the `otlp` exporter record controls the upstream transport's existing retry loop. The configured OTLP timeout applies to each HTTP attempt. Retries, backoff, and `Retry-After` may make a flush take longer than one timeout period.

The package does not provide an OTLP/gRPC exporter, Prometheus scrape endpoint, StatsD output, Influx line protocol, or local telemetry storage. Use an OpenTelemetry Collector, compatible agent, or backend when protocol translation, durable buffering, or fan-out is required.

<a name="signals"></a>
### Signals

Metrics, traces, and logs are configured independently:

```php
'metrics' => [
    'exporter' => ['otlp'],
    'export_interval' => 60000,
],

'traces' => [
    'exporter' => ['otlp'],
    'sampler' => 'parentbased_always_on',
    'schedule_delay' => 5000,
    'max_queue_size' => 2048,
    'max_export_batch_size' => 512,
],

'logs' => [
    'exporter' => ['otlp'],
    'schedule_delay' => 1000,
    'max_queue_size' => 2048,
    'max_export_batch_size' => 512,
],
```

Set a signal's exporter to `none` to disable that signal:

```ini
OTEL_METRICS_EXPORTER=none
OTEL_LOGS_EXPORTER=none
```

Each signal accepts exactly one exporter. Use a Collector when one signal must be delivered to several destinations.

Trace samplers use standard OpenTelemetry names, including `always_on`, `always_off`, `parentbased_always_on`, `parentbased_always_off`, `traceidratio`, and `parentbased_traceidratio`. Ratio samplers read `OTEL_TRACES_SAMPLER_ARG` as a value from `0.0` through `1.0`.

`OTEL_PHP_INTERNAL_METRICS_ENABLED` enables the PHP SDK's own processor and exporter metrics. It is off by default because the SDK records some counters on span creation even when a sampler rejects the span. Enable it when visibility into queue depth, exported records, and dropped spans or logs is worth that added application-path work. The cumulative `otel.sdk.processor.span.processed` and `otel.sdk.processor.log.processed` counters with `error.type` are the reliable loss indicators; queue-size observations are periodic samples and may miss short bursts.

<a name="resource-attributes"></a>
### Resource Attributes

You may describe the deployed service using standard resource attributes:

```ini
OTEL_SERVICE_NAME=orders
OTEL_RESOURCE_ATTRIBUTES=service.version=2026.08,deployment.environment.name=production
```

`OTEL_SERVICE_NAME` takes precedence over `service.name` inside `OTEL_RESOURCE_ATTRIBUTES`. Explicit resource attributes take precedence over the corresponding `APP_*` defaults.

Hypervel appends the producing process type, stable worker or process identity, and process ID to the configured `service.instance.id` base. This keeps event workers, task workers, CLI processes, and custom server processes distinct across reloads. A complete provider override owns its own resource and must create an equally unique `service.instance.id`.

<a name="configuration-lifecycle"></a>
### Configuration Lifecycle

Publishing the configuration is recommended for production applications that use configuration caching. Standard values read by `config/opentelemetry.php` are resolved when the configuration file is evaluated. Running `config:cache` therefore freezes them until the cache is rebuilt:

```shell
php artisan config:cache
```

The SDK reads resource-detector settings and signal-specific span and log limits when package-built providers are created after fork. These settings include `OTEL_PHP_DETECTORS`, `OTEL_SPAN_ATTRIBUTE_COUNT_LIMIT`, `OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT`, `OTEL_SPAN_EVENT_COUNT_LIMIT`, `OTEL_EVENT_ATTRIBUTE_COUNT_LIMIT`, `OTEL_SPAN_LINK_COUNT_LIMIT`, `OTEL_LINK_ATTRIBUTE_COUNT_LIMIT`, `OTEL_LOGRECORD_ATTRIBUTE_COUNT_LIMIT`, and `OTEL_LOGRECORD_ATTRIBUTE_VALUE_LENGTH_LIMIT`. Cached explicit resource attributes are merged over detector output.

The current PHP SDK does not use the generic `OTEL_ATTRIBUTE_COUNT_LIMIT` and `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT` values as fallbacks for its trace and log builders. Configure the signal-specific values instead.

The package's `enabled`, `propagators`, and `response_propagators` settings establish process-global OpenTelemetry context in the master process and require a full server restart. Event and task workers consume their signal, exporter, sampler, view, processor, and instrumentation settings after Hypervel reloads worker configuration. Standalone commands use startup configuration. Custom server processes inherit startup configuration and also require a full restart for changes.

The current PHP SDK does not enforce `OTEL_BSP_EXPORT_TIMEOUT`, `OTEL_BLRP_EXPORT_TIMEOUT`, `OTEL_METRIC_EXPORT_TIMEOUT`, or `OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION`. These settings are intentionally absent from the published configuration. Supply a complete provider override when another behavior is required.

<a name="batching-and-shutdown"></a>
### Batching and Shutdown

Request, job, query, and log paths add data only to in-memory SDK aggregators or bounded queues. One coordinator coroutine per producing process performs encoding, compression, and network export for every enabled signal.

The default trace queue holds 2048 completed sampled spans and drains every five seconds. The default log queue holds 2048 records and drains every second. A batch size of 512 means a full queue may require four HTTP requests during one drain. Size queues and intervals for each worker's sustained and burst volume; a failed or slow backend reduces the volume that can be retained before records are dropped.

On shutdown, trace and log providers drain before the metrics provider. This lets the final metric collection include SDK records created by the trace and log drains. Shutdown is best effort and synchronous for standalone commands and non-coroutine custom processes. CLI-heavy deployments may use a lower OTLP timeout and `max_retries => 0` to bound exit delay.

<a name="instrumentation"></a>
## Instrumentation

Each built-in instrumentation class may be set to `false`, `true`, or an option array. Every advertised metric has its own switch. A disabled metric creates no instrument, callback, timing state, or record call. A domain whose outputs are all disabled registers no listener, middleware, or observer.

Omitting a built-in metric does not disable it. Set its name to `false` to disable it individually, or set `metrics` to `false` to disable every metric in that instrumentation. Named entries override only the metrics they name; the others keep their shipped defaults.

When Swoole cancels an operation coroutine, the package emits no completion span, metric, or event. Its coroutine-local telemetry state is released when that coroutine ends.

The shipped defaults are:

| Domain | Traces / logs | Metrics enabled by default |
| --- | --- | --- |
| HTTP server | Request spans | `http.server.request.duration`; `http.server.active_requests` is off |
| HTTP client | Physical request spans | `http.client.request.duration` |
| Database and Redis | Client operation spans | `db.client.operation.duration` |
| Database and Redis pools | None | `db.client.connection.count`, `db.client.connection.max`, `db.client.connection.pending_requests` |
| Cache | Events on the current span | `hypervel.cache.operations` |
| Queue | Producer and consumer spans | `messaging.client.sent.messages`, `messaging.client.operation.duration`, `messaging.client.consumed.messages`, `messaging.process.duration`; `hypervel.queue.jobs` is off |
| Scheduler | Task spans | `hypervel.scheduler.task.duration`, `hypervel.scheduler.task.executions` |
| Console | Command spans | `hypervel.console.command.duration` |
| Events | Exact allowlist, empty by default | None |
| Views | Render spans | `hypervel.view.render.duration` |
| Scout | Search-engine operation spans | `db.client.operation.duration` |
| gRPC | Logical client and server RPC spans | `rpc.client.call.duration`, `rpc.server.call.duration` |
| WebSockets | Message spans | `hypervel.websocket.message.duration`, `hypervel.websocket.messages`, `hypervel.websocket.active_connections` |
| Exceptions | Error log records | `hypervel.exceptions` |
| Object pools | None | `hypervel.object_pool.objects`, `hypervel.object_pool.max`, `hypervel.object_pool.pending_requests` |
| PHP runtime | None | memory, GC, process CPU/context-switch, and OPcache metrics |
| Swoole server and workers | None | connections, requests, task activity/queue depth, worker requests, and coroutine count |

OPcache and process-global Swoole server statistics are collected only in event worker zero so they are not multiplied by worker count. Memory, GC, CPU, context-switch, worker-request, and coroutine metrics are emitted per producing worker. Unavailable functions or statistic keys are omitted rather than reported as zero.

<a name="http"></a>
### HTTP

HTTP server spans begin when a request is received and end after the response send attempt. The final matched route is used for the span name and `http.route`; streamed bodies are never buffered merely to calculate their size. WebSocket handshakes and Reverb's routed HTTP requests use the same server lifecycle.

Health checks and other routes may be excluded before any OpenTelemetry work:

```php
HttpServerInstrumentation::class => [
    'traces' => true,
    'except_paths' => ['up', 'health/*'],
    'except_methods' => ['OPTIONS'],
    // ...
],
```

`OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS` replaces the standard known-method list. Values are case-sensitive. Server instrumentation describes the wire method even when `_method` or `X-HTTP-METHOD-OVERRIDE` changes application routing. Outgoing instrumentation describes the exact PSR-7 method seen at the physical send boundary. Guzzle 7 currently uppercases methods sent through Hypervel's convenient `Http::get()` style API, while a request passed through `Http::buildClient()->send()` retains the PSR-7 request's casing.

Outgoing requests are traced automatically by default. You may disable tracing for one request:

```php
Http::withoutTrace()->get('https://example.com/status');
```

When the HTTP client instrumentation's `manual` option is `true`, requests are traced only when selected:

```php
Http::withTrace()->get('https://example.com/orders');
```

These controls affect tracing and propagation only. An enabled duration metric still observes each outgoing request.

Outgoing HTTP clients do not know an application's route template. You may provide one during application boot:

```php
use Hypervel\OpenTelemetry\Facades\OpenTelemetry;
use Psr\Http\Message\RequestInterface;

OpenTelemetry::resolveUrlTemplateUsing(
    function (RequestInterface $request): ?string {
        if ($request->getUri()->getHost() === 'api.example.com'
            && str_starts_with($request->getUri()->getPath(), '/orders/')) {
            return '/orders/{order}';
        }

        return null;
    },
);
```

URL queries and request/response headers are off by default. Captured URL credentials are always redacted. Authorization, proxy authorization, cookies, PHP authentication passwords, and set-cookie values remain redacted even if allowlisted. Sensitive query parameters use a built-in list that may be extended in configuration.

Response propagation is experimental in OpenTelemetry and defaults to `none`. Installed propagation packages may register `servertiming` or `traceresponse` through `OTEL_EXPERIMENTAL_RESPONSE_PROPAGATORS`.

<a name="database-redis-cache"></a>
### Database, Redis, and Cache

Database spans record the parameterized SQL supplied by Hypervel as `db.query.text` by default. Bindings are never attached or interpolated. Query text is limited to 500 Unicode characters by default and may be disabled or given another positive limit:

```php
DatabaseInstrumentation::class => [
    'traces' => true,
    'query_text' => false,
    'query_text_max_length' => 500,
    // ...
],
```

Set `query_text_max_length` to `null` to remove this query-specific cap. The SDK's `OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT` still applies. Parameterized SQL keeps values out of telemetry, but literals written directly into raw SQL remain visible when query text is enabled.

Redis command names are normalized to uppercase. Redis query text is off by default. When enabled, the built-in formatter includes only key or field positions known to be safe for the command family and omits values, credentials, scripts, and message bodies. Unknown and module commands record only the command name. Keys and field names can still contain private or high-cardinality data.

Redis command spans and duration metrics include the configured connection name as `hypervel.redis.connection`. This lets command telemetry line up with the matching `redis:<connection-name>` pool series. Physical endpoint and database attributes are omitted because Cluster and Sentinel commands may run against a different node than the configured discovery address, and Redis may change databases at runtime.

Applications that understand custom Redis commands may replace the formatter:

```php
OpenTelemetry::resolveRedisQueryTextUsing(
    function (string $command, array $parameters, string $connection): ?string {
        return $command === 'ACME.GET'
            ? $command . ' ' . hash('xxh128', (string) ($parameters[0] ?? ''))
            : null;
    },
);
```

Cache instrumentation adds completion events to the current recording span and increments `hypervel.cache.operations`. Raw cache keys are off by default. When enabled, they may be normalized, hashed, or removed:

```php
OpenTelemetry::resolveCacheKeyUsing(
    fn (string $key, ?string $store): ?string => hash('xxh128', $key),
);
```

Write span events include an already-known TTL in seconds. Keys and TTLs are never metric dimensions. Cache instrumentation follows repository events: a store configured with `events => false` emits no ordinary cache telemetry, memoized local hits are not counted, and `add`, `increment`, and `decrement` have no completion events. Failover transitions are reported independently with distinct logical failover and failed backing-store identities.

Pool metrics inspect only pools already created in the worker and run only during metric collection. Database and Redis pool names use `database:` and `redis:` prefixes so equal application connection names do not collide.

<a name="queues"></a>
### Queues

Persistent asynchronous jobs receive one producer span and flat propagation fields in their final encoded payload immediately before broker or storage work. The consumer extracts that context and creates one process span. Producer and consumer metrics omit job IDs, classes, and payloads.

Propagation may remain enabled when local queue tracing is disabled. This allows application or third-party OpenTelemetry context and baggage to cross the queue without a built-in producer span. Disable propagation as well when the deployment carries no trace context and wants to avoid final payload decoding and encoding.

The built-in `background` and `deferred` connections propagate the current context without producer telemetry because they have no broker terminal event. The `sync` connection runs under the ambient context. All three local drivers count jobs as consumed but do not count them as sent or record send duration, so do not compare the sent and consumed counters when using these drivers. Direct driver `pushRaw()` calls bypass Hypervel's job payload lifecycle; callers that own raw payloads must inject propagation themselves.

Jobs dispatched after a database commit are finalized and timed only when the queue driver actually begins enqueueing them. Database and SQS batch operations retain one lifecycle per logical message. Later SQS chunks include the time spent sending earlier chunks because timing begins when the batch is finalized, and a later batch failure completes every still-outstanding message once.

Terminal queue correlation normally uses the exact final encoded payload without decoding it. If another finalizer rewrites that payload afterward, the package performs one terminal decode to recover Hypervel's job UUID. Payloads from custom drivers or `Queue::createPayloadUsing()` callbacks that do not contain a string UUID are accepted, omit `messaging.message.id`, and use exact-payload correlation only. If a later finalizer rewrites such a payload, its producer span remains started but is never ended or exported, and its state remains until coroutine or process exit. A later finalizer that removes or corrupts an existing UUID, or throws after earlier messages in the same batch were finalized, likewise leaves unmatched state. A long-running non-coroutine custom process that catches and continues after such failures may retain that state until it exits.

Queue depth is off by default because it performs backend I/O during metric collection. Enable it with explicit targets:

```php
QueueInstrumentation::class => [
    'traces' => true,
    'propagation' => true,
    'depth_queues' => [
        'redis' => ['default', 'emails'],
    ],
    'metrics' => [
        // ...
        'hypervel.queue.jobs' => true,
    ],
],
```

Depth collection belongs to event worker zero. A queue-only deployment without an event worker should register its own observable metric in a deliberately selected process.

<a name="application-operations"></a>
### Application Operations

Console instrumentation traces commands but excludes the pre-fork server start command. `commands` and `except` accept Laravel-style wildcard patterns. Commands invoked directly through Symfony's `Command::run()` do not establish an Artisan telemetry lifecycle. The `env:encrypt` and `env:decrypt` commands deliberately skip provider boot and therefore do not emit package telemetry. Scheduled tasks become independent root spans so a minute-long `schedule:run` command does not become the parent of every task.

Event instrumentation uses an exact passive allowlist:

```php
EventInstrumentation::class => [
    'events' => [
        App\Events\OrderPlaced::class,
        'billing.settled',
    ],
],
```

It adds an event to the current recording span without inspecting event payloads. Passive observation does not make `hasListeners()` true, so a framework event deliberately suppressed when it has no active listeners remains suppressed.

View spans include only the view name. View data, rendered output, and filesystem paths are excluded. Scout spans cover the actual search-engine `search`, `paginate`, `update`, `delete`, `flush`, and filtered-delete operation; query strings, pagination values, model IDs, and index payloads are excluded. Engines resolved through `EngineManager` are observed automatically. A directly constructed raw engine is not.

When `hypervel/grpc` is installed, one span covers a complete logical RPC across retries, backoff, and streaming. Message bodies and metadata values are excluded. Every non-OK client status is classified as an error. Server spans follow the narrower RPC server convention, so application-result statuses such as `INVALID_ARGUMENT` do not become errors merely because they are non-OK. Exception records reported within an RPC retain the bounded `rpc` origin. Explicit `Call::cancel()` completes a client span as `CANCELLED`; runtime coroutine cancellation does not emit a false completion. Client endpoint attributes use the known configured address and port. Server metric address attributes remain off unless `server_metric_address` is enabled.

WebSocket instrumentation covers each application message and active connection counts without creating connection-lifetime spans. It also covers Reverb's WebSocket handler through the shared Hypervel message boundary.

<a name="runtime-pool-metrics"></a>
### Runtime and Pool Metrics

Runtime metrics use one snapshot per source during collection. The complete default surface is visible in `config/opentelemetry.php` and includes:

- `php.memory.usage`, `php.memory.peak_usage`, and `php.memory.limit`;
- `php.gc.runs`, `php.gc.collected`, `php.gc.threshold`, `php.gc.roots`, `php.gc.collector_time`, `php.gc.destructor_time`, and `php.gc.free_time`;
- `process.cpu.time` and `process.context_switches`;
- `php.opcache.memory_used`, `php.opcache.memory_free`, `php.opcache.memory_wasted`, `php.opcache.hit_rate`, `php.opcache.hits`, `php.opcache.misses`, and `php.opcache.cached_scripts`;
- `php.opcache.interned_strings.memory_used`, `php.opcache.interned_strings.memory_free`, and `php.opcache.interned_strings.count`;
- `hypervel.server.connections`, `hypervel.server.requests`, `hypervel.server.tasks.active`, and `hypervel.server.task_queue.size`;
- `hypervel.worker.requests` and `hypervel.worker.coroutines`.

Object-pool metrics use the exact pool registry name. Framework-generated automatic names contain a construction fingerprint and may change when construction input changes. Use an explicit stable `pool.name` when dashboard continuity matters. Deliberately dynamic application pool names and recycler eviction can create historical backend series even though the live worker contains only a bounded set. Disable those metrics or use a metric view when that is not acceptable.

<a name="exceptions-logs"></a>
## Exceptions and Logs

Reported exceptions are captured directly as OpenTelemetry error log records when the logs signal and exception instrumentation are enabled. Hypervel's normal exception mapping, `dontReport` filtering, duplicate suppression, throttling, custom `report()` methods, and earlier reportable callbacks remain authoritative.

The default record includes standard exception type, message, stack trace, code location, trace/span correlation, and a truthful bounded origin such as `request`, `job`, `console`, `schedule`, `websocket`, `rpc`, or `process`. The exception counter uses only exception type and optional origin.

Message and stack capture are independently configurable:

```php
ExceptionInstrumentation::class => [
    'logs' => true,
    'message' => true,
    'stack_trace' => true,
    'metrics' => [
        'hypervel.exceptions' => true,
    ],
],
```

Exceptions on unsampled requests still produce error log records when logs are enabled. A custom exception handler without Laravel's `reportable()` method cannot be instrumented through this integration.

<a name="exception-enrichment"></a>
### Exception Enrichment

Applications and packages may append exception attributes during provider boot. Multiple callbacks run in registration order:

```php
use OpenTelemetry\API\Logs\LogRecordBuilderInterface;
use Throwable;

OpenTelemetry::enrichExceptionsUsing(
    function (LogRecordBuilderInterface $record, Throwable $exception): void {
        if ($tenant = tenant()) {
            $record->setAttribute('app.tenant.id', (string) $tenant->getKey());
        }
    },
);
```

Enrichment runs in the originating worker before export or relay serialization, while request, user, tenant, and exception objects are still available. A failing enricher is reported through OpenTelemetry diagnostics and does not prevent later enrichers from running.

Enable `user_context` on HTTP server instrumentation to include the authenticated user ID on recording request spans and request-origin exception records. Hypervel calls `hasUser()` before reading the user, so telemetry never starts authentication or a database lookup solely to collect context. You may replace the default mapping:

```php
use Hypervel\Contracts\Auth\Authenticatable;

OpenTelemetry::resolveUserUsing(
    fn (Authenticatable $user): iterable => [
        'user.id' => (string) $user->getAuthIdentifier(),
    ],
);
```

User attributes are resolved at most once per included request. Consider privacy and backend cardinality before adding tenant, role, or account fields.

<a name="application-logs"></a>
### Application Logs

General application-log export is opt-in. Add an `opentelemetry` channel to `config/logging.php`:

```php
'channels' => [
    'opentelemetry' => [
        'driver' => 'opentelemetry',
        'name' => 'application',
        'level' => env('LOG_LEVEL', 'debug'),
    ],

    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'opentelemetry'],
    ],
],
```

The channel preserves the record timestamp, level, message, current OpenTelemetry context, and scalar or valid list context values. It does not recursively serialize arbitrary objects. An exception record already emitted by direct exception capture is not emitted again by the channel.

Disabling the SDK or logs signal keeps the named channel resolvable. Its handler returns immediately and allows later stack handlers to receive the record. An application that leaves the disabled channel in a logging stack still pays Monolog's normal record dispatch cost.

The optional `log_context` bridge adds current trace and span IDs to Hypervel's coroutine-local logging context for non-OpenTelemetry log channels. It is off by default because standard OpenTelemetry log records already correlate through context:

```php
'log_context' => [
    'enabled' => true,
    'trace_id_key' => 'trace_id',
    'span_id_key' => 'span_id',
],
```

<a name="custom-telemetry"></a>
## Custom Telemetry

The facade returns standard OpenTelemetry interfaces. Applications and third-party packages do not need a Hypervel-specific metric or span abstraction. Long-lived meters, tracers, loggers, and metric instruments may be created during provider boot; their deferred handles begin recording after the producing worker or process binds its SDK.

Do not create mutable span or log builders before binding. Request those operation-local builders when the operation begins.

<a name="custom-metrics"></a>
### Metrics

```php
use Hypervel\OpenTelemetry\Facades\OpenTelemetry;

$counter = OpenTelemetry::meter('acme.billing', '1.0.0')
    ->createCounter('billing.invoices.created', '{invoice}');

$counter->add(1, ['billing.plan' => $plan]);
```

Use low-cardinality metric attributes. IDs, raw URLs, exception messages, cache keys, SQL, job classes, and other unbounded application values belong on spans or logs rather than metrics.

<a name="custom-traces"></a>
### Traces

The `trace` helper starts and activates an internal span, passes it to the callback, records ordinary exceptions, and rethrows them:

```php
use OpenTelemetry\API\Trace\SpanInterface;

$invoice = OpenTelemetry::trace(
    'billing.invoice.create',
    function (SpanInterface $span): Invoice {
        $span->setAttribute('billing.account.id', (string) $this->account->getKey());

        return $this->invoices->create();
    },
);
```

For full control, use the standard tracer:

```php
$span = OpenTelemetry::tracer('acme.billing')
    ->spanBuilder('billing.charge')
    ->startSpan();

try {
    // ...
} finally {
    $span->end();
}
```

<a name="custom-logs"></a>
### Logs

```php
OpenTelemetry::logger('acme.billing')
    ->logRecordBuilder()
    ->setBody('Invoice created')
    ->setAttribute('billing.plan', $plan)
    ->emit();
```

<a name="propagation"></a>
### Propagation

Array carriers have Laravel-style convenience methods:

```php
$carrier = OpenTelemetry::inject(['message' => 'payload']);
$context = OpenTelemetry::extract($carrier);
```

For another carrier type, call the configured standard propagator with its matching getter/setter:

```php
$propagator = OpenTelemetry::propagator();
$propagator->inject($request, $requestHeadersSetter, $context);
```

Unkeyed `Hypervel\Coroutine\Coroutine::fork()` inherits a snapshot of the current OpenTelemetry context. `Coroutine::create()` starts from the package base context. A keyed fork copies only its explicit Hypervel context allowlist. Code that creates execution through another API must pass and activate a `ContextInterface` explicitly.

Propagation remains available when `OTEL_SDK_DISABLED=true`, as required by OpenTelemetry. Installing the disabled package creates no request-local state unless application or third-party code actually uses OpenTelemetry context or propagation.

<a name="extending-opentelemetry"></a>
## Extending OpenTelemetry

Register worker-lifetime callbacks and exporter drivers during application provider boot, before the producing process binds telemetry. Instrumentation, provider, sampler, processor, and metric-view class strings are resolved from the container after fork.

<a name="custom-instrumentation"></a>
### Custom Instrumentation

An instrumentation may extend `AbstractInstrumentation` for the standard trace and per-metric switches:

```php
namespace Acme\Telemetry;

use Hypervel\OpenTelemetry\Instrumentation\AbstractInstrumentation;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;

class BillingInstrumentation extends AbstractInstrumentation
{
    private ?CounterInterface $invoices = null;

    public function __construct(private MeterProviderInterface $meters)
    {
    }

    protected function registerInstrumentation(): void
    {
        if ($this->metricEnabled('billing.invoices.created')) {
            $this->invoices = $this->meters
                ->getMeter('acme.billing')
                ->createCounter('billing.invoices.created', '{invoice}');
        }
    }
}
```

Add the class and its defaults to the class-keyed instrumentation map:

```php
'instrumentation' => [
    BillingInstrumentation::class => [
        'metrics' => [
            'billing.invoices.created' => true,
        ],
    ],
],
```

The class may implement `Hypervel\OpenTelemetry\Contracts\Instrumentation` directly when it does not need the helper. A disabled class is not resolved from the container.

A reusable package may merge its own resource attributes, exporters, and instrumentations into the shared configuration:

```php
class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/opentelemetry.php', 'opentelemetry');
    }

    protected function mergeableOptions(string $name): array
    {
        return ['resource_attributes', 'exporters', 'instrumentation'];
    }
}
```

Hypervel reapplies these defaults when workers rebuild their configuration.

<a name="metric-views"></a>
### Metric Views

Metric views use the upstream SDK's typed selection and view objects. For example, this view keeps only selected queue-duration attributes:

```php
use Hypervel\OpenTelemetry\Contracts\MetricView;
use OpenTelemetry\SDK\Metrics\View\SelectionCriteria\InstrumentNameCriteria;
use OpenTelemetry\SDK\Metrics\View\SelectionCriteriaInterface;
use OpenTelemetry\SDK\Metrics\View\ViewTemplate;

class QueueDurationView implements MetricView
{
    public function criteria(): SelectionCriteriaInterface
    {
        return new InstrumentNameCriteria('messaging.process.duration');
    }

    public function template(): ViewTemplate
    {
        return ViewTemplate::create()->withAttributeKeys([
            'messaging.system',
            'messaging.destination.name',
            'messaging.operation.name',
            'error.type',
        ]);
    }
}
```

Register view classes in `metrics.views`. Views are resolved before the metric exporter is created:

```php
'metrics' => [
    'views' => [QueueDurationView::class],
    // ...
],
```

Views are also the correct place to remove deployment-specific dynamic dimensions such as database namespaces, queue destinations, Scout indices, or recognized RPC methods.

<a name="custom-exporters"></a>
### Custom Exporters

Custom exporter drivers implement one small factory contract. This makes it possible for a private agent to send already-batched payloads to an in-instance relay without changing instrumentation:

```php
use Hypervel\Contracts\Container\Container;
use Hypervel\OpenTelemetry\Contracts\ExporterFactory;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;

class RelayExporterFactory implements ExporterFactory
{
    public function __construct(
        private SpanExporterInterface $spans,
        private MetricExporterInterface $metrics,
        private LogRecordExporterInterface $logs,
    ) {
    }

    public function spanExporter(array $config): SpanExporterInterface
    {
        return $this->spans;
    }

    public function metricExporter(array $config): MetricExporterInterface
    {
        return $this->metrics;
    }

    public function logExporter(array $config): LogRecordExporterInterface
    {
        return $this->logs;
    }
}

OpenTelemetry::extend(
    'relay',
    fn (Container $container): ExporterFactory => $container->make(RelayExporterFactory::class),
);
```

Define the driver and select it for each signal that uses the relay:

```php
'exporters' => [
    'nightwatch' => [
        'driver' => 'relay',
        'socket' => '/run/nightwatch/telemetry.sock',
    ],
],

'metrics' => ['exporter' => ['nightwatch']],
'traces' => ['exporter' => ['nightwatch']],
'logs' => ['exporter' => ['nightwatch']],
```

Exporter implementations must remain non-blocking and bounded. A relay may aggregate workers, authenticate, retry, persist, and maintain one upstream connection. Enrichment that needs the request, user, tenant, or exception must still run in the originating worker before serialization.

<a name="provider-overrides"></a>
### Provider Overrides

Advanced applications may replace one complete SDK provider:

```php
'traces' => [
    'provider' => App\Telemetry\TracerProvider::class,
    'schedule_delay' => 5000,
],
```

The class must implement the matching SDK lifecycle interface: `OpenTelemetry\SDK\Trace\TracerProviderInterface`, `OpenTelemetry\SDK\Metrics\MeterProviderInterface`, or `OpenTelemetry\SDK\Logs\LoggerProviderInterface`. Hypervel still schedules `forceFlush()` and `shutdown()` using the signal cadence, but the override owns its complete resource, exporter, processor or reader, sampler, views, limits, and unique process identity. Other package settings for that signal are not applied.

You may instead add standard span or log processors through `traces.processors` and `logs.processors`, or supply a container-resolved sampler through `traces.sampler`. Added processors are siblings of the package batch processor. They may observe or enrich records but cannot prevent the package processor from exporting them.

Tail sampling makes a decision after spans have ended. A process-local implementation must buffer bounded traces, spans, and bytes; define a decision timeout and late-span policy; remain coroutine-safe; and correctly flush and shut down its downstream processor. It belongs in a complete trace-provider override because an ordinary sibling processor cannot gate the built-in exporter. Process-local sampling sees only one worker's spans and is not equivalent to distributed tail sampling. Prefer Collector or backend tail sampling when the decision needs the complete distributed trace.

<a name="deployment"></a>
## Deployment

<a name="per-worker-export"></a>
### Per-Worker Export

Every producing worker owns an independent SDK provider and in-memory aggregation. At each cadence it sends its own bounded OTLP batches. An eight-worker application therefore has eight producers, and a full trace or log queue can produce more than one HTTP request because `max_export_batch_size` limits each request.

This is the standard OpenTelemetry process model. The unique `service.instance.id` lets a backend distinguish producers while queries aggregate service-level metrics as needed. Traces and logs are not multiplied by the worker count: each request, operation, and log record is still produced by only the worker that handled it.

<a name="collectors-relays"></a>
### Collectors and Relays

Send workers directly to a backend when its ingest endpoint is suitable. Use a local Collector, sidecar, host agent, or product relay when you need:

- one upstream connection or larger batches across workers;
- protocol translation or export to several backends;
- durable queues and retry outside application memory;
- authentication or product-specific transport behavior;
- Prometheus ingestion through a Collector's OTLP receiver and Prometheus exporter.

No package instrumentation changes are needed. Point OTLP at the local Collector, or select a custom exporter driver supplied by the agent.

<a name="custom-server-processes"></a>
### Custom Server Processes

Custom Hypervel server processes bind their own telemetry SDK. Coroutine-enabled processes receive the normal export scheduler. Non-coroutine processes drain on shutdown and may call `OpenTelemetry::flush()` at a safe boundary in a long-running loop.

A relay process should exclude itself so it does not recursively export through its own relay:

```php
'server_processes' => [
    'except' => [App\Processes\TelemetryRelay::class],
],
```

`flush()` performs explicit export in the calling context and returns `false` when another flush already owns the provider graph. Do not call it on ordinary request or job paths.

<a name="performance"></a>
### Performance

With `OTEL_SDK_DISABLED=true`, Hypervel builds no signal providers, exporters, scheduler, or framework instrumentation. Configured propagation remains available and stays lazy. A signal set to `none` builds no provider or exporter for that signal. A metric or domain disabled in `instrumentation` registers none of its recording path.

Sampling also reduces package work. Built-in instrumentation checks whether a span records before resolving trace-only query text, headers, URL templates, user context, cache keys, Redis detail, or other optional attributes. Metrics retain only the low-cardinality work they need.

Observable runtime, server, pool, and queue-depth metrics collect from existing state only in the scheduler coroutine. Queue depth is the one optional observable that performs backend I/O. OTLP encoding, compression, retries, and network I/O remain outside application operation coroutines unless application code explicitly calls `flush()` or supplies an extension that violates this contract.

The package keeps bounded SDK queues and coroutine-scoped operation state. It does not store telemetry on disk or in an application database. Monitor queue loss, worker RSS, backend request volume, and dynamic metric dimensions under production-like concurrency when changing queue sizes, intervals, internal metrics, compression, or custom extensions.

The database-pool and messaging metric conventions used by the current OpenTelemetry semantic-conventions package are marked development, while the RPC conventions are release candidate. Their names or attributes may change in a future framework release as the standard stabilizes.
