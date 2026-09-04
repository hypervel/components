# Hypervel OpenTelemetry Package

## Goal

Add an optional, first-party `hypervel/opentelemetry` component that emits standards-based metrics, traces, and logs with Laravel-style configuration and APIs while remaining safe for Hypervel's pre-fork Swoole lifecycle. The package must be useful directly with any OTLP backend, provide stable extension points for applications and third-party packages, and let a private SaaS agent replace only the exporter with an in-instance relay.

All paths in this plan are relative to the components repository. The implementation starts from branch `0.4`.

## Settled decisions

- Use the upstream OpenTelemetry PHP API and SDK. Do not create Hypervel metric, tracer, span-builder, or log-record abstractions.
- Name the Composer component `hypervel/opentelemetry`, keep its directory/config slug `opentelemetry`, and use the `Hypervel\OpenTelemetry` namespace. OpenTelemetry is the project's canonical compound brand name; `open-telemetry` is the upstream Composer organization's chosen vendor slug, not a Laravel multi-word package naming rule to copy into Hypervel.
- Provide one `OpenTelemetry` facade and one `OpenTelemetryManager`. Custom application telemetry uses the standard OTel interfaces returned by the facade.
- Export OTLP over HTTP using protobuf by default. An active built-in OTLP exporter using `http/protobuf` requires `ext-protobuf`; validate that requirement after per-signal protocol precedence during process SDK construction, never on an application or export hot path. JSON is selectable without the extension. An OTLP/gRPC exporter, Prometheus scrape endpoint, Influx line protocol, StatsD output, and Telegraf-specific integration are permanently excluded: a Collector, relay, or backend owns protocol translation and fan-out.
- Aggregate in memory and initiate direct export once per enabled signal cadence from one coordinator coroutine per worker. A trace/log flush may produce multiple bounded OTLP requests when queued records exceed `max_export_batch_size`; cadence means one drain attempt, not a promise of one HTTP request. Do not add generic IPC or a framework server process.
- Allow a private agent to register one custom exporter driver whose signal exporters write to a Hypervel server-process relay. The relay owns IPC, cross-worker batching, authentication, retry, persistence, and SaaS-specific behavior.
- Build all real SDK providers, processors, readers, and exporter transports after fork. Pre-fork code sees rebindable deferred OTel providers and instruments, never SDK objects or sockets.
- Install an OTel context storage backed by Hypervel `CoroutineContext`. Unkeyed `Coroutine::fork()` inherits a snapshot; a keyed fork copies only its explicit allowlist, while `Coroutine::create()` starts at the configured base context. Code that bypasses Hypervel's inheritance API must propagate context explicitly.
- One exporter driver per signal. A Collector handles fan-out. `none` disables a signal and constructs none of its provider/exporter/instrumentation graph.
- Advanced applications may replace one complete SDK provider per signal, supply a container-resolved sampler, and register typed metric views. Provider overrides own their complete signal graph; do not add a second generic provider-builder or mutation API.
- Honor standard `OTEL_SDK_DISABLED`; when true, return no-op signal handles and construct no real SDK provider, exporter, scheduler, or domain instrumentation graph. The OTel specification deliberately leaves propagators active when the SDK is disabled, so still install the configured propagators and Hypervel coroutine-safe Context storage; that storage stays lazy and performs no request-path work unless application/third-party code actually uses OTel Context or propagation.
- Treat `enabled`, text propagators, and response propagators as master-lifecycle settings. Changing them requires a full server restart. Exporter, provider, processor, sampler, view, and instrumentation settings are resolved after fork: event/task workers use the configuration Hypervel rebuilds during `BeforeWorkerStart`, standalone CLI processes use their startup configuration, and custom server processes use the startup configuration inherited from the master. Consistent with Hypervel's documented process lifecycle, any custom-process configuration change requires a full server restart.
- Every built-in metric has its own configuration switch. An off metric has no instrument, callback, event listener used only by that metric, timing, or `record()` call.
- `db.query.text` is enabled by default as a deliberate Laravel-style choice: both reviewed Laravel OTel implementations record the supplied SQL template, and OTel's sanitization guidance says parameterized query text should remain intact because parameters carry the values. The semconv attribute is nevertheless labelled opt-in, so document the choice rather than claiming it is mandatory. Record `QueryExecuted::$sql` as supplied, never bindings or `toRawSql()`. Cap it at 500 Unicode characters by default to bound worker queues, OTLP payloads, and backend storage; `query_text_max_length` accepts a positive integer or `null` for no query-specific cap. Truncate without adding an ellipsis. The SDK's `OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT` still applies. Document that literal values written directly into raw SQL can still be exposed.
- General application-log bridging is opt-in through an `opentelemetry` log channel. Reported exceptions are captured directly when the logs signal and exception instrumentation are enabled.
- Optional user, cache-key, Redis query-text, ordinary-log correlation, Event, View, and Scout integrations use narrow, purpose-specific extension points. They do not justify a global attribute-enricher registry or invasive engine/view decorators.
- Instrument first-party Hypervel gRPC client and server calls as one logical RPC across retries and streaming, through narrow observer-gated lifecycle seams owned by `hypervel/grpc`. This is independent of the intentionally unsupported OTLP/gRPC exporter protocol.
- Do not mutate or reset OTel globals that this package does not own.

## Research incorporated

The design was checked against:

- `examples/opentelemetry-php`: current PHP API, SDK, context, provider, batch processor, metric reader, exemplar, OTLP, and diagnostic behavior.
- `examples/opentelemetry-php-contrib`: Laravel auto-instrumentation, Swoole context adapter, runtime metrics, Monolog bridge, and transport integrations.
- `examples/laravel-opentelemetry`: Laravel event instrumentation, manager/config ergonomics, query recording, HTTP middleware, and application extension APIs.
- `examples/laraotel-opentelemetry-laravel`: header/response-propagation, cache-event detail, watcher, driver, and manual-span ideas; its current default branch was also checked for dependency overlap, lifecycle behavior, privacy, OTel API currency, and test/source consistency rather than treated as a correctness reference.
- `examples/hyperf/hyperf/src/metric`: its dedicated-process/Prometheus architecture and the costs that do not apply to an OTLP-only package.
- Hypervel's HTTP, console, exception, queue, scheduler, database, Redis, pool, object-pool, WebSocket, coroutine, coordinator, testbench, logging, and package-discovery implementations.
- Current OTel semantic conventions for HTTP, databases, messaging, exceptions, resources, and runtime metrics.

The comparison retains the deferred pre-fork graph, direct per-worker OTLP topology, privacy controls, exporter drivers, standard custom-telemetry handles, response propagation, HTTP exclusions, query/Redis/pool/cache/queue/scheduler/console/WebSocket coverage, runtime metrics, exception enrichment, bucket advisories, user/Event/View/Scout integrations, HTTP manual mode, and exhaustive lifecycle tests. Hypervel-specific integrations use public observer/lifecycle APIs rather than reflection, invasive decorators, wildcard listeners, or unsafe raw-value defaults.

The following ideas are intentionally not retained: multiple wrapper facades, bespoke instrument wrappers, exporter arrays, a production memory driver, custom transport retry, OTel internal initializers, a configuration-array views DSL, a broad attribute-enricher plugin system, cache duration spans, raw bindings/Redis arguments/cache keys by default, request-path export/serialization, an OTLP/gRPC exporter, in-process tail sampling, and ownership of external OTel global resets. Trace/span IDs in ordinary Hypervel log context are supported only through an explicit opt-in scope bridge; standard OTel log correlation remains automatic.

## Public API

The facade resolves the singleton manager and exposes boot-time extension plus ordinary standard API access:

```php
use Hypervel\OpenTelemetry\Facades\OpenTelemetry;
use OpenTelemetry\API\Trace\SpanInterface;

$counter = OpenTelemetry::meter('billing')
    ->createCounter('billing.invoices.created', '{invoice}');

$counter->add(1, ['billing.plan' => $plan]);

$result = OpenTelemetry::trace(
    'billing.invoice.create',
    function (SpanInterface $span) use ($invoice) {
        $span->setAttribute('billing.invoice.id', $invoice->id);

        return $this->createInvoice($invoice);
    },
);
```

Methods:

```php
OpenTelemetry::meter(
    string $name = 'hypervel.application',
    ?string $version = null,
    ?string $schemaUrl = null,
    iterable $attributes = [],
): MeterInterface;

OpenTelemetry::tracer(/* same instrumentation-scope arguments */): TracerInterface;
OpenTelemetry::logger(/* same instrumentation-scope arguments */): LoggerInterface;

OpenTelemetry::trace(
    string $name,
    Closure $callback,
    iterable $attributes = [],
): mixed;

OpenTelemetry::flush(): bool;

OpenTelemetry::propagator(): TextMapPropagatorInterface;

/** @param array<string, mixed> $carrier */
OpenTelemetry::inject(array $carrier = [], ?ContextInterface $context = null): array;

/** @param array<string, mixed> $carrier */
OpenTelemetry::extract(array $carrier, ?ContextInterface $context = null): ContextInterface;

// Boot-only; the closure returns one ExporterFactory instance.
OpenTelemetry::extend(string $driver, Closure $factory): void;

// Boot-only; append in registration order.
OpenTelemetry::enrichExceptionsUsing(
    Closure $enricher, // (LogRecordBuilderInterface $record, Throwable $e): void
): void;

// Boot-only; one outgoing HTTP-client resolver, last registration wins.
OpenTelemetry::resolveUrlTemplateUsing(
    Closure $resolver, // (RequestInterface $request): ?string
): void;

// Boot-only; one authenticated-user resolver, last registration wins.
OpenTelemetry::resolveUserUsing(
    Closure $resolver, // (Authenticatable $user): iterable
): void;

// Boot-only; optional cache-key normalization/hashing, last registration wins.
OpenTelemetry::resolveCacheKeyUsing(
    Closure $resolver, // (string $key, ?string $store): ?string
): void;

// Boot-only; replaces the conservative Redis formatter, last registration wins.
OpenTelemetry::resolveRedisQueryTextUsing(
    Closure $resolver, // (string $command, array $parameters, string $connection): ?string
): void;
```

`trace()` starts an INTERNAL span, activates it, and passes `SpanInterface` to the callback. For an ordinary thrown exception it records the exception and ERROR status, associates the exact throwable with the active span context for later exception-log correlation, then detaches, ends, and rethrows. A `CanceledException` is control flow rather than an application error: detach the scope and rethrow the exact instance without recording, association, or ending the span. Advanced callers use the returned standard `TracerInterface` directly.

`resolveUrlTemplateUsing()` applies only to outgoing PSR-7 HTTP-client requests, where the framework cannot infer a stable route template. Server instrumentation reads Hypervel's matched route directly at response completion.

`inject()` and `extract()` are Laravel-style array-carrier conveniences over the configured standard propagator and its upstream `ArrayAccessGetterSetter`; callers that use another carrier type continue to use the upstream propagator with their own getter/setter. Resolver callbacks are worker-lifetime boot configuration. URL/cache/Redis resolvers run only on their enabled recording-trace path; the user resolver may also run for an enabled request-origin exception log when the request span is unsampled. Exceptions from application callbacks are sent to OTel diagnostics independently and do not recurse through the application logger.

Pre-fork-created handles remain valid after worker binding:

```php
final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->counter = OpenTelemetry::meter('acme.billing')
            ->createCounter('acme.billing.charges', '{charge}');
    }
}
```

Third-party packages do not need a Hypervel-specific metric API. They target the standard OTel API through the facade, dependency injection, or `OpenTelemetry\API\Globals`; telemetry becomes active when the real worker SDK is bound.

## Package and repository structure

Create:

```text
src/opentelemetry/
├── LICENSE.md
├── README.md
├── composer.json
├── config/opentelemetry.php
└── src/
    ├── Contracts/
    │   ├── ExporterFactory.php
    │   ├── Instrumentation.php
    │   └── MetricView.php
    ├── Context/
    │   ├── ContextState.php
    │   ├── CoroutineContextStorage.php
    │   ├── GrpcMetadataSetter.php
    │   ├── HeaderBagGetter.php
    │   ├── PsrRequestHeadersSetter.php
    │   ├── ResponseHeadersSetter.php
    │   └── Scope.php
    ├── Deferred/
    │   ├── Logs/...
    │   ├── Metrics/...
    │   └── Trace/...
    ├── Exporters/
    │   ├── ConsoleExporterFactory.php
    │   └── OtlpExporterFactory.php
    ├── Facades/OpenTelemetry.php
    ├── Instrumentation/
    │   ├── AbstractInstrumentation.php
    │   ├── CacheInstrumentation.php
    │   ├── ConsoleInstrumentation.php
    │   ├── DatabaseInstrumentation.php
    │   ├── EventInstrumentation.php
    │   ├── ExceptionInstrumentation.php
    │   ├── GrpcInstrumentation.php
    │   ├── HttpClientInstrumentation.php
    │   ├── HttpServerInstrumentation.php
    │   ├── PoolInstrumentation.php
    │   ├── QueueInstrumentation.php
    │   ├── RedisInstrumentation.php
    │   ├── RuntimeInstrumentation.php
    │   ├── SchedulerInstrumentation.php
    │   ├── ScoutInstrumentation.php
    │   ├── ViewInstrumentation.php
    │   └── WebSocketInstrumentation.php
    ├── Logging/
    │   ├── LogChannel.php
    │   └── OpenTelemetryHandler.php
    ├── Support/
    │   ├── ConsoleTelemetryState.php
    │   ├── ExceptionContext.php
    │   ├── ExceptionContextRegistry.php
    │   ├── GrpcTelemetryState.php
    │   ├── InstrumentationOptions.php
    │   ├── LogContextScope.php
    │   ├── OperationOrigin.php
    │   ├── QueryOperation.php
    │   ├── QueueConsumerState.php
    │   ├── QueueProducerState.php
    │   ├── QueueProducerStateStore.php
    │   ├── RedisQueryTextFormatter.php
    │   ├── RequestTelemetryState.php
    │   ├── ScoutTelemetryState.php
    │   ├── ViewTelemetryState.php
    │   └── WebSocketTelemetryState.php
    ├── OpenTelemetryManager.php
    └── OpenTelemetryServiceProvider.php
```

The exact deferred file split should follow the OTel interfaces rather than forcing one class per file when a private implementation is tiny. Do not collapse unrelated responsibilities into the manager, but do not create interfaces that have only one internal consumer.

Update root `composer.json`:

- Add the `Hypervel\OpenTelemetry\` PSR-4 path.
- Add `hypervel/opentelemetry` to `replace` at `self.version`.
- Add provider discovery for `OpenTelemetryServiceProvider` and alias `OpenTelemetry` to the facade.
- Require compatible current packages: `open-telemetry/api:^1.10`, `open-telemetry/context:^1.5`, `open-telemetry/sdk:^1.15`, `open-telemetry/exporter-otlp:^1.4`, and `open-telemetry/sem-conv:^1.38`. The component directly implements context-storage interfaces, so it must not rely on the API package's transitive context dependency. Re-check the resolved versions and generated semconv constants after the root Composer update. The Hypervel resource uses the exact schema enum case used by the installed SDK detectors (`Version::VERSION_1_38_0->url()` in the researched release), not the newest available semconv case; SDK constraints allow semconv to advance before the detectors do. A test asserts that the final schema is non-null and equals `ResourceInfoFactory::defaultResource()->getSchemaUrl()`, forcing a deliberate update when the SDK detectors advance.
- Add `"tbachert/spi": true` to Composer `allow-plugins` (required by the SDK).
- Keep `ext-protobuf` as a package suggestion rather than a global requirement because JSON, console, `none`, custom exporter drivers, and complete provider overrides do not consume built-in protobuf export. The built-in exporter fails during process SDK construction when a final per-signal protocol is `http/protobuf` and the extension is unavailable; it never falls back to the portable encoder.

The component `composer.json` declares PHP `^8.4`, `ext-mbstring` for the promised Unicode-safe detail caps, OTel packages, Guzzle 7 for the explicit coroutine-safe OTLP/HTTP client and TLS options, Monolog 3, PSR interfaces, Symfony HTTP foundation, and the Hypervel components used by its unconditional integrations. Include `hypervel/server-process` as a direct requirement because the provider directly consumes its process-lifecycle events; do not rely on Foundation/Server's transitive dependency. Declare optional `hypervel/grpc` and `hypervel/scout` under `suggest`, not `require`: their instrumentation classes have only unconditional package dependencies in their constructors, then check that the optional package's public runner and operation types exist before resolving the runner or registering an observer. A missing optional package must make the normalizer skip that built-in cleanly even when its shipped config entry is present; it must not trigger autoload or container errors during boot. The package already requires Foundation for its worker, CLI, and exception lifecycles; Foundation requires View and WebSocket Server, so those integrations do not need a second optional-dependency mechanism. Discover the provider and facade and use `0.4-dev` as the `dev-main` branch alias. Run Composer update from the root; do not commit the monorepo lock file under the repository's existing policy.

## Configuration

Use standard `OTEL_*` variables for every setting the installed PHP SDK/package can actually honor, with Laravel-style arrays for framework-specific choices. Do not publish a knob that the runtime silently ignores:

```php
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
    // The OTel specification gives OTEL_SERVICE_NAME precedence over
    // service.name from OTEL_RESOURCE_ATTRIBUTES.
    $resourceAttributes['service.name'] = Configuration::getString(Variables::OTEL_SERVICE_NAME);
}

return [
    'enabled' => ! Configuration::getBoolean(Variables::OTEL_SDK_DISABLED, false),
    'internal_metrics' => Configuration::getBoolean(Variables::OTEL_PHP_INTERNAL_METRICS_ENABLED, false),

    'service' => [
        'name' => env('APP_NAME', 'hypervel'),
        'version' => env('APP_VERSION'),
        'environment' => env('APP_ENV'),
        'instance_id' => null, // optional base; a worker/PID suffix is always added
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
        'except' => [], // process class names that must not bind/export telemetry
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
            // Each nullable signal override falls back to the shared value above.
            'traces_endpoint' => $optionalString(Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT),
            'traces_protocol' => $optionalEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_PROTOCOL),
            'traces_headers' => $optionalMap(Variables::OTEL_EXPORTER_OTLP_TRACES_HEADERS),
            'traces_compression' => $optionalEnum(Variables::OTEL_EXPORTER_OTLP_TRACES_COMPRESSION),
            'traces_timeout' => $optionalInt(Variables::OTEL_EXPORTER_OTLP_TRACES_TIMEOUT),
            'traces_certificate' => $optionalString(Variables::OTEL_EXPORTER_OTLP_TRACES_CERTIFICATE),
            'traces_client_certificate' => $optionalString('OTEL_EXPORTER_OTLP_TRACES_CLIENT_CERTIFICATE'),
            'traces_client_key' => $optionalString('OTEL_EXPORTER_OTLP_TRACES_CLIENT_KEY'),
            // `metrics_*` and `logs_*` expose the same eight standard overrides.
            'max_retries' => 3, // upstream transport retry limit; no second retry layer
        ],
        'console' => ['driver' => 'console'],
    ],

    'instrumentation' => [
        DatabaseInstrumentation::class => [
            'traces' => true,
            'query_text' => true,
            'query_text_max_length' => 500, // null removes only this query-specific cap
            'metrics' => [
                'db.client.operation.duration' => true,
            ],
        ],
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
            // Header/query options match the server privacy controls.
            'metrics' => [
                'http.client.request.duration' => true,
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
        RedisInstrumentation::class => [
            'traces' => true,
            'query_text' => false,
            'query_text_max_length' => 500,
            'metrics' => [
                'db.client.operation.duration' => true,
            ],
        ],
        EventInstrumentation::class => [
            'events' => [],
        ],
        ConsoleInstrumentation::class => [
            'traces' => true,
            'commands' => ['*'],
            'except' => [],
            'metrics' => [
                'hypervel.console.command.duration' => true,
            ],
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
        // Remaining built-ins follow the same traces/logs/per-name metrics shape.
    ],

];
```

Rules:

- The published config contains every built-in metric by its final wire name. No hidden all-or-nothing metric switch.
- `internal_metrics` is the upstream PHP SDK self-observability switch, not a hidden Hypervel metric group. It is default-off and intentionally enables the SDK-owned incubating batch-processor/exporter instruments as one upstream-defined set; applications that need per-instrument control use metric views or a complete provider override. Enabling it also records upstream span-start/live counters on the application path, including for spans rejected by the sampler, so it is an explicit queue-loss/tuning observability tradeoff rather than a zero-cost default. It may remain enabled where that operational visibility justifies measured overhead, but should not be enabled blindly at extreme scale.
- Read every standard `OTEL_*` variable exposed by the published config through the upstream SDK `Configuration` resolver. Choose the getter from the installed `ValueTypes` declaration when present, otherwise from the standard's declared type; use a `Variables` constant when present and the literal standard name when the current SDK has not yet added one (notably client-certificate/key settings). This honors SDK SPI resolvers, the environment, and php.ini and applies standard list/map/boolean parsing when the config file is evaluated. Like `env()`, `config:cache` freezes those resolved values in the generated cache until it is rebuilt; it does not re-run the SDK resolver in each production process. Only Hypervel-owned `APP_*` fallbacks use `env()`. Guard nullable per-signal overrides with `Configuration::has()` so an unset value remains `null`; explicit defaults stay visible in the config. Re-check every getter against the resolved SDK's `ValueTypes` and the current standard during implementation. Getter/type mismatches, invalid integers or malformed maps, and unexpected empty required values fail during config loading; the SDK's boolean parser retains its specified diagnostic-and-false behavior.
- Resolve the standard `OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS` literal with `Configuration::getList()` because the installed SDK has no `Variables` constant or `ValueTypes` entry for it. The list is a case-sensitive complete replacement, not an addition. Keep one owning default-list constant on `ConfigurationNormalizer`, retain the readable literal in the published config, and test that they match. Validate but never case-fold configured values; each enabled HTTP instrumentation builds its exact-match lookup set once during registration.
- Parse the environment-backed SDK switch once in the config file through the upstream OTel `Configuration::getBoolean()` parser: only case-insensitive `true` disables the SDK; invalid values such as `1`, `yes`, `on`, and Laravel's `(true)` literal produce the upstream diagnostic and remain enabled. The manager consumes `enabled` without reparsing it. When false, keep signal access on upstream no-op providers and skip worker/CLI/custom-process signal binding, exporters, processors/readers, domain instrumentation, and timers. Still install the configured text/response propagators in the base context and the lazy Hypervel Context storage because standard `OTEL_SDK_DISABLED` does not disable propagation. Still register the named Monolog driver so an application can toggle the SDK off without breaking an already-configured logging stack; its handler returns immediately, performs no OTel log conversion, and continues bubbling so it cannot swallow later handlers in a Laravel-style stack. Boot-time callers receive safe no-op signal handles, and an application that does not explicitly use OTel Context or propagation pays no coroutine-path work from the disabled package.
- A class entry may be `false` to disable everything or `true` to use defaults. Its `metrics` value remains a boolean in normalized options: `true` means every metric queried by that instrumentation is enabled and `false` means all are disabled. A per-name map overrides individual metrics. The normalizer may materialize built-in names as an internal convenience, but must not require an instrumentation class to declare or enumerate its metric names; `AbstractInstrumentation::metricEnabled(string $name)` therefore supports both boolean and map forms for third-party classes.
- After Hypervel's outer config merge, the normalizer fills fixed defaults for service/signal settings, each named exporter, and each built-in instrumentation class. Setting one database option or adding one processor list must not silently remove unrelated required defaults. Numeric processor lists replace rather than append, so ordering remains explicit.
- Normalize and validate once after the event/task worker configuration reload, during CLI binding, or in a custom server-process child before its handle runs, into immutable `InstrumentationOptions`; never parse config on an observation path.
- Normalize cadence, queue-size, batch-size, and sampler-argument types regardless of whether their values are consumed, so malformed configuration fails consistently and the normalized array has stable shapes. Apply value, enum-membership, cross-field, exporter-definition, and container-contract validation only when the setting is consumed. In particular, validate every cadence used by an active signal as a strictly positive integer before creating a timer, including the metric export interval and trace/log schedule delays for custom providers. A non-positive value would be clamped by `Timer::tick()` to a one-microsecond loop. A disabled signal may retain a zero or negative integer because no timer consumes it. Queue/batch positivity and relationships, sampler-argument range, exemplar membership, exporter definition, and view/processor/provider contracts are ignored when their owning package-built graph is not selected; their documented scalar/list shapes still apply.
- Signal exporter `none` is the sole per-signal disable mechanism. It wins over a configured provider override, which is then not resolved. Do not add separate `metrics.enabled`, `traces.enabled`, or `logs.enabled` flags. Normalize every instrumentation record structurally before signal gating, then force any declared `traces`, `logs`, or `metrics` output off with its inactive signal. `propagation` remains independent of tracing. When all three package signals are inactive, disable every instrumentation after structural validation, including propagation-only entries. The recognized output set for third-party records is deliberately limited to `traces`, `logs`, `metrics`, and `propagation`; `manual`, `user_context`, `url_query`, `query_text`, `key`, `message`, `stack_trace`, and `server_metric_address` only modify an output. A third-party record that declares no recognized output remains active because the package cannot infer its purpose; one that declares recognized outputs remains active only when at least one survives. Built-ins retain their known cross-signal rules: Event needs traces and a non-empty allowlist, HTTP may remain for logs plus `user_context`, Queue may remain for propagation, Exception needs logs or its metric, and Pool/Runtime need a metric.
- `resource_attributes`, `exporters`, and the class-keyed `instrumentation` map are mergeable so applications and third-party providers can add resource metadata, exporter configurations, and instrumentation services without replacing defaults. `resource_attributes` is an associative map in the resolved config; the published file parses the standard environment string through the SDK `Configuration::getMap()`, which delegates to `MapParser` and preserves standard empty/invalid-value behavior.
- Processor class strings, custom sampler class strings, provider overrides, and metric-view class strings are container-resolved once after fork and must implement their relevant upstream SDK or package contract. Provider overrides specifically implement `OpenTelemetry\SDK\Trace\TracerProviderInterface`, `OpenTelemetry\SDK\Metrics\MeterProviderInterface`, or `OpenTelemetry\SDK\Logs\LoggerProviderInterface`, not only the corresponding API provider interface, because the package lifecycle must call their standard `forceFlush()` and `shutdown()` methods. Do not add closure-based provider mutation or a configuration-array metrics views DSL.
- Configured trace/log processors run in listed order before the package batch processor, which is always last. That preserves deterministic Laravel-config order and lets a log processor enrich a mutable record before it is queued. `traces.processors` remain siblings of the package's batch processor: they may observe spans but cannot prevent that batch processor from exporting them. A worker-local tail sampler that gates export must own/decorate the downstream processor through a complete `traces.provider` override; do not imply that adding it to `traces.processors` suppresses package export.
- A non-null signal `provider` replaces the package-built provider for that complete signal. The provider is resolved post-fork, is responsible for its own resource, exporters/processors/readers/sampler/views/limits, and receives the same scheduler/manual `forceFlush()` and `shutdown()` lifecycle. Normal resource/exporter, processor, queue/batch-size, sampler, exemplar, temporality, and view settings for that signal are ignored and documented as such; do not apply semantic validation to unused values or require an unused named exporter record, though their documented scalar/list shapes remain valid. In particular, a direct-export custom provider must supply a unique per-worker `service.instance.id` itself. The signal's `schedule_delay` / `export_interval` remains active because it owns the package coordinator's provider flush cadence even for a custom provider.
- Resolve every package-owned setting and container collaborator consumed by one signal before creating that signal's exporter. For package-built metrics, resolve views and map the validated exemplar filter first. The built-in OTLP metric factory validates its own temporality vocabulary before constructing a transport; custom exporter drivers retain ownership of their temporality vocabulary. Trace samplers/processors and log processors already precede their exporters. Do not add transactional rollback: package-built partial providers become unreferenced when construction fails, while container-resolved overrides/processors retain their normal container lifetime. Provider `shutdown()` is terminal, and metrics shutdown performs a final collection/export, so it is not a safe rollback primitive. A custom exporter factory owns any resource it allocates before throwing.
- When `internal_metrics` is true, package-built trace/log providers and batch processors register their SDK-owned instruments on the active metrics provider, including an application-supplied `metrics.provider`. That provider override still owns the metrics resource, readers, views, export, and lifecycle; enabling self-observability explicitly permits these additional upstream instruments on it. Package code does not otherwise mutate its graph.
- A custom `traces.sampler` class string is container-resolved as `SamplerInterface`. Standard sampler names retain their normal `sampler_arg` behavior; a custom sampler ignores `sampler_arg`.
- Normalize `OTEL_METRICS_EXEMPLAR_FILTER` with the current standard values `trace_based`, `always_on`, and `always_off`, defaulting to `trace_based`, and map them to the installed SDK's `WithSampledTraceExemplarFilter`, `AllExemplarFilter`, and `NoneExemplarFilter` classes. Do not expose the PHP SDK's older `with_sampled_trace`/`all`/`none` spellings as the package's public configuration merely because those class names and legacy environment mappings remain in the researched release.
- Each `metrics.views` class implements `MetricView`, returning an upstream `SelectionCriteriaInterface` and `ViewTemplate`. Register them into `CriteriaViewRegistry` and construct `MeterProvider` directly because the researched SDK builder exposes no view-registration method and builds an empty registry internally.
- Parse OTel map strings, including shared/per-signal exporter headers, through `Configuration::getMap()`/the SDK `MapParser`, not ad hoc string splitting. The normalizer may also accept an already-resolved associative map from application config.
- The config file obtains `sampler_arg` through the SDK's typed float getter. Validate it in the closed interval `[0.0, 1.0]` when `traceidratio` or `parentbased_traceidratio` is selected; other standard samplers and custom sampler classes do not consume it.
- Do not invent variables in the reserved `OTEL_*` namespace. There is no standard `OTEL_SERVICE_VERSION`, `OTEL_SERVICE_INSTANCE_ID`, or `OTEL_DEPLOYMENT_ENVIRONMENT`; application defaults live in the Laravel-style service array, while standard overrides may use `OTEL_RESOURCE_ATTRIBUTES` (`service.version`, `service.instance.id`, `deployment.environment.name`). Treat a resource `service.instance.id` as the base identity and append the required worker/PID suffix rather than letting it collapse multiple producers onto one ID.
- Resolve the response-propagator list already parsed by `Configuration::getList()` through the OTel registry into `NoopResponsePropagator`, one implementation, or `MultiResponsePropagator`, matching the SDK factory's unknown-name behavior: emit an OTel diagnostic and substitute a no-op. It is an experimental OTel surface, defaults to `none`, and must not be presented as stable Hypervel protocol. Installed propagation packages may register the upstream `servertiming` or `traceresponse` names without a Hypervel integration.
- Build text and response propagators once while installing the package base context in the master, including when `OTEL_SDK_DISABLED=true`. They are ordinary immutable objects, not deferred delegates. Document that changing `enabled`, `propagators`, or `response_propagators` requires a full server restart; do not add switchable context storage or deferred propagators solely to make a worker reload change process-global context behavior.
- Keep the standard exporter variables as the lists returned by `Configuration::getList()` and require exactly one exporter per signal, matching the PHP SDK's own exporter factories; the normalizer may additionally accept a single Laravel-config string. Reject fan-out with a Collector guidance error.
- Honor the standard per-signal OTLP endpoint/header/protocol/compression/timeout/certificate/client-certificate/client-key variables over shared values. The published config exposes the complete traces/metrics/logs matrix; the abbreviated block above shows all eight trace keys and requires identical metric/log keys. Signal-specific HTTP endpoints are used verbatim, while shared endpoints use upstream `HttpEndpointResolver` to append `/v1/traces`, `/v1/metrics`, or `/v1/logs` correctly.
- Validate TLS paths and the client-certificate/client-key pair during worker/CLI boot without reading certificate contents in the master. An `https` endpoint with no custom files uses the system trust store. The researched PHP `PsrTransportFactory` accepts certificate arguments but does not apply them to its discovered client, so explicitly construct its Guzzle PSR-18 client post-fork with `verify`, `cert`, and `ssl_key` options. Instantiate one Guzzle `HttpFactory`, pass it as both request and stream factory to `PsrTransportFactory`, and pass it as the URI factory to `MessageFactoryResolver::create()` for `HttpEndpointResolver`; do not fall back to PSR discovery. Retain the upstream PSR transport, serializers, retry policy, and exporters rather than implementing a second HTTP transport or TLS layer. Re-check the installed upstream code during implementation; if it still ignores these arguments, report the verified dependency defect to the project owner before retaining the explicit wiring. Opening an external issue or pull request requires separate approval.
- Do not expose no-op batch/metric export-timeout settings or the default-histogram-aggregation setting in this release. The researched PHP batch span/log processors accept `OTEL_BSP_EXPORT_TIMEOUT` / `OTEL_BLRP_EXPORT_TIMEOUT` constructor values but only validate them and never use them to bound an export; `ExportingReader` / `MeterProviderInterface` has no timeout/cancellation input; and the SDK contains no base-2 exponential histogram aggregation implementation. The package therefore cannot truthfully honor those variables or `OTEL_METRIC_EXPORT_TIMEOUT` / `OTEL_EXPORTER_OTLP_METRICS_DEFAULT_HISTOGRAM_AGGREGATION`. The effective export bound is the OTLP per-attempt timeout plus retry behavior. An application that needs another behavior supplies a complete provider override. Document these limitations and re-check all four standard settings whenever the OTel dependency is upgraded rather than retaining permanent omissions.
- Keep the upstream OTLP compression default of `none`; gzip remains a standard opt-in for bandwidth-sensitive direct export. Do not spend PHP worker CPU by default merely because a backend may be remote.
- Do not duplicate SDK span/log attribute-count/value-length/event/link limits or `OTEL_PHP_DETECTORS` in package config. The resolved SDK limit builders read their signal-specific `OTEL_SPAN_*`, `OTEL_EVENT_*`, `OTEL_LINK_*`, and `OTEL_LOGRECORD_*` variables directly, and `ResourceInfoFactory::defaultResource()` resolves its detector list, when package-built providers/resources are constructed after fork. Unlike variables evaluated by the published config file, these direct SDK reads are not frozen into Hypervel's config cache: event/task workers see the environment reloaded before binding, while custom processes retain startup environment as usual. The final cached `resource_attributes` map, including `OTEL_SERVICE_NAME` precedence resolved by the config file, merges over detector output so the two paths cannot produce conflicting final service values. The current limit builders do not fall back to generic `OTEL_ATTRIBUTE_COUNT_LIMIT` or `OTEL_ATTRIBUTE_VALUE_LENGTH_LIMIT`, so the docs must not claim that they do. Query text retains a targeted, independently configurable cap because SQL is commonly much larger than ordinary span attributes; the query cap and the SDK's span-specific limit both apply.
- Do not enable `OTEL_PHP_AUTOLOAD_ENABLED`; this package performs explicit setup.

The service provider calls `mergeConfigFrom()`, returns `['resource_attributes', 'exporters', 'instrumentation']` from `mergeableOptions('opentelemetry')`, publishes the config, registers the facade alias/component metadata, and registers the deferred `opentelemetry` log driver factory. Those operations are pre-fork safe; resolving an OTel log record remains deferred until a worker or CLI SDK is bound.

Non-metric built-in defaults are explicit in the published file:

| Instrumentation | Defaults |
| --- | --- |
| HTTP server/client | traces on; standard known-method list; client automatic mode; server path/method `except` lists empty; user context and URL query capture off; request/response header allowlists empty; built-in sensitive header/query names always excluded or redacted |
| Database | traces on; query text on; query-specific limit 500; bindings/interpolation never captured |
| Redis | traces on; query text off; conservative formatter and 500-character cap when opted in; arbitrary raw arguments never captured |
| Cache | active-span events on; raw key capture off; 500-character cap when opted in |
| Queue | propagation, one persistent asynchronous send span, and process spans on; built-in background/deferred connections carry flat propagation without producer telemetry; sync connections use ambient context; `depth_queues` empty |
| gRPC | one logical client/server span across retries and streaming; `rpc.client.call.duration` and `rpc.server.call.duration` on when the gRPC component is installed; message bodies excluded |
| Custom server processes | bind/export on unless the process class is listed in `server_processes.except`; no process-operation span is invented |
| Event | empty exact allowlist, so no observer is registered |
| View | traces and `hypervel.view.render.duration` on when the View component is installed; data, rendered output, and filesystem path excluded |
| Scout | traces and `db.client.operation.duration` on when Scout is installed; query strings, pagination values, and model IDs excluded |
| Scheduler, console, WebSocket | traces on; console includes all commands unless filtered; raw command arguments and message bodies off |
| Exceptions | direct error log on; message and stack trace on but independently configurable; ordered enrichers enabled only on the exception path |
| Runtime/server/pools | observable metrics only; no polling outside metric collection |

When both exception message and stack trace are enabled, use `LogRecordBuilderInterface::setException()`. If either privacy switch is off, set only the permitted standard exception attributes manually while always retaining `exception.type`. Header lists and other strings are normalized once; invalid types and non-positive query/cache-key/Redis length limits fail at boot. Every targeted maximum length accepts only a positive integer or `null`.

Every class-keyed entry resolves through the container and implements one small boot-time contract:

```php
interface Instrumentation
{
    /** @param array<string, mixed> $options */
    public function register(array $options): void;
}
```

The metric-view contract remains a typed pass-through to the upstream SDK:

```php
interface MetricView
{
    public function criteria(): SelectionCriteriaInterface;

    public function template(): ViewTemplate;
}
```

`AbstractInstrumentation` is optional and implements a final public `register()` that stores the already-normalized options, then calls one protected registration method. It supplies only `tracesEnabled()` and `metricEnabled(string $name)` checks. It has no manager/service locator, dispatcher wrapper, span registry, instrument factory, or attribute pipeline; dependencies remain constructor-injected. Built-ins use it where useful, while third parties may implement `Instrumentation` directly.

Built-ins store normalized flags/instruments during `register()`. A third-party package adds its class and config under `opentelemetry.instrumentation` and uses standard OTel interfaces internally. An instrumentation class whose entry is replaced by `false`, has an empty required allowlist, or has every output off is never container-resolved or registers nothing, as appropriate. No runtime class scan, auto-discovery SPI, or general attribute-mutation registry is needed.

## Exporter extension contract

Keep one narrow contract:

```php
interface ExporterFactory
{
    public function spanExporter(array $config): SpanExporterInterface;

    public function metricExporter(array $config): MetricExporterInterface;

    public function logExporter(array $config): LogRecordExporterInterface;
}
```

`OpenTelemetry::extend('nightwatch', fn (Application $app) => $app->make(NightwatchExporterFactory::class))` registers a boot-only creator. The manager selects the signal's configured exporter name, obtains its `driver`, resolves the factory, and invokes only the relevant typed method.

Built-ins:

- `otlp`: use the upstream HTTP endpoint resolver, `PsrTransportFactory`/transport, protobuf/JSON serializers, compression, retry behavior, and signal exporter classes. Inject an explicitly configured Guzzle PSR-18 client so Hypervel's coroutine-safe client choice and standard CA/mTLS files are deterministic. Reuse one post-fork client for signals with the same normalized timeout/TLS tuple so the handler/connection pool is not multiplied unnecessarily; incompatible tuples get separate clients. Do not add a second retry implementation.
- `console`: use SDK console exporter factories for local development.
- `none`: manager sentinel; build a no-op delegate and no exporter/factory/provider graph for that signal.

Tests register a fixture memory factory through `extend()`. Do not expose or document `memory` as a production driver.

Validate the final per-signal protocol at built-in OTLP exporter construction, after shared/per-signal precedence. Accepted protocols are `http/protobuf` and `http/json`; reject `grpc` with a signal-qualified error, and reject `http/protobuf` without `ext-protobuf` after the remaining signal settings have been validated so configuration errors stay deterministic. The availability check runs once per active built-in OTLP signal during process SDK construction and adds no application/export hot-path branch. The explicit client is Guzzle's PSR-18 implementation. Hypervel's server and ordinary command coroutine runtimes default to `SWOOLE_HOOK_ALL`, which includes native-cURL, TCP, and sleep hooks, so transport sends and retry waits yield from the scheduler coroutine. Prove that assumption with a slow-endpoint process test using JSON so extension-independent CI exercises the transport/yielding contract; a separate extension-gated engine test exports a real span through native protobuf to a valid empty protobuf response. Do not infer non-blocking behavior merely from the existence of a coroutine.

The maintained CI image installs the official PECL protobuf extension from a pinned `5.36.1` build input, accepts that version or newer, and verifies that it loads in both supported PHP images. The unit workflow runs one extension-gated positive factory test for all three signal exporters, while the engine workflow runs the native encoding/export test; the existing `php -n` child retains extension-free negative and precedence coverage. Only those two native-positive tests skip on a developer machine without the extension. CI images are published only from `0.4` when the Dockerfile or image workflow changes, so a branch that changes both an image and extension-gated tests still runs against the previously published image; build one maintained image directly before review, while the image workflow validates the complete PHP matrix when it publishes the artifact.

Expose the upstream HTTP transport's non-negative `max_retries` as an exporter config value, defaulting to its upstream value of three. This only configures the existing transport retry loop; the package and private relay must not wrap it in another retry layer.

The private agent's factory returns non-blocking, bounded IPC exporters. Enrichment happens in the originating worker before serialization. The relay may aggregate workers into one upstream connection but must not require changes to instrumentation or the public package.

## Context and pre-fork correctness

### Base OTel context

During provider registration:

1. Resolve the configured text and response propagators. When the SDK is enabled, construct deferred `TracerProviderInterface`, `MeterProviderInterface`, and `LoggerProviderInterface` implementations; when it is disabled, use upstream no-op providers and construct no deferred graph.
2. Build the package base context with the public API in either mode:

   ```php
   $baseContext = Configurator::create()
       ->withTracerProvider($deferredTracerProvider)
       ->withMeterProvider($deferredMeterProvider)
       ->withLoggerProvider($deferredLoggerProvider)
       ->withPropagator($propagator)
       ->withResponsePropagator($responsePropagator)
       ->storeInContext(Context::getRoot());

   Context::setStorage(new CoroutineContextStorage($baseContext));
   ```

`ContextInterface` is immutable, so retaining `storeInContext()`'s return is required. The storage constructor receives that exact base context; do not call `storeInContext()` for side effects that do not exist. Installing storage while the SDK is disabled preserves the specification's propagation behavior and coroutine safety; because `CoroutineContextStorage` creates coroutine-local state only on attachment/use, installation alone does not add per-request state.

Do not call `Globals::registerInitializer()`; it is internal and marked for removal. Standard `Globals::*` access still sees providers stored in the current base context.

### Storage semantics

`ContextState` implements `ReplicableContext` and contains an immutable inherited base context plus a small doubly linked stack of scopes attached in that execution unit. Replication snapshots only `current()` as the child's new base and starts the child with an empty local scope stack; do not clone every parent node. This matches OTel execution-context inheritance, isolates later parent detaches, and keeps `Coroutine::fork()` O(1). The returned scope implements `ContextStorageScopeInterface`, which already extends `ScopeInterface`. State-object identity is sufficient to distinguish coroutine and fallback execution units; do not retain a redundant execution ID, fallback execution ID, or scope constructor discriminator. Normal operations must be O(1):

- `current()`: top of current coroutine stack or configured base context.
- `attach($context)`: push and return a scope bound to its state and storage node.
- `scope()`: return the active `ContextStorageScopeInterface`, or `null` when no local scope is attached.
- scope `context()`: return the exact context attached by that scope. Its `ArrayAccess` methods provide per-scope local storage with the same semantics as the upstream scope contract; they are not shared between nodes or inherited into a replicated child stack.
- scope `detach()`: remove only the node it owns, including when a terminal lifecycle event occurs in another coroutine; return the contract flags `INACTIVE`, `DETACHED`, or `MISMATCH | $depth` for stale/double/out-of-order detach without corrupting newer context.
- `fork($id)`, `switch($id)`, and `destroy($id)`: faithfully satisfy the upstream `ExecutionContextAwareInterface` contract with the same small process-local fallback-state map as upstream storage. Hypervel coroutines use `CoroutineContext` and `ReplicableContext::replicate()` directly; never scan `Coroutine::list()` or maintain a second per-coroutine registry.

Use only public `ContextStorageInterface` and `ExecutionContextAwareInterface` APIs. Never depend on upstream `ContextStorageNode`/`ContextStorageHead` internals.

Expected inheritance:

- unkeyed `Coroutine::fork()`: current-context snapshot as an independent base, with no copied parent scope nodes. A caller-provided `copyContext` allowlist copies only those named Hypervel context keys; it does not implicitly include OTel state.
- `Coroutine::create()`: base context, no request span.
- ordinary non-coroutine CLI: process-local stack.

Only Hypervel's unkeyed `Coroutine::fork()` API promises inheritance. Code that starts execution through another mechanism must propagate a `ContextInterface` explicitly; do not add runtime-specific compatibility layers to this Hypervel package.

### Deferred graph

Rebinding must cover the entire object graph, not only providers:

- tracer provider -> tracer;
- meter provider -> meter -> synchronous instruments and observable instruments/callbacks;
- logger provider -> logger.

Long-lived provider, tracer, meter, logger, and metric-instrument handles created before fork retain immutable descriptors and resolve the current real delegate when used. Materialize instrumentation-scope iterable attributes once so generator input remains replayable after binding. Do not build rebindable span-builder or log-record-builder implementations: those are mutable, operation-local objects and should be requested from the deferred tracer/logger at the point of use after worker binding. A builder requested before binding is an ordinary upstream no-op builder and is not revived later. Recording/emitting before worker binding is a no-op and is never buffered across fork. Rebinding/unbinding is idempotent and cannot retain a previous worker SDK in tests.

Deferred observables preserve the complete upstream callback contract. `createObservable*()` returns the deferred instrument and permanently associates callbacks supplied during creation with that instrument. Calling the instrument's `observe()` or the meter's `batchObserve()` returns a detachable `ObservableCallbackInterface` token. Detaching before bind prevents registration; detaching after bind detaches the current delegate token; unbind/rebind detaches every old delegate token before replaying only active registrations. Bound-object callbacks use weak target ownership matching the SDK, so ignoring the token does not keep their object alive; static/unbound callbacks retain the upstream manual-detach lifetime. `batchObserve()` maps same-meter deferred instruments to current delegates and follows upstream diagnostic/dummy-instrument behavior for foreign instruments rather than inventing a stricter exception. A detached or collected callback never resurrects or leaks after rebind. Implement this through package-owned public-PHP primitives; do not call the SDK's internal callback helpers.

## Worker SDK and export scheduler

Bind an event/task worker from `AfterWorkerStart`, after Hypervel has rebuilt worker configuration and completed pre-fork setup. If binding commits the provider graph and later instrumentation registration throws, start the worker scheduler in `finally` whenever the manager became bound so the original failure escapes without orphaning shutdown ownership. Bind a standalone CLI process only after every provider has booted: register an `ArtisanStarting` listener at that boundary, then bind immediately when the Console Application contract was already resolved or wait for its later construction. This captures early Artisan construction without binding before later providers contribute configuration or extensions, remains independent of the Symfony console-event switch used by tests, and leaves console-mode application boots that never construct Artisan on deferred no-op providers. Bind custom server-process children from `BeforeProcessHandle` using the startup configuration they inherit from the master; Hypervel deliberately requires a full restart for custom-process configuration changes. The package provider registers only those small lifecycle entry points, CLI command-scheduler listeners, and server-process `AfterProcessHandle` in the master. Signal/domain listeners, middleware, hooks, instruments, and callbacks are selected in the producing process so the configuration authoritative for that lifecycle is used:

1. Normalize final worker config and resource attributes.
2. For each signal whose exporter is not `none`, resolve its provider override when configured. For every remaining signal, lazily create only that signal's transport/exporter graph and build the shared `ResourceInfoFactory::defaultResource()` merge only if a package-built provider needs it.
3. Build enabled package-owned providers in dependency order:
   - establish the active metrics provider first: use the already-resolved override, or build the package `MeterProvider` directly with `ExportingReader`, the configured exemplar filter, and a `CriteriaViewRegistry` populated from typed `MetricView` services. Supply the same clock, attribute factory, instrumentation-scope factory, staleness handler, and configurator defaults as the upstream builder so adding views changes only view registration;
   - select that active provider as the internal meter provider only when `internal_metrics` is true; if metrics is `none`, there is no internal meter provider and no internal metrics;
   - build the package `TracerProvider` with the configured standard/container sampler, custom processors, and `BatchSpanProcessor(autoFlush: false)`, passing the internal meter provider to both trace components when present;
   - build the package `LoggerProvider` with custom processors and `BatchLogRecordProcessor(autoFlush: false)`, likewise passing the internal meter provider when present.
4. Bind deferred delegates.
5. Normalize the final instrumentation map, resolve/register only active instrumentation classes, and create only enabled instruments/callbacks.
6. Start one `Timer::tick()` when at least one enabled provider has a due signal.

Filter registrations by execution environment as well as config: HTTP-server and WebSocket server listeners belong only in event workers, and shared server/OPcache metrics only in event worker zero. Command-span listeners remain available in server workers because application code can call Artisan inline there, but standalone CLI binding/scheduler ownership is gated by manager mode. Database, Redis, cache, queue, HTTP-client, gRPC client, log, exception, and per-process runtime instrumentation remain available in every worker/process type where application code may use them; gRPC server observation registers only where its server package is available. The `enabled === false` worker path exits before step 1 because its no-op providers, propagators, and Context storage were fixed during master registration.

Resource requirements:

- Standard `service.name`, `service.version`, `deployment.environment.name`, and user `OTEL_RESOURCE_ATTRIBUTES`. Explicit resource attributes override application-derived name/version/environment defaults, while the published config folds a non-empty `OTEL_SERVICE_NAME` into the parsed resource map last so the standard-required `OTEL_SERVICE_NAME`-over-`service.name` precedence is preserved through config caching. Apply framework-derived `hypervel.worker.*` / `hypervel.process.*` identity after explicit attributes so application configuration cannot spoof the producing process.
- Unique `service.instance.id` for each producing worker or custom process: use the resource/configured value or hostname only as a base, then append execution type, stable worker/process identity, and process ID so a rolling reload cannot produce colliding resources. Standalone CLI uses worker ID `0`; its `cli` type keeps it distinct from event worker zero. A custom process includes its class, configured name, and process index.
- Integer `hypervel.worker.id` where one exists and string `hypervel.worker.type` (`event`, `task`, `process`, or `cli`). Custom processes additionally expose bounded resource attributes for class, configured name, and index; these are resource identity, never per-operation metric dimensions.
- Standard SDK/language/process/host resource detection retained.

The scheduler uses one tick at the smallest enabled interval and monotonic per-signal `nextDueAt` values:

- traces: default 5000 ms;
- logs: upstream PHP SDK default 1000 ms;
- metrics: default 60000 ms.

At each tick, only due signals run through their upstream provider's `forceFlush()`. Advance each due time monotonically past the current time after an attempt; a slow export skips missed intervals instead of causing a catch-up export burst. This uniformly covers package-built and application-supplied providers without retaining parallel private processor/reader registries. Serialize export ownership so manual `flush()` and a tick cannot overlap. Internal `flushSignals()` returns `true` when it completed or nothing is bound, `false` when a provider reported failure, and `null` when another flush or shutdown owns the graph. A periodic tick silently skips `null` and reports only exact `false`; public manual `flush(): bool` deliberately maps `null` to `false` so callers learn that they did not acquire ownership without waiting on application code. Closing is different—it must wait for any already-running background/manual flush to release ownership before shutting providers down, so it can never race or silently skip final shutdown. The wait exists only at process termination and uses one manager-owned completion signal, not polling or a coroutine per signal. Catch automatic export failures at this background boundary and report through OTel diagnostics/error output, never the application logger. Exact cancellation from periodic flush or closing shutdown passes to `Coordinator\Timer`; its recurring callback loop treats cancellation as terminal and cleans up silently, matching `Timer::after()`, while ordinary callback failures retain the existing log-and-continue behavior.

Because `autoFlush` is false, the package tick is the only batch-processor drain. Queue size and cadence must therefore be sized together: the shipped trace baseline is `2048 / 5 s`, about 410 completed sampled spans per second per worker between successful drains, and the log baseline is `2048 / 1 s`, 2048 records per second; bursts can fill either queue sooner, and slow/failed exports can reduce sustained capacity. `max_export_batch_size=512` also means a full default trace/log queue drains through up to four exporter calls, so backend request capacity depends on worker count, signal volume, batch size, failed-attempt retries, and cadence rather than only worker count. Keep queues bounded and never add a near-capacity flush on the application path. Document tuning and, when `internal_metrics` is enabled, alert on loss through the cumulative `otel.sdk.processor.{span,log}.processed` counter with `error.type`; unlike a gauge sample, it retains drops between metric collections within the live worker, subject to ordinary metric-export delivery. `otel.sdk.processor.{span,log}.queue.size` is only a coarse observable sample at `metrics.export_interval` and may miss bursts between metric collections, while queue capacity is static. A shorter metrics interval improves depth resolution at the cost of more frequent collection/export; it is not a substitute for the drop counter.

`Timer::tick()` receives `$isClosing = true` when `WORKER_EXIT` resumes and then stops. On that final invocation, call each provider's `shutdown()` exactly once in dependency order: traces and logs first, metrics last. The final trace/log drains can record SDK internal processed/exported/drop data; shutting down metrics last lets its reader perform the one final collection that can export those observations. After all shutdown attempts finish, unbind the deferred graph back to no-op delegates so no handle retains a closed worker SDK. Do not unbind before shutdown and do not force-flush immediately before shutdown because upstream provider shutdown already drains its graph. One manager owns one producing-process SDK lifecycle. A provider-construction or deferred-binding failure occurs before worker-lifetime instrumentation mutation, rolls back to no-op delegates, and may be corrected and retried. After the deferred graph binds successfully, register `ProcessIdentity` in the container once, retain it on the manager, then mark the manager bound before registering the first instrumentation. This makes identity available even when the instrumentation map is empty and removes per-instrumentation registration state. Identity is a truthful process fact rather than provider graph state, so shutdown retains it. Listeners, middleware, macros, and observers have no uniform removal API, so a later `bind()` remains the normal no-op even when a registration then throws. The original registration failure escapes unchanged; successfully registered earlier domains remain truthful when application code deliberately catches it, and the binding lifecycle still owns final shutdown. A bind attempted after shutdown throws a descriptive `LogicException` requiring a new process or application/manager instance. This prevents duplicate worker-lifetime hooks and silent reuse of terminally closed provider overrides and custom span/log processors retained by Hypervel's container. The deferred delegates retain their own tested bind/unbind semantics; the manager does not resurrect an SDK graph whose lifecycle services were shut down. Do not add instrumentation rollback machinery, another shutdown coordinator, a third manager state, a closed-state accessor, or an internal-metric registry. `shutdown()` and manual `flush()` are idempotent and best effort. The OTLP transport timeout bounds each HTTP attempt, not the entire flush: upstream retries, retry delays, and `Retry-After` may extend elapsed time. Do not add a child-coroutine cancellation layer that the synchronous PSR transport would ignore, and do not claim a hard overall deadline that the current SDK cannot enforce. No separate `OnWorkerExit` listener is required.

The lifecycle exception is fail-fast without breaking framework cleanup. Worker exit resumes `WORKER_EXIT` in a `finally`; console termination records listener failure and still terminates the application/runs duration handlers; custom-process cleanup records listener failure and still closes the quit channel, clears native timers, and resumes `WORKER_EXIT`. Legitimate worker shutdown work runs in `OnWorkerExit` before telemetry's closing tick, ordinary application `Terminating` listeners registered during boot run before the package's later CLI shutdown listener, and process work finishes before `AfterProcessHandle`. Do not silently skip a command or other application operation started after those documented shutdown boundaries.

Request/job/query coroutines only mutate in-memory SDK aggregators or queues. OTLP encoding, compression, and network I/O occur in the scheduler coroutine.

## CLI lifecycle

`ServerStartCommand` extends Symfony directly and does not dispatch Hypervel command events, so the worker lifecycle above remains pre-fork safe.

For ordinary commands in a standalone CLI lifecycle:

- During provider boot in a normal console runtime, register lifecycle-owned `BeforeHandle` / `AfterExecute` listeners before any manager-bound instrumentation listeners. After every provider has booted, register the `ArtisanStarting` bind listener and use `Container::resolved()` to detect either a container-resolved Artisan or the instance published by an earlier direct `Kernel::getArtisan()` call. `Kernel::setArtisan(null)` clears both the Kernel property and published instance, so later construction re-dispatches `ArtisanStarting` to the now-registered listener; no separate historical marker or `hasArtisan()` API is needed.
- Binding owns the CLI SDK and registers one `Foundation\Events\Terminating` listener. Establish that ownership in a `finally` whenever the manager became bound, including when later instrumentation registration threw, so a caught boot failure cannot leave the provider graph without final synchronous shutdown.
- At `BeforeHandle`, the lifecycle scheduler path checks CLI ownership first, returns outside a coroutine, then increments `activeCliCommandsInCoroutine` and starts the scheduler on the `0 -> 1` transition. `AfterExecute` applies the same ownership/coroutine checks, retains an underflow guard, then decrements and stops on `1 -> 0`. This handles nested and concurrent commands plus a coroutine command invoked beneath a non-coroutine command, and clears the tick before the owning `Coroutine::run()` can wait for child timer coroutines.
- Console instrumentation independently filters and activates a command span at `BeforeHandle` in either coroutine or non-coroutine commands. The earlier Artisan bind registers this listener before the first command event. It consumes the framework-supplied `AfterExecute::$exitCode` and nullable throwable without narrowing the nullable public constructor fields, then records throwable/non-zero status and ends/detaches only its own invocation state.
- Lifecycle listeners run before later instrumentation listeners. Scheduler cleanup therefore still occurs if a command-telemetry completion listener fails; the final command record queued after the tick stops is exported by terminating shutdown. A non-coroutine command starts no tick and uses that shutdown as its export cadence. It can delay process exit by roughly `timeout * (max_retries + 1)` plus backoff when the endpoint is unavailable; document lower OTLP timeouts and `max_retries: 0` for CLI-heavy deployments rather than adding a second CLI-only timeout setting.
- Do not shut providers down in `AfterExecute`: the throwable is rethrown afterward and the Foundation console kernel reports it later.

`Artisan::call()` may execute inline inside an HTTP/job/server-worker coroutine. When the manager is already server-worker-bound, `BeforeHandle` and `AfterExecute` create/end only the nested command span using the existing worker SDK. They do not increment CLI lifecycle state, start or clear another scheduler, or register `Terminating`; the worker lifecycle remains the sole owner of that SDK and scheduler.

Treat command spans as invocation state, not one manager-global slot. Nested `Artisan::call()` invocations and concurrent scheduled command coroutines each get the correct parent/current scope. The CLI lifecycle counter is independent of console tracing/metrics and their filters, so a filtered long-running command still drains telemetry produced by every other enabled domain. Commands run directly through `Command::run()` bypass Artisan and intentionally do not establish a CLI SDK lifecycle; `env:encrypt` and `env:decrypt` deliberately skip provider boot and likewise emit no package telemetry.

Package tests cover success, non-zero exit, throwable, timer clearing, report-before-shutdown order, non-coroutine Generator/Dev commands with active telemetry but no timer, nested `Artisan::call()` in CLI and server-worker modes, and `ServerStartCommand` exclusion.

## Custom server-process lifecycle

`ServerProcess\AbstractProcess` dispatches `BeforeProcessHandle` and `AfterProcessHandle` rather than worker-start events. Use those existing events to bind and close the package in supported custom processes; do not add another core lifecycle hook.

- Skip a process when its exact class is listed in `server_processes.except`. This one list lets a private relay avoid exporting into itself without a capability registry or callback policy.
- If `BeforeProcessHandle` provider construction fails, the framework still dispatches `AfterProcessHandle`; the package listener remains a no-op because no SDK was bound, so it cannot replace the original diagnostic with a cleanup failure. If provider binding succeeded and later instrumentation registration failed, the manager remains bound and `AfterProcessHandle` closes that graph through the normal lifecycle.
- A coroutine-enabled process binds providers, registers applicable instrumentations, starts the same single scheduler, and handles `AfterProcessHandle` by first clearing its package-owned scheduler tick and then synchronously flushing/shutting down traces and logs before metrics. This completes before `AbstractProcess` closes its quit channel, calls native `Timer::clearAll()`, and resumes `WORKER_EXIT`; do not rely on that later worker event for a process-owned SDK.
- A non-coroutine process binds the same providers/instrumentations but starts no timer and drains synchronously in the same signal order at `AfterProcessHandle`. A long-lived/high-throughput non-coroutine process must call the existing explicit `OpenTelemetry::flush()` at a safe boundary in its own loop, or deliberately size queues for shutdown-only draining; the package must not introduce another async runtime or a background mechanism that cannot run while the process's synchronous `handle()` owns execution.
- Bind/unbind once per process invocation, preserve ordinary nested operation scopes, and include the process resource identity described above. Do not invent a span for the process lifetime itself.

## Framework prerequisites

Framework-owned lifecycle hooks and their supporting correctness are specified and tested in [Hypervel Observability Framework Prerequisites](./2026-08-29-0533-hypervel-observability-framework-prerequisites.md). That plan owns changes to existing framework components and their tests/docs. This plan owns `src/opentelemetry`, root package wiring, canonical OTel docs/navigation, OTel-specific tests/workflow entries, and how the package consumes the resulting contracts.

Framework contracts consumed are: HTTP `ResponseSent` (including WebSocket handshake and Reverb HTTP paths); queue `JobPayloadFinalizing`; database `QueryFailed`; cache failure causes and logical failover identity; existing-pool enumeration and waiter counts; WebSocket `MessageHandled`; View completion observers; Scout and gRPC operation runners; non-blocking gRPC final-status and final-failure access; the current `AfterExecute` input/throwable/exit-code data; exact HTTP physical-send ordinals; boot-order-independent Redis event configuration; and terminal cancellation for recurring Timer callbacks. Existing framework cancellation behavior remains authoritative at the new boundaries.

Package-side invariants:

- Register only listeners/observers needed by enabled outputs. Framework producers guard event construction and observer runners branch before operation-state allocation when unused.
- Treat exact coroutine cancellation as terminal: package observers perform no completion telemetry or arbitrary cleanup callback after cancellation. Coroutine-local state is released by context teardown.
- Consume public typed facts rather than re-wrapping framework operations, decorating engines, reconstructing final payloads/statuses, mutating cache/Redis configuration, or reaching into pool internals.
- Keep framework regression tests and public framework documentation in the prerequisite plan. Package integration tests prove each enabled instrumentation consumes its contract and each disabled instrumentation registers no hook.

## Built-in instrumentation

Use current OTel sem-convention constants whenever the installed package provides them. Never import the archived compatibility aggregates `ResourceAttributes`, `ResourceAttributeValues`, `TraceAttributes`, or `TraceAttributeValues`; use the domain-specific stable or incubating `Attributes\*`, `Incubating\Attributes\*`, `Metrics\*`, and `Incubating\Metrics\*` classes. The Hypervel resource declares the exact schema used by the installed SDK detectors. Resource and instrumentation-scope schema URLs govern different OTLP fields; built-in instrumentation scopes intentionally declare none because this package emits a mix of stable, incubating, and post-release-candidate names and no single released scope schema describes that mix. Stable standard telemetry uses standard names/attributes and recommended explicit histogram bucket advisories. Development conventions are identified in config/docs and are tested as package-owned wire contracts. Framework-only telemetry uses the `hypervel.*` namespace. Never put raw URLs, SQL, keys, job IDs, exception messages, user IDs, job classes, or command arguments on metrics. `error.type` may carry an exception class as the standard bounded error dimension.

HTTP server and client spans share the current convention's naming rule: use `{method} {target}` when the low-cardinality server route or client URL template is available, otherwise `{method}`. The method component is the known `http.request.method`, except that an `_OTHER` attribute value requires the literal span-name component `HTTP`; preserve the original wire method only in `http.request.method_original`. Keep this rule on the shared `HttpTelemetryAttributes` helper so the two instrumentations cannot drift.

Register ordinary listeners for every built-in domain lifecycle because Hypervel's cache, database, Redis, queue, HTTP-server, scheduler, and console producers use `hasListeners()` to decide whether to construct and dispatch their events. `EventInstrumentation` is the sole `Dispatcher::observe()` consumer: its passive application-event observation intentionally does not make a guarded producer start dispatching. Do not substitute observers for domain listeners as a micro-optimization; that would silently disable the instrumentation.

### Metric defaults

The published config spells out each name. A shared snapshot call may feed several enabled observables, but an off metric has no OTel instrument and receives no observation:

| Domain | Metrics on by default | Metrics off by default |
| --- | --- | --- |
| HTTP server | `http.server.request.duration` | `http.server.active_requests` |
| HTTP client | `http.client.request.duration` | none |
| Database/Redis operations | `db.client.operation.duration` | none |
| Connection pools | `db.client.connection.count`, `db.client.connection.max`, `db.client.connection.pending_requests` | none |
| Cache | `hypervel.cache.operations` | none |
| Queue | `messaging.client.sent.messages`, `messaging.client.operation.duration`, `messaging.client.consumed.messages`, `messaging.process.duration` (all development) | `hypervel.queue.jobs` backend depth |
| Scheduler | `hypervel.scheduler.task.duration`, `hypervel.scheduler.task.executions` | none |
| Console | `hypervel.console.command.duration` | none |
| View | `hypervel.view.render.duration` | none |
| Scout | `db.client.operation.duration` | none |
| gRPC | `rpc.client.call.duration`, `rpc.server.call.duration` (release-candidate conventions) | none |
| WebSocket | `hypervel.websocket.message.duration`, `hypervel.websocket.messages`, `hypervel.websocket.active_connections` | none |
| Exceptions | `hypervel.exceptions` | none |
| Object pools | `hypervel.object_pool.objects`, `hypervel.object_pool.max`, `hypervel.object_pool.pending_requests` | none |
| PHP memory | `php.memory.usage`, `php.memory.peak_usage`, `php.memory.limit` | none |
| PHP GC | `php.gc.runs`, `php.gc.collected`, `php.gc.threshold`, `php.gc.roots`, `php.gc.collector_time`, `php.gc.destructor_time`, `php.gc.free_time` (all available on required PHP 8.4) | none |
| Process CPU | `process.cpu.time`, `process.context_switches` when `getrusage()` exists | unavailable platform metrics |
| OPcache (worker zero only) | `php.opcache.memory_used`, `php.opcache.memory_free`, `php.opcache.memory_wasted`, `php.opcache.hit_rate`, `php.opcache.hits`, `php.opcache.misses`, `php.opcache.cached_scripts`, `php.opcache.interned_strings.memory_used`, `php.opcache.interned_strings.memory_free`, `php.opcache.interned_strings.count` when OPcache is active | unavailable metrics |
| Swoole server/worker | `hypervel.server.connections`, `hypervel.server.requests`, `hypervel.server.tasks.active`, `hypervel.server.task_queue.size`, `hypervel.worker.requests` from available server stats; `hypervel.worker.coroutines` from `Coroutine::stats()` | metrics whose source key is unavailable |

The database-pool and messaging names are development conventions in the researched semconv version. In the installed package, stable/incubating DB and HTTP metric constants exist and must be used, including `DbMetrics::DB_CLIENT_OPERATION_DURATION`, `DbIncubatingMetrics::DB_CLIENT_CONNECTION_COUNT`, and `HttpMetrics::HTTP_SERVER_REQUEST_DURATION`. No current RPC attribute class or messaging metric class exists, so the current RPC attributes and four messaging metric names remain tested literals. Development names are explicitly identified in config/docs and can change only through the framework's normal versioning policy.

Use these explicit histogram boundary advisories unless the final installed semconv recommends a different list: HTTP and messaging durations `[0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10]`; DB/Redis/Scout durations `[0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10]`. Use the HTTP list for custom queue-processing, scheduler, console, View, and WebSocket duration histograms. Keep advisories in instrument construction; advanced applications that need aggregation changes use typed `MetricView` services rather than a package-specific array DSL.

### HTTP server

Lifecycle: `RequestReceived` -> `ResponseSent`.

- Before creating timing state, skip configured `except_methods` and `except_paths`. Methods are normalized to uppercase once; paths use Hypervel `Request::is()` wildcard semantics so health/readiness routes can be excluded without per-request regex compilation. An excepted request performs no OTel extraction, timing, span, active-count, or duration work.
- Read the server wire method with `Request::getRealMethod()`, never `getMethod()`: Symfony's latter method applies `_method` / `X-HTTP-METHOD-OVERRIDE` and may throw while validating the application override. Compare the uppercased real method to the exact configured `known_methods` set. Emit the known value or `_OTHER`, and set `http.request.method_original` from the raw `REQUEST_METHOD` server value whenever its spelling differs from the canonical value or the method is unknown. This intentionally produces names such as `POST /users/{id}` when routing matched that route through an application-level `DELETE` override; telemetry describes the wire method while the route describes application routing.
- Extract W3C parent/baggage using the configured propagator.
- Start and activate a SERVER span with a method/server-safe provisional name. At `ResponseSent`, read the route already stored on the request, then set the final span name and route attributes before ending it. Do not register a `RouteMatched` listener: it would construct and dispatch an extra event on every routed request even though the final route remains available at the completion boundary.
- Default span attributes follow current HTTP semconv, including normalized known method, scheme, route, status, protocol version, request/response body size when already known without reading a body, and `error.type` on failure. Do not read or emit `server.address` / `server.port`: both server metrics mark them opt-in, the request authority can create attacker-controlled metric cardinality, and `Request::getHost()` can throw and poison later host validation before the framework kernel renders the correct 400 response. HTTP is already implicit in this instrumentation, so omit `network.protocol.name=http`; retain the recommended `network.protocol.version`, stripping the framework/Symfony server value's guaranteed `HTTP/` prefix so the attribute contains only `1.0`, `1.1`, `2`, or the other actual version component. Accept only a valid non-negative `Content-Length`; for an ordinary already-materialized response, `strlen()` is allowed, but never consume or buffer a streamed/file response to calculate size.
- When a non-noop response propagator is configured, listen to `RequestHandled` and, while the server span context is still active and before transport send, inject into the Symfony response through `ResponseHeadersSetter`. If that existing event carries `CanceledException`, return before propagation. Installed `servertiming`/`traceresponse` propagators can add response headers. The default `none` registers no `RequestHandled` listener or call; span completion still waits for `ResponseSent`.
- End with the cumulative transport exception, the Hypervel response's rendered application exception when present, and final response status. Balance scope/active metrics even when no response exists. Do not add a second exception event when the exception reporter emits its correlated log.
- When `user_context` is enabled and the root span records, collect the authenticated principal only through `Guard::hasUser()` followed by `user()`; never trigger authentication or a database lookup solely for telemetry. The default resolver records only the installed semconv's user-ID attribute. `resolveUserUsing()` replaces it with an application mapping. Resolve lazily at response completion, or at request-origin exception reporting when that happens first, then cache the resulting attributes in `RequestTelemetryState` so the HTTP span and exception record share one resolver call.
- `RequestTelemetryState` is one small coroutine-local value shared by HTTP and exception instrumentation. Create it whenever HTTP instrumentation is registered and the request is not excluded. Normal trace/metric modes retain timing, instruments, the root span `ContextInterface`, and optional lazy user attributes through the native request callback, even after `ResponseSent` ends/detaches the span. This lets a terminable-middleware failure reported by the base `Hypervel\Server\Server::guardResponseCallback()` immediately after `HttpServer\Server::onRequest()` rethrows correlate to the exact ended request span without a per-request `RequestTerminated` listener or another core event. `Hypervel\Server\Server::reportCallbackFailure()` reports it in that same request coroutine. In logs-only `user_context` mode, register only `RequestReceived`, retain only the request plus lazy user data, and perform no clock, method/HTTP-attribute, timing, span, completion-listener, or response work. Keep `except_methods` and `except_paths` ahead of both modes, so excluded requests intentionally have neither state nor user attributes on exception records. Coroutine destruction clears either state.
- When the top-level `log_context` bridge is enabled, add the current trace/span IDs under its configured keys to Hypervel's coroutine-local logging Context for each package-managed activated span, including manual `trace()`. A small `LogContextScope` snapshots and restores prior key existence/values across nested HTTP, queue, console, scheduler, WebSocket, View, Scout, and manual scopes. It never writes when disabled or when the span context is invalid. This is for ordinary non-OTel channels; the OTel log builder continues to correlate directly from Context.
- `http.server.request.duration` histogram, seconds, default on.
- `http.server.active_requests` UpDownCounter, default off because OTel marks it opt-in/development. Increment/decrement with the same start-available attributes.
- Optional request/response header capture uses allowlists plus default sensitive exclusions. URL query recording is off unless explicitly configured; this deliberately favors privacy over the convention's conditionally-required server `url.query`, while the low-cardinality route remains preferred.
- Normalize privacy lists once. `authorization`, `proxy-authorization`, `php-auth-pw`, `cookie`, and `set-cookie` are always redacted even if allowlisted. When query capture is enabled, merge configured names with `access_token`, `api_key`, `apikey`, `awsaccesskeyid`, `password`, `passwd`, `secret`, `sig`, `signature`, `token`, `x-amz-credential`, `x-amz-signature`, and `x-goog-signature`, and replace matching values case-insensitively without logging the original. Apply the same defaults to HTTP-client URLs.

Do not derive active requests from Swoole `connection_num`; keep-alive/socket connections are not active HTTP requests.

### HTTP client

Register one middleware through `Http\Client\Factory::globalMiddleware()` (or `afterResolving` when the factory already exists):

- Start a CLIENT span from the captured ambient parent and inject from an explicit context containing that span before send. A small package-owned `PsrRequestHeadersSetter` reassigns the immutable request through `withHeader()`, replacing rather than appending propagation fields. Never activate the client span: an asynchronous promise may outlive the middleware call or settle in another coroutine, so activation would mis-parent unrelated work and make cleanup cross-coroutine. The promise callbacks retain only the span, explicit context, start timestamp, and already-required request data.
- End on fulfilled/rejected promise, set status/error, and record `http.client.request.duration` in seconds.
- If startup or promise settlement throws `CanceledException`, rethrow the exact instance without span completion, metric recording, status/error mutation, exception association, or URL-template resolver work. Scope cleanup is unnecessary because client spans are never activated.
- Treat a fulfilled 4xx or 5xx as a client error even under Hypervel's default `http_errors=false`: set span status to ERROR and use the status-code string as `error.type` on the span and duration metric. A rejection before a usable response uses the exception class. Ordinary `http_errors=true` still reaches this middleware as a fulfilled response because Guzzle's error middleware wraps it; the later Hypervel `RequestException` uses current/request-root exception correlation because this middleware never observed that wrapper.
- A request with no current span starts a root client span rather than refusing to instrument.
- Read the method from the outgoing `RequestInterface` at the physical-send middleware boundary and never case-fold it. Match it exactly against `known_methods`; emit it unchanged on a hit, otherwise emit `_OTHER` plus `http.request.method_original` and use `HTTP` as the span-name component. Hypervel's easy `PendingRequest` API currently reaches this boundary with an uppercase method because Guzzle 7's `request()` / `requestAsync()` normalize before constructing the request. The public `Http::buildClient()->send*($request)` path instead preserves whatever method its PSR-7 request implementation exposes, and Guzzle 8 / PSR-7 3 will preserve casing on the easy path too. Server instrumentation may canonicalize a wire method because Hypervel/Symfony routing treats methods case-insensitively and retains the original spelling separately; client instrumentation must describe the request the transport emits, so do not force symmetry by uppercasing client methods.
- Every client span includes required `url.full`, `server.address`, and `server.port`. Build `url.full` without exposing URI credentials: redact any userinfo, omit the query component entirely when query capture is off, and otherwise retain it with the shared sensitive-key redaction. Preserve the rest of the URI, including an already-known fragment. Use the application-owned outgoing host without reverse lookup and derive an omitted default port as 80 for HTTP or 443 for HTTPS. Client duration metrics include the same required host/effective-port pair. The opt-in `url.scheme` and `url.template` remain trace-only, and neither signal emits redundant `network.protocol.name=http`. Use the PSR-7 response protocol version unchanged because `MessageInterface` guarantees its bare numeric form; do not silently normalize a broken transport implementation.
- `resolveUrlTemplateUsing()` receives only the outgoing `RequestInterface` and may supply a low-cardinality `url.template`. On a recording span, a non-null result sets the attribute and updates the provisional method-only name to `{method-or-HTTP} {template}`. Isolate resolver failures through OTel diagnostics. No resolver runs for server requests, metrics-only requests, or non-recording client spans.
- Record body size only from a valid existing `Content-Length`; avoid body capture and response-body buffering.
- One logical Hypervel call emits one CLIENT span per physical retry or redirect request. Set stable `http.request.resend_count` only after the first send, using `PendingRequest::PRIOR_SENDS_OPTION + ($options['__redirect_count'] ?? 0)`. Never approximate the value from Hypervel's retry attempt alone; prior redirect hops must remain part of the ordinal.

With `manual=false`, create a span and inject propagation for every request unless `PendingRequest::withoutTrace()` is called. With `manual=true`, do so only for requests marked by `PendingRequest::withTrace()`. These controls govern tracing/propagation only; an independently enabled duration metric still observes every client request. Register the one global middleware when tracing or the duration metric is enabled, but register the boot-only `withTrace()` / `withoutTrace()` macros only when tracing is enabled. The macros set the package-owned Guzzle option `hypervel_otel_trace` through `withOptions()`; define it once as an internal class constant shared with the middleware. The middleware reads it from `$options` only on the tracing path, so metrics-only mode has no trace-option lookup, macro registration, or macro-collision failure. A disabled domain registers neither macros nor middleware. When tracing is enabled, detect a pre-existing macro with either name and fail during worker boot rather than silently replacing application behavior.

### Database

Listen to `QueryExecuted` and `QueryFailed`:

- If `QueryExecuted::$time` is `null`, emit neither a span nor a duration observation: the public manual `logQuery()` entry did not measure an operation, and inventing a zero-length CLIENT span would be misleading.
- At listener entry, take one SDK-clock end timestamp and derive the start from the event's elapsed milliseconds. Create a completed CLIENT span with both explicit timestamps; telemetry listener work is not added to the database duration.
- Record stable `db.client.operation.duration` as the event duration converted to seconds with required low-cardinality attributes.
- Determine `db.operation.name` conservatively: trim whitespace, remove one trailing semicolon, reject any remaining semicolon, and accept only a leading `SELECT`, `INSERT`, `UPDATE`, or `DELETE`; otherwise omit it. Do not parse comments or claim to parse arbitrary SQL.
- When `query_text` is true, set `db.query.text` to the supplied SQL template, limited to `query_text_max_length` Unicode characters (500 by default, `null` for no query-specific cap). Use `mb_substr()` without an ellipsis before handing the value to the SDK so worker queues and export payloads are bounded; the SDK then applies `OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT`, so the tighter character limit wins. Never attach bindings or interpolated/raw SQL. The docs must state that application-authored literals remain visible.
- Set namespace/system/server/connection role where available. On failure, prefer the canonical class of an underlying driver exception for `error.type`, falling back to the final throwable class; never use its message as a metric dimension.

Pool observables, collected only at export time:

- `db.client.connection.count` split by `db.client.connection.state=used|idle`;
- `db.client.connection.max`;
- `db.client.connection.pending_requests` from `getWaiters()`.

These development semconv metrics remain individually configurable. Enumerate only already-created pools via `pools()`; observation must never create a database connection or pool. Use the exact application-unique `db.client.connection.pool.name` values `database:<physical-name>` and `redis:<connection-name>` so equal configured names in the two unconditional pool domains cannot collide. For connection pools, `used` means managed connections unavailable in the idle channel (`getCurrentConnections() - getConnectionsInChannel()`), including a connection briefly held for validation or checkout; never expose internal borrowed sets solely for telemetry. Pin the literal prefixes and use only `DbIncubatingAttributes` / `DbIncubatingMetrics`, including the incubating `idle` / `used` value constants rather than the archived aggregate classes.

### Redis

Use existing command success/failure events and their elapsed time:

- Normalize the event command once to uppercase for formatter classification, span naming, `db.operation.name`, and the duration metric. This deliberately differs from semconv's recommendation to retain application casing: Redis command calls are case-insensitive, caller spelling is uncontrolled, OTel's Laravel contrib instrumentation also uppercases it, and canonicalization prevents duplicate metric series or inconsistent safe-argument classification. Use the canonical command for built-in output but continue passing the original command to the custom resolver.
- CLIENT span and `db.client.operation.duration` with `db.system.name=redis`, the canonical low-cardinality command operation, and the raw configured logical connection name as `hypervel.redis.connection`. This bounded identity distinguishes cache, queue, and session connections and pairs command series with the existing `redis:<connection-name>` pool series. Do not emit configured `server.address`, `server.port`, or `db.namespace`: standalone endpoints can be read safely, but Cluster seeds and Sentinel nodes are not the command-serving endpoint, and the configured database index can become stale after `SELECT`. The command events expose no truthful per-command physical node or database fact, so use one uniform logical identity across topologies rather than attributes that are accurate only sometimes.
- Query text is off by default. When enabled on a recording span, a conservative `RedisQueryTextFormatter` sets `db.query.text` to the command plus only scalar parameters at explicitly classified key/field positions; it never invokes arbitrary `__toString()`, serializes arrays/objects/callables, or includes value, credential, script, or message positions, and it emits the command name alone for unknown/module commands. “Conservative” means values and credential-like arguments are structurally excluded, not that application key/field names cannot contain PII; document that residual opt-in risk. Base the classification on the researched OTel-contrib Redis serializer but tighten its broad serialize-all families. Cap output at `query_text_max_length` Unicode characters (500 by default, `null` for no package-specific cap), without ellipsis, before the SDK's standard span-attribute limit.
- `resolveRedisQueryTextUsing()` replaces the built-in formatter for applications/packages that understand custom commands or need bespoke hashing/redaction. It receives the raw event parameters only after the option and recording-span checks; its nullable output is capped identically. Resolver failure is isolated through OTel diagnostics. Never attach a raw argument array, execute formatting on a metrics-only/non-recording path, or place query text on metrics.
- Error type from `CommandFailed`. Do not add a defensive null-duration branch: the framework's sole Redis event producer always supplies its measured time.
- Existing framework cancellation emits no Redis completion event. Instrumentation therefore records no command completion for that path and does not add Redis-specific cancellation machinery.
- Inject the canonical `RedisManager` and call `enableEvents()` during registration only when Redis tracing or `db.client.operation.duration` is enabled. Pool observables alone never enable command events. The manager refreshes any earlier mismatched pool generation at this boot boundary, so worker-start, CLI, custom-process, application-provider, Sentry, and Telescope ordering cannot silently suppress command telemetry.
- Redis pool observables use the database connection-pool metric names and never resolve/create absent pools.

### Cache

Use completion events only:

- Register ordinary listeners for `CacheHit`, `CacheMissed`, `KeyRetrievalFailed`, `ManyKeysRetrievalFailed`, `KeyWritten`, `KeyWriteFailed`, `KeyForgotten`, `KeyForgetFailed`, `CacheFlushed`, `CacheFlushFailed`, `CacheLocksFlushed`, `CacheLocksFlushFailed`, and `CacheFailedOver`. These active listeners deliberately make the framework's guarded producers dispatch. Respect each repository's configured emitter layer: a store with `events => false` produces no ordinary cache telemetry, while `CacheFailedOver` remains visible because `FailoverStore` dispatches it independently through its injected dispatcher.
- Add package-owned span events named `hypervel.cache.get`, `hypervel.cache.put`, `hypervel.cache.forget`, `hypervel.cache.flush`, `hypervel.cache.lock_flush`, and `hypervel.cache.failover` to a recording active span. The `hypervel.` prefix avoids claiming OTel's currently unsettled cache namespace. Shared low-cardinality attributes are `hypervel.cache.operation`, optional logical `hypervel.cache.store`, `result` (`hit`, `miss`, `success`, or `failure`), and `error.type` when the event carries an exception. A failover event additionally carries the failed backing store as `hypervel.cache.failed_store`; omit the normal store attribute when the logical failover name is unavailable rather than mixing the two identities.
- Create `hypervel.cache.operations` as a Counter with unit `{operation}`. A single-key completion records one; `ManyKeysRetrievalFailed` records the exact number of requested keys; `putMany()` already emits one event per key and therefore records one at each event. A failover records one backing-store transition under its distinct `failover` operation and must not be interpreted as a cache attempt or included in hit/success-rate calculations. The cache key and TTL are never metric attributes.
- Each `KeyWritten` / `KeyWriteFailed` span event, including the per-key events emitted by `putMany()`, includes the already-available non-null TTL as `hypervel.cache.ttl` in seconds. Repository control flow guarantees a positive integer or `null`, so do not add a redundant range guard. Do not derive or emit an absolute expiry or a locale/time-dependent human description.
- Trace-event key capture is off by default. When enabled on a recording active span, normalize backed enums to their scalar value, apply an optional `resolveCacheKeyUsing()` replacement for hashing/redaction, and cap the nullable result at `key_max_length` Unicode characters (500 by default, `null` for no package-specific cap). With no resolver, the bounded raw key is recorded and its privacy/cardinality risk is explicit in config/docs. Resolver failure is isolated through OTel diagnostics. Do not serialize multi-key arrays into one attribute in this version; preserve the useful operation/result event without keys for bulk events.
- Branch on a recording active span before trace-only key, TTL, or event-attribute work, and branch independently on the counter before building metric attributes. Compute the shared low-cardinality operation/store/result/error values once only when at least one enabled signal consumes them. When traces and the counter are off, register no listeners.
- No cache span or duration pairing. Database/Redis-backed stores already produce timed downstream spans, and cross-event pairing adds state/overhead without reliable new information.
- Document truthful coverage limits: memoized-cache local hits bypass the inner repository and are not counted; `add()`, `increment()`, and `decrement()` have no completion events; and `CacheFailedOver` remains independent of the outer repository event switch.

### Queues

Producer:

- Listen to `JobPayloadFinalizing` when tracing, propagation, or either producer metric is enabled; a consumer-only configuration with propagation off registers no producer listener. For each framework job enqueued through a persistent object/class API, start one PRODUCER send span at this actual dispatch boundary when tracing is enabled. Decode once when tracing needs the framework UUID or propagation needs a mutable carrier. Inject and re-encode only when propagation is enabled; otherwise retain the original encoded payload exactly. Never activate the producer span: database/Redis work performed by the driver keeps its ambient parent, and multiple messages in one bulk call cannot become nested current spans. When tracing is off but propagation is on, inject the current valid context without creating a span. Propagate-without-recording is a supported configuration: the package creates or extracts no built-in trace context while traces are inactive, but queue propagation forwards an explicitly active application/third-party trace context and Baggage. Built-in inbound HTTP/gRPC extraction remains trace-gated. When the application establishes neither context nor Baggage, recommend disabling propagation because its finalizer still decodes and re-encodes persistent payloads. Standard W3C fields are disjoint from Hypervel's built-in payload keys; applications using custom propagators or payload metadata own collisions they introduce, so do not add a field scanner, carrier-diff optimization, or package envelope. Direct driver `pushRaw()` calls intentionally bypass Laravel's job lifecycle; callers that own raw propagation use the facade or upstream propagator against the payload root before encoding.
- The fallible region starts after any producer span and ends before the local commit. On ordinary failure, record the exact throwable, set ERROR, associate its span context for later exception-log correlation, and end the span when one exists, then rethrow unchanged; trace-disabled paths simply rethrow. Cancellation rethrows unchanged without completion or association. Reaching either catch means no state or payload was committed, so no rollback exists. This pre-broker failure records no sent counter or duration. State insertion and payload assignment are the adjacent, non-yielding commit; terminal events own the state/span afterward, and their order is convention rather than a race boundary.
- When propagation is enabled and normalized queue config contains a built-in `background` or `deferred` connection, also register one `Queue::createPayloadUsing()` callback. Branch on a precomputed connection-to-driver map and return only flat injected fields for those two drivers; every other connection returns `[]`. This is the same dispatch-time payload dehydration pattern Hypervel uses for log context. These local drivers have no enqueue terminal, so they create no producer span or producer metric state; their consumer telemetry continues the injected dispatch context. `sync` runs inline under the ambient context and needs no injection, `null` emits no telemetry, and a custom SyncQueue-like driver owns its payload hook. Persistent-only deployments register no static callback.
- Do not create messaging Create spans. The finalization seam gives every persistent asynchronous framework job a unique send context, including root and batch dispatches, with one producer span rather than Create+Send. Sampling is the standard volume control; do not add a package-specific `create_spans` mode with weaker correlation.
- Store in-flight producer state in one `QueueProducerStateStore implements NonCopyableContext` in `CoroutineContext`: an exact final-payload-to-state map and an optional framework-UUID-to-payload fallback index. Each state retains its nullable UUID so removal also clears the fallback index. Database/SQS batches can retain multiple entries before any terminal event, so a mutable store avoids array copy-on-write and `NonCopyableContext` prevents a forked child from mutating its parent's entries. Normal `JobQueued` / `JobQueueingFailed` correlation uses the exact payload without JSON decoding; computing its first string hash is proportional to payload length but remains cheaper and lower-allocation than decoding. If a later finalizer listener re-encodes the mutable event payload after OTel's listener, decode that final terminal payload once and resolve the original exact payload through its preserved UUID. Do not depend on `traceparent` for private correlation because propagation may omit trace context. Missing and non-string UUIDs are accepted and use exact-payload correlation only. Trace-disabled producer metrics use the same store, create no span, decode only when propagation independently requires it, and retain state under the exact final payload. The package never listens to `JobQueueing`.
- A later listener that removes/corrupts the framework UUID, rewrites a UUID-less payload, or throws after OTel's finalizer supplies no terminal correlation fact. For a rewritten UUID-less payload, the producer span was started but is never ended and therefore never exported; its state remains until coroutine teardown or process exit. Byte-identical UUID-less payloads within one coroutine also share one exact key and cannot be independently correlated; framework-created payloads avoid this because they contain unique UUIDs, while custom drivers own this limitation. In a Database/SQS batch, a later message's finalizer failure can likewise leave earlier finalized sibling states without terminal events because the framework has not begun the batch attempt. A long-running non-coroutine custom process that catches and repeatedly continues such broken dispatches retains one producer state, span context, and payload-sized exact key per orphan until it exits. Do not guess among concurrent batch states, add a second private correlation field, sweep, cap, or listener-order mechanism.
- End the exact span and record per-message `messaging.client.sent.messages` / `messaging.client.operation.duration` at `JobQueued` or `JobQueueingFailed`, with `enqueue` operation and error type as applicable. The counter records each logical attempt on success or failure. Database/SQS expose per-message lifecycle rather than one authoritative batch span, so do not invent `messaging.batch.message_count` or a generic batch API. SQS failure handling completes every outstanding finalized message exactly once, including unsent later chunks; later-chunk durations include preceding chunk work as documented above.
- Recording producer spans use `enqueue <destination>` or bare `enqueue`, set standard operation name/type, system/destination, `hypervel.queue.connection`, a string framework UUID as `messaging.message.id` when present, and `messaging.message.envelope.size` from `strlen()` of the already-materialized encoded payload. Message ID and size never become metric dimensions. Each FailoverQueue child attempt builds its own payload/UUID and truthful producer lifecycle, so multiple failed send spans before one success are expected rather than duplicates.

Consumer:

- On `JobProcessing`, extract producer context from the payload root and activate a messaging `process` span with `SpanKind::CONSUMER`. In the normal asynchronous queue-worker path with no valid ambient span, use the producer context as parent; if an ambient operation span exists, retain it as parent and add a link to the producer context instead. Built-in sync jobs have no injected context and naturally use their ambient parent.
- When queue tracing is off but `propagation` is on, activate the valid extracted producer context itself for the job lifetime without creating a consumer span. This makes downstream application/third-party spans descendants of the remote producer while preserving a true propagation-only mode. Detach it at the same canonical completion boundary; if no valid context was extracted, create no scope. When both tracing and propagation are off, perform no payload extraction or Context operation. A connection configured with Hypervel's built-in `sync` driver also performs no extraction: the package never injects its payload, and the job executes inline under the ambient context.
- Retain active consumer state in one instrumentation-instance `WeakMap<Job, QueueConsumerState>`. Create an entry only when a consumer span, activated extracted scope, or process-duration metric needs completion; propagation-only sync jobs and asynchronous jobs without a valid carrier retain no empty state. The map is bounded by live jobs, values never reference their keys, completion removes entries, and cancellation leftovers disappear with job collection. The same job object lets timeout/failure events from a monitor coroutine update the correct state without a process-global ID registry; no static cleanup applies.
- Use `JobExceptionOccurred`, `JobTimedOut`, and `JobFailed` to record the first terminal error on guarded attempt state, tolerating timeout/failure events from the monitor coroutine. `JobAttempted` supplies the canonical result/duration exactly once.
- At `JobAttempted`, mark the attempt terminal once, record the canonical duration, and detach/end immediately in every driver and worker mode. Deferring completion to coroutine exit would keep a persistent job span active through later command work on the supported `queue:work --once` path. When the final attempt has an ordinary throwable, associate that exact throwable with the consumer span context in `ExceptionContextRegistry` immediately before ending; this preserves correlation when `Worker::runJob()` or the background/deferred exception callback reports it afterward without extending the operation scope. A `JobAttempted` with no matching `JobProcessing` state—such as an invalid payload or a failure from an earlier `JobProcessing` listener—is a no-op. A distinct exception thrown by a later queue lifecycle listener remains attributed to its ambient context rather than being misattributed to the completed job. Cancellation emits no `JobAttempted`; deliberately abandon the started consumer telemetry with coroutine context rather than exporting a false completion.
- Increment `messaging.client.consumed.messages` once at `JobProcessing`, when the broker delivery reaches the application, regardless of its eventual outcome. Record `messaging.process.duration` only at canonical attempt completion, with connection/system, stable queue name, operation, and error type. Do not use job UUID as a metric attribute.
- Recording consumer spans use `process <destination>` or bare `process`, set standard operation name/type, system/destination, `hypervel.queue.connection`, framework UUID as `messaging.message.id`, and `messaging.message.envelope.size` from `strlen($job->getRawBody())`. The worker has validated persistent payloads before `JobProcessing`; SyncJob payloads are framework-created and `pushRaw()` cannot enqueue them, so do not add a second defensive decode guard.
- Cleanup state even when listeners or exception reporting throw. Custom context scopes retain their originating coroutine ownership, so cross-coroutine timeout cleanup cannot detach an unrelated context.

`hypervel.queue.jobs` depth is off by default and requires an explicit `depth_queues` map such as `['redis' => ['default', 'emails']]`. When enabled, one observable callback in event worker zero resolves only those configured `QueueManager` connections and calls the public `size($queue)` during metric collection in the scheduler coroutine. It reports connection and queue attributes, catches each backend failure through OTel diagnostics, and never performs depth I/O on a request/job path. Enabling the metric with no queues is a boot-time configuration error. A queue-only deployment with no event worker receives no built-in depth series; it registers an application observable through `OpenTelemetry::meter()` in its deliberately designated process. Do not add leader election, IPC, a worker selector, or a topology guess for this optional backend poll.

### Scheduler

Use `ScheduledTaskStarting`, `ScheduledTaskFinished`, and `ScheduledTaskFailed`:

- Start and activate one root INTERNAL span at `ScheduledTaskStarting` in the task coroutine. `ScheduleRunCommand` deliberately copies only Hypervel's logging-context key into its task `Waiter` (and background task hop), not the package's OTel context key, so task spans are independent roots rather than children of the minute-long `schedule:run` command trace. Do not widen the framework copy allowlist. If the opt-in log-context bridge copied the command IDs, activating the task span replaces them for the actual task execution and its scope restores the prior values afterward; scheduler orchestration that occurs before `ScheduledTaskStarting` legitimately remains command-correlated.
- `ScheduledTaskFinished` occurs before exit-code validation; read `Event::exitCode()` and treat non-zero as error before ending.
- `ScheduledTaskFailed` records the throwable and ends only if the state remains active.
- Record `hypervel.scheduler.task.duration` and `hypervel.scheduler.task.executions` with `result` plus a stable task identity: explicit description when supplied, otherwise the registered Artisan command name without arguments, or a fixed `callback`/`process` fallback. Never use the raw command line or closure file/line as a metric attribute.
- System/background commands run in separate processes/coroutines and do not receive invented environment-based trace propagation in this version.

### Console

Use the CLI lifecycle above:

- INTERNAL command span and `hypervel.console.command.duration`.
- Attributes: stable command name and exit code; exclude raw argv/options by default.
- Do not create a span for the pre-fork server start command.
- Normalize `commands` and `except` patterns once and match with Laravel-style `Str::is()` semantics. The default `['*']` allowlist preserves all ordinary commands; exclusions win. A filtered command performs no command-duration timing, span, Context, or metric work, while the process-level export scheduler remains independently owned by the CLI lifecycle and nested `Artisan::call()` still uses the surrounding operation context for any work it performs.

### Application events

`EventInstrumentation` accepts an exact class/name allowlist and registers it in one call to `Dispatcher::observe()`. It adds one span event named for the dispatched event to the current span only when that span records. Do not inspect or serialize payload properties by default and do not create child spans or a duration metric.

Use passive exact observers, not ordinary listeners or a wildcard: they run after active listeners (including after an active-listener failure), do not alter halt/propagation behavior, and do not make `hasListeners()` true. Consequently, an event that its producer suppresses entirely behind `hasListeners()` remains unobserved unless an active application listener already causes dispatch; this is intentional. An empty allowlist registers no observer.

### Views

Use the existing pre-render observer and new completion observer:

- INTERNAL span and `hypervel.view.render.duration` from immediately before rendering notification/engine execution through completion;
- use span name `view {name}` and `hypervel.view.name` as the only view identity; never view data, rendered HTML, or filesystem path;
- one per-coroutine stack handles nested view renders and concurrent requests. Pop only when the stack's top entry owns the exact `ViewContract` instance passed to completion. If an earlier pre-render observer failed before OTel pushed that view, completion is a no-op and must never pop an outer render;
- no observer registration when traces and the duration metric are both off; a non-recording span with the metric enabled performs only the shared metric timing/identity work.

### Scout

Register one observer with `EngineOperationRunner` when Scout is installed and a Scout trace or metric output is enabled:

- CLIENT span and stable `db.client.operation.duration` around the exact engine `search`, `paginate`, `update`, `delete`, `flush`, or `delete_by_filter` call; engine implementation DB/HTTP spans therefore become children. Base `get()`/`keys()`/`cursor()` and ordinary Builder fallback mapping/hydration remain outside the CLIENT span. A specialized `PaginatesEloquentModels*` engine method is self-contained, so its complete method—including any hydration it deliberately performs—is wrapped because no narrower public engine boundary exists;
- spans use `db.system.name` for the configured Scout engine name, `db.operation.name`, `db.namespace` for the exact Scout index/dataset, and `hypervel.scout.model` for the model class; emit stable `DbAttributes::DB_OPERATION_BATCH_SIZE` only when `modelCount > 1`, and never capture model IDs. Metrics omit model class/model count and retain only the operation/system/index namespace attributes. Index names remain application-defined and can be high cardinality, so document typed metric-view filtering rather than claiming a universal bound;
- query strings, pagination values, index payloads, and model IDs are never captured by the built-in instrumentation;
- state lives in the runner-issued observer token, not a process-global active-span property, so nested and concurrent Scout operations remain isolated;
- no observer registration, descriptor-index resolution, or `EngineOperation` allocation when every Scout output is off. The five base-method wrappers also avoid closure allocation in that state; an optional-interface Builder or filtered-deletion branch still allocates its one callback closure before `runOperation()` can take the no-observer branch. Empty update/delete collections, all NullEngine operations, and DatabaseEngine/CollectionEngine no-op writes emit no Scout telemetry. Direct raw engine method calls on an engine constructed outside `EngineManager` are deliberately outside the contract; third-party engines used through the normal manager/wrapper path work without subclass changes.

### gRPC

When `hypervel/grpc` is installed and either signal is enabled, register one `GrpcOperationObserver`. The instrumentation checks optional-package class availability before resolving the runner; a configuration entry alone must never make `hypervel/grpc` a runtime requirement:

- Create one CLIENT or SERVER span for the complete logical RPC. Client spans include retry/backoff and the full request/response-stream lifetime; do not create per-attempt or per-message spans.
- Use the current release-candidate RPC conventions: `rpc.system.name=grpc`, recognized fully qualified `rpc.method` as `Service/Method` without the HTTP path's leading slash (otherwise `_OTHER`), string `rpc.response.status_code`, and `error.type` only on a convention-classified failure. Client spans add the configured target address and port because that address is the endpoint they dial, even when its text resembles a bind wildcard. Server spans add the already-known configured port and add the configured bound address only when it is not the wildcard `''`, `0.0.0.0`, or `::`, because a wildcard bind is not a server identity. Neither path performs reverse lookup or transport inspection. Span name is the same recognized `Service/Method` or the `grpc` fallback. Use tested literal current names because the installed PHP semconv package has not generated a current RPC attribute class; its archived aggregate contains only superseded names. This greenfield instrumentation emits only the current convention and needs no legacy dual-emission switch.
- Client `starting()` starts the span from the current parent but does not activate it; it injects W3C trace/baggage from an explicit context containing that client span, so the generated method returns with its original ambient context unchanged. Its token owns the span context and start timestamp only. Server `starting()` extracts inbound metadata before preflight/application middleware and activates the SERVER span; its token additionally owns the OTel scope and optional log-context scope because the complete synchronous server stack should be parented to that RPC. A package-owned `GrpcMetadataSetter` implements only the public propagation setter contract and reassigns the immutable client carrier through `without()->with()` rather than appending duplicate propagation fields. Server extraction uses the upstream singleton `ArrayAccessGetterSetter` directly with the descriptor's raw header array; do not duplicate its case-insensitive multi-value array getter. No global active-call map exists.
- Finish once with final status/error and record the thrown exception according to RPC exception conventions. On clients, every non-OK final gRPC status is an error. On servers, use the gRPC convention's narrower error-status set (`UNKNOWN`, `DEADLINE_EXCEEDED`, `UNIMPLEMENTED`, `INTERNAL`, `UNAVAILABLE`, and `DATA_LOSS`); application-result statuses such as `INVALID_ARGUMENT` do not set ERROR merely because they are non-OK. A classified status failure uses its stable status name as `error.type`; a failure before any status uses the final exception type. Apply the same classification to duration-metric `error.type`. A client span may add bounded `hypervel.grpc.attempt_count`; do not expose retry delays, metadata values, request/response messages, or message sizes by default.
- When a client call finishes with a throwable that Hypervel may report after the logical call span is ended, associate that throwable with the retained client span context in `ExceptionContextRegistry` before ending it. Server failures are reported while the active server scope still exists and do not use the registry.
- Record `rpc.client.call.duration` and `rpc.server.call.duration` histograms in seconds with the same complete logical-call duration and recommended HTTP/RPC buckets. Metrics use only `rpc.system.name`, recognized method/`_OTHER`, response status, and error type. Add known `server.address` / `server.port` to the client metric when available without reverse lookup. They are opt-in on the server metric and are omitted by default; `server_metric_address=true` adds the already-known configured port and adds the configured bound address only when it is not the wildcard `''`, `0.0.0.0`, or `::`. Never substitute the client-controlled authority or perform a reverse lookup. Both metrics are independently configurable.
- Explicit `Call::cancel()` finishes the client span/metric as CANCELLED. `CanceledException` teardown skips observer completion in the cancelled coroutine.
- If traces and both duration metrics are off, do not resolve the runner, register an observer, inspect metadata, allocate operation state, or alter gRPC execution.

### WebSocket

- Create a SERVER span named `websocket.message` from `MessageReceived` to `MessageHandled`, with `hypervel.websocket.opcode`, `hypervel.websocket.server`, error type, and no message body.
- `hypervel.websocket.message.duration` and `hypervel.websocket.messages` use the same opcode/server/`result` attributes. The message counter alone listens only at completion and performs no start timing or state allocation.
- `hypervel.websocket.active_connections` UpDownCounter uses only `hypervel.websocket.server` from existing opened/closed events.
- These native `hypervel/websocket-server` boundaries already wrap every resolved `OnMessageInterface`, including Reverb's `WebSocketHandler`; do not also observe Reverb's post-success `MessageReceived` event or create duplicate Reverb-specific message telemetry.
- All three metrics have independent switches. Connection instrumentation does not create long-lived connection spans.

### Exceptions

Use the provider's `callAfterResolving(ExceptionHandlerContract::class, ...)` once per manager, including the already-resolved case, only when the exception ERROR-log output or `hypervel.exceptions` metric is active. If the resolved handler has `reportable()`, append one callback, matching Keepsuit's Laravel package while preserving Hypervel's Sentry-style report path. The handler naturally applies mapping and `dontReport`/duplicate/throttle filtering before callbacks; custom exception `report()` methods and earlier reportable callbacks that consume reporting remain authoritative. A custom handler without `reportable()` is left untouched and the limitation is documented. The log output and exception counter are independent: metrics-only mode creates no log builder, resolves no log context/user attributes, and runs no enricher; logs-only mode creates no counter/instrument.

When enabled:

- Emit one ERROR log record with event name `exception`.
- Set source timestamp and ERROR severity. Use `LogRecordBuilderInterface::setException()` for standard `exception.type`, `exception.message`, and `exception.stacktrace` under the defaults; use explicit standard attributes when a message/stack privacy switch suppresses one field. Set the log body separately and obey the same message switch: it may contain the exception message only when enabled, otherwise use a class-only body. Never build a class-plus-message body first and then try to redact only the `exception.message` attribute.
- Add code function/file/line when available without source reads, using the stable `CodeAttributes` constants. Format PHP methods as the semconv-native fully qualified `Class::method` regardless of whether the trace frame came from an instance or static call.
- Set the log context using the exact-operation/current/request-root precedence below. Errors on unsampled requests still produce a correlated log when logs are enabled.
- Set `hypervel.exception.origin` only when the bounded `request`, `job`, `console`, `schedule`, `websocket`, `rpc`, or `process` value can be determined truthfully. Increment `hypervel.exceptions` with exception class and the same optional origin; do not attach message or stack to metrics. `process` means an actual custom server process and is never an unknown fallback.
- For request-origin records, prefer an exact throwable handoff, then the current valid span context, then the ended root context retained in `RequestTelemetryState`. This covers failures from terminable middleware that occur after `ResponseSent` without keeping the HTTP scope active beyond the transport boundary. With user context enabled, merge the same lazily collected request-scoped user attributes used by the HTTP root span, or the request-only state created by HTTP's logs-only user-context mode. If exception reporting is the first consumer, it collects once only when the guard already has a user; HTTP completion reuses the cached result. Other origins never attempt auth resolution.
- Invoke all registered enrichers in order with the builder and throwable. Catch each enricher independently and send diagnostics outside the application logger.

`ExceptionContextRegistry` is one internal, failure-only `WeakMap<Throwable, ExceptionContext>`, not a general telemetry registry. The small immutable value carries the exact `ContextInterface` and optional bounded origin without repeated anonymous array shapes. The map remains lazily allocated and is active only when direct exception-log capture is active; otherwise `associate()` returns immediately and failure paths pay no storage work for a nonexistent consumer. The facade `trace()` helper plus DB, Redis, HTTP-client, gRPC-client, queue producer-send/consumer, scheduler, and console instrumentation associate a throwable immediately before ending an operation span when framework reporting necessarily happens afterward. Association also follows the framework-recognized single-hop `getInnerException()` mapping when it returns a different `Throwable`, so `Handler::mapException()` retains the handoff for wrapped streamed-response failures without inventing recursive traversal or cycle machinery. On an exact miss, `take()` follows one direct PHP `getPrevious()` cause and atomically takes that entry; it never walks an arbitrary exception chain. This correlates Hypervel HTTP connection wrappers without registering a `ConnectionFailed` listener or adding a transfer API. User-defined exception mappers can return an unrelated object that does not exist at the originating failure boundary; those records use the normal current/request-root fallback rather than justifying a framework telemetry hook. The reportable callback atomically takes the value, passes its context to `LogRecordBuilderInterface::setContext()`, and uses its optional bounded origin. This is required for standalone failed operations, manual helper spans, and command/scheduled-task failures whose originating scope is already gone by the time Hypervel reports; otherwise their log can lose trace correlation entirely, not merely move to a parent span. Direct use of the standard tracer remains intentionally standard API: callers that end a span before reporting an exception own any explicit log context handoff. HTTP uses current/`RequestTelemetryState` context; gRPC-server and WebSocket exceptions are reported while their operation context remains active. Those paths do not write this WeakMap. The registry performs no success-path work and weak keys cannot retain exceptions.

`OperationOrigin` owns one key created through the public `Context::createKey()` API; OTel Context matches custom values by key-object identity, so every producer and resolver must share this exact instance. Package-managed request, job, console, schedule, WebSocket, and inbound gRPC root contexts store their bounded origin and nested contexts inherit it. Resolve exception origin in this order: retained/current OTel Context, `RequestContext::has()`, presence of the public WebSocket FD key in `CoroutineContext`, then the bound `ProcessIdentity` (`cli` maps to `console`, a custom server process maps to `process`). Omit the attribute in an event/task worker when none is known. Do not construct the upstream internal `ContextKey` class, treat `process` as an unknown sentinel, or add origin-only queue/scheduler shadow lifecycles when their instrumentation is disabled.

The private SaaS agent may add tenant context, fingerprint hints, release metadata, and bounded opt-in source snippets in the originating worker. Vendor-specific attributes use its namespace. It enriches the existing record and never emits a second exception record. Do not implement breadcrumbs; sampled trace spans are the causal trail. Do not promise function arguments because production PHP commonly disables exception arguments and they are privacy-sensitive.

### Logs

Register an `opentelemetry` log driver/channel with a small Monolog `AbstractHandler` that implements `handle()` directly. Do not extend `AbstractProcessingHandler`: it would run a formatter and populate `LogRecord::$formatted` even though OTLP uses the structured record fields.

- Preserve the Monolog record timestamp, map levels with `Severity::fromPsr3()`, retain the original severity text, and cache one standard OTel logger per Monolog channel.
- Preserve message and only scalar/OTel-valid configured context attributes; do not serialize arbitrary objects recursively.
- Emit through the standard OTel logger builder, which correlates the current context automatically.
- If context contains the exact throwable currently being reported by `Foundation\Exceptions\Handler::isReporting()` and the direct exception reportable callback is registered and enabled, skip the framework's follow-up OTel-channel record. `reportable()` callbacks run before the framework logger; if the framework reaches its logger, the registered direct callback has already run, while a thrown direct-recorder bug prevents that logger path rather than requiring a second emission marker. Do not suppress when direct capture is disabled or could not be registered on a custom handler. Ordinary logs that happen to carry another exception are retained.
- Do not install `opentelemetry-logger-monolog`; the bridge is small and this avoids its global/context assumptions.

The channel is not added to `LOG_STACK` automatically. Applications opt in in `logging.php`. Direct exception capture is configured separately through exception instrumentation and the logs signal.

When the whole SDK or logs signal is disabled, resolving an explicitly configured `opentelemetry` channel returns the same handler in a disabled, immediate-return mode rather than an unknown-driver error or a handler that builds dropped OTel records. Its `isHandling()` returns `false` so Monolog can skip it before processor initialization where applicable, and `handle()` still returns `false` immediately because Monolog calls handlers directly when a record is already initialized. Later stack handlers continue receiving the record. Monolog's `NullHandler` is unsuitable because it reports the record as handled and can stop a flattened stack. An application that explicitly retains this channel still pays Monolog's own record/dispatch cost; the package must not claim otherwise.

### Runtime and pool metrics

Use the contrib runtime-metrics package's metric names, units, formulas, availability guards, and batched snapshot pattern as the compatibility reference, but implement the minimum callbacks directly in one `RuntimeInstrumentation`; do not mirror its four-class group hierarchy. Its public `RuntimeMetrics::register()` can disable only the `memory`, `gc`, `cpu`, and `opcache` groups, while the underlying metric classes/config are marked internal. Depending on it would make `php.gc.runs` inseparable from the other GC instruments and cannot satisfy the settled public contract that each advertised metric can be disabled with no instrument/observation path. Direct ownership also permits the required worker-zero OPcache rule without registering then dropping instruments. This is roughly four small source snapshots, not a second runtime-metrics framework; do not retain the contrib package as an unused dependency.

- Memory, GC, and CPU metrics run per worker.
- OPcache runs only in worker type `event`, id `0`, because its shared counters must not be multiplied. Ordinary CLI and custom-process identities are never mistaken for event worker zero.
- Build an instrument only for a true metric switch. Register no snapshot callback when every metric using that source is off.
- When any metrics from a shared source are on, call `memory_get_usage`, `gc_status`, `getrusage`, `opcache_get_status`, `Server::stats()`, or `Coroutine::stats()` once per collection and observe only enabled instruments.
- Missing functions/status keys silently omit unavailable data rather than emitting invented zeroes.

Object-pool metrics use the already-resolved `PoolManager::pools()` snapshots for total/borrowed/idle/max/waiters. Use exact `borrowed` for the `used` state rather than weakening it to the connection-pool derivation. `hypervel.object_pool.name` is the exact registry identity because OTel has no applicable generic object-pool convention and replacing that identity would merge distinct live pools. Framework-generated automatic identities include a construction fingerprint and change with construction input; `pool.name` supplies a stable explicit identity where dashboard continuity is wanted. Direct application pool names may deliberately be dynamic, and `PoolRecycler` may evict them, so backends can accumulate historical series even when the live worker set is small. Document that tradeoff and the existing typed-view/metric-disable controls rather than adding hashing, truncation, an allowlist, a resource-type companion, or another naming layer. A metric collection may legitimately see a series disappear after recycler eviction. Observation never creates or mutates pools.

Register one instance-bound `batchObserve()` callback for all enabled database/Redis pool instruments and another for all enabled object-pool instruments. Each callback enumerates its factories/managers and takes each source snapshot once per collection, then observes only enabled instruments. The bound target lets either the installed SDK's weak-target destructor or the deferred meter's equivalent lifecycle remove the registration when the worker-lifetime instrumentation instance is destroyed, so the instrumentation does not retain a redundant caller token. Do not use static callbacks, one callback per metric, special single-instrument branches, or closed-pool guards for pools removed through their supported managers.

Swoole metrics distinguish per-worker facts from process-global server stats. One batched `Swoole\Server::stats()` snapshot in event worker zero feeds enabled global connections/requests/task values. Per-worker request values use only documented worker-local stats keys; coroutine count comes from one `Coroutine::stats()` snapshot per producing worker. Omit absent keys. Do not relabel `connection_num` as HTTP active requests.

## Diagnostics and failure isolation

- Never call internal `OpenTelemetry\API\Behavior\Internal\Logging::setLogWriter()` and never populate `LoggerHolder` with the application logger.
- Upstream diagnostics therefore use its standard destination (`ErrorLogWriter`/`OTEL_PHP_LOG_DESTINATION`) and cannot recurse through the OTel log channel.
- Catch independently around user-supplied enrichers and URL/user/cache-key/Redis-query-text resolvers, each configured queue-depth backend call, and automatic exporter-scheduler work. Application callbacks rethrow `CanceledException` before their ordinary diagnostic-isolation catch, so cancellation is never logged and swallowed as a resolver/enricher failure. Do not wrap whole package listener/observer bodies or ordinary package-owned attribute extraction: such catches would mask implementation bugs contrary to the repository's fail-fast policy. Upstream SDK no-op/drop behavior remains authoritative where its own contracts contain telemetry failures.
- Manual facade `flush()` is explicit application code and may return failure/throw according to its documented contract; automatic lifecycle flush is best effort.
- Bound queues prevent unbounded telemetry memory. Retain SDK dropped-item counters/diagnostics.

## Performance invariants

1. The built-in graph performs no export, protobuf encoding, compression, DNS, or remote I/O in application request, RPC, query, job, cache, scheduled-task event, log, or exception paths. Explicit queue-depth observation and automatic OTLP export I/O run only in the export scheduler coroutine. A caller that explicitly invokes manual `flush()` chooses to perform export work at that call site. All application/third-party extension code—including provider overrides, processors, exporter drivers, samplers, metric views, instrumentation classes, enrichers, and URL/user/cache-key/Redis resolvers—is documented as responsible for preserving this invariant.
2. One scheduler coroutine per worker, irrespective of the number of signals or metrics.
3. One SDK provider per enabled signal per worker; one exporter per signal.
4. Disabled signals construct no SDK signal graph. Disabled metrics construct no instrument or callback and perform no record call.
5. Event objects added to framework packages remain listener-gated.
6. Configuration parsing, class resolution, attribute allowlist normalization, and instrument creation happen once.
7. Completion-only DB/Redis spans reuse event elapsed time; no wrapper closure on every query.
8. Pool/runtime observables do work only at collection time and inspect only existing state.
9. Metric attribute sets are controlled by design: IDs, raw URLs, raw keys, bindings, payloads, bodies, argv, exception messages, and Scout model classes never become metric dimensions. Standard/application-defined values such as `db.namespace`, messaging destination, Scout index, and recognized RPC method can still be dynamic; document them and provide typed `MetricView` filtering rather than making a false universal value-cardinality claim.
10. No per-operation IPC. The private relay receives each worker's already-batched SDK export payload.
11. Attributes that identify the operation and are already known before the span is created are span-builder input whenever a tracer is enabled, because the SDK passes builder attributes to the sampler and span suppressor before a recording decision exists; compute them once and reuse the same array for an enabled metric. Everything else resolves after one `SpanInterface::isRecording()` branch: trace-only detail such as headers, URLs, query text, bodies, and code attributes; values that are only final after the span exists, such as an injected payload's envelope size; and per-occurrence identifiers such as a message UUID. Completion-time attributes are computed only when a recording span or enabled metric consumes them. Sampling must reduce all remaining package-owned trace-detail work as well as exported spans. The explicit default-off `internal_metrics` tradeoff is the exception: the upstream SDK records bounded span-start/live self-metrics even for spans rejected by the sampler.
12. Queue producer metrics never decode payload JSON. Persistent queue tracing decodes and re-encodes once in `JobPayloadFinalizing` to inject flat propagation and retain the framework UUID as a private correlation key. Terminal correlation uses an exact-payload fast index and performs no decode in the normal listener-order path; only a miss caused by a later finalizer mutation decodes the final payload once to recover that UUID. Built-in background/deferred propagation uses the existing decoded payload hook, while sync processing performs neither operation.
13. The Event allowlist uses passive exact observers and never changes `hasListeners()`; an empty allowlist registers nothing. Disabled View, Scout, and gRPC instrumentation resolves no optional runner and registers no observer. The framework prerequisite plan owns and proves the corresponding no-consumer allocation guards at each operation boundary.
14. User context is resolved at most once per request and only after `hasUser()`; cache-key and Redis-query-text resolvers run only for enabled, recording trace detail. The ordinary-log Context bridge is off by default and performs no Context writes when off.

## Tests

Place package tests under `tests/OpenTelemetry/` and OTel-backed service integration tests under `tests/Integration/OpenTelemetry/`. The [framework prerequisite plan](./2026-08-29-0533-hypervel-observability-framework-prerequisites.md) owns focused tests beside each changed component. Use the root PHPUnit/PHPStan/PHP-CS-Fixer conventions and run the narrowest test after each implementation slice.

### Context/deferred unit tests

- base/current context inside/outside coroutines;
- `scope()`/scope `context()`, per-scope `ArrayAccess` isolation, and nested attach/detach return flags for inactive, detached, stale/double, and out-of-order mismatch depth;
- `fork()` snapshot isolation, keyed-fork allowlists, and `create()` no inheritance;
- O(1) switch/destroy without coroutine-list scan;
- scope cleanup after coroutine destruction and cross-coroutine terminal detach;
- standard `Globals` provider lookup from current context;
- `OTEL_SDK_DISABLED=true` installs no-op signal providers but retains configured propagator inject/extract and coroutine isolation, while an untouched request/coroutine creates no OTel context state;
- provider/tracer, provider/meter/all long-lived instrument kinds/observable callbacks, and provider/logger handles created pre-fork then rebound; one-shot scope-attribute generators remain replayable; creation callbacks remain instrument-owned; observable and batch-observe tokens detach before/after bind, preserve bound-object weak lifetime versus static manual-detach lifetime, detach old delegates on unbind/rebind, map same-meter deferred instruments, diagnose foreign instruments like upstream, and never resurrect; operation builders requested before binding remain no-op rather than becoming another deferred object layer;
- pre-bind observations are dropped, post-bind work reaches only the current delegate, rebind/unbind is idempotent;

### Manager/export tests

- each signal `none` constructs no exporter/provider/processor/reader;
- OTLP shared/per-signal precedence for endpoints, headers, protocol/content type, compression, timeouts, CA certificate, client certificate, and client key; shared-endpoint signal-path appending versus verbatim signal endpoints; signal-qualified protocol/network/TLS errors while the shared retry-limit error remains unqualified; shared and per-signal protobuf selections requiring `ext-protobuf`, a per-signal JSON override bypassing the requirement, configuration validation preceding the environment check, and one extension-gated positive factory test covering all three signals; readable-file and client-certificate/key-pair validation; Guzzle `verify`/`cert`/`ssl_key` mapping; explicit shared Guzzle request/stream/URI factory wiring without PSR discovery; compatible-signal client reuse, incompatible timeout/TLS tuple separation; and the upstream retry limit;
- custom driver precedence, factory method selection, invalid/missing driver errors, and rejection of multiple exporters in one signal variable;
- standard and container-resolved custom sampler, default/float `sampler_arg`, ratio-range validation only for ratio samplers, text/response propagator, array inject/extract conveniences, resource and `OTEL_SERVICE_NAME` precedence, authoritative worker/process identity, non-null resource-schema equality with the installed SDK detectors, and canonical exemplar-filter mappings;
- invalid exemplar membership fails during normalization before any exporter factory runs; metric views and exemplar mapping complete before metric exporter creation; the built-in OTLP driver rejects invalid temporality before transport creation;
- structural types remain enforced for disabled and provider-overridden settings, while correctly typed unused queue/batch ranges, sampler ranges, exemplar values, and undefined exporter records are ignored; cadence positivity remains enforced for every active signal, including provider overrides;
- processor, provider, and metric-view class validation/container resolution after fork; direct `MeterProvider` construction with the populated `CriteriaViewRegistry`;
- upstream `internal_metrics` parsing/default, metrics-provider-first construction, no SDK self-instruments when off or metrics is `none`, and the active package/application meter provider wired into package batch processors/providers when on, exposing queue capacity/size, processed-drop, in-flight, exported, and span-start/live SDK instruments without otherwise mutating an override's graph;
- complete provider-override ownership/ignore semantics and facade/deferred handle delegation to the supplied provider;
- one tick, smallest cadence, per-signal due times, monotonic advancement, stale-run invalidation when stop or restart occurs during an in-flight export, periodic/manual no-overlap, internal `true`/`false`/`null` completed/failed/skipped results, public manual contention mapped to `false`, periodic contention skipped without diagnostics, and closing waiting for an in-flight flush before provider shutdown;
- strict positive validation for every active metrics/trace/log cadence, including custom providers, with disabled-signal zero/negative values ignored because no timer consumes them;
- bounded trace/log queue behavior with `autoFlush: false`, including capacity drops before the package tick, cumulative processed/error drop accounting, coarse queue-size sampling at the independent metrics cadence, and successful drain at the configured trace/log cadence;
- periodic provider `forceFlush`, exact cancellation passed to Timer without diagnostics or continuation, closing provider shutdown exactly once with traces/logs before metrics, final-drain internal metrics collected by the metrics provider's last shutdown collection, and identical lifecycle for built-in/custom providers without a private reader/processor flush registry;
- manual flush result/failure, idempotent shutdown, deferred unbind, retry after a pre-registration provider-construction failure, and one-shot post-bind instrumentation registration: a registration failure escapes unchanged, remains bound, does not register earlier hooks again on a second `bind()`, and retains ordinary shutdown; plus descriptive failure on bind after shutdown (including when container-retained custom span/log processors were closed) and no false hard-deadline guarantee;
- direct exporter work occurs in scheduler coroutine, never observation coroutine;
- slow local OTLP endpoint proves native-cURL/retry sleeps yield: a concurrent heartbeat/request coroutine continues while export is pending, and a test-local exporter decorator records the successful export result without awaiting or changing timing. The JSON case uses `src/engine/examples/http_server.php`'s optional delayed-response body to return the valid empty OTLP JSON response `{}`; changes to that shared fixture rerun both `ClientTest` and `Http2ClientTest`. A separate `RequiresPhpExtension('protobuf')` case uses `/timeout?time=0` with no body parameter, reuses the recording decorator, and proves a real span is natively encoded and accepted with an empty protobuf response;
- `trace()` return, attributes, nesting, ordinary exception status, exact exception-log context/origin handoff before detach/end after worker binding, and rethrow; cancellation detaches context, preserves exact identity, emits no completed span or exception telemetry, and leaves later work on the root context.
- process identity registration after successful provider binding even with an empty instrumentation map, container availability before instrumentation resolution, truthful retention after provider shutdown, and worker scheduler ownership retained when post-bind instrumentation registration throws.
- enabled/propagator settings fixed at master base-context installation; signal/instrumentation settings from rebuilt event/task-worker configuration, standalone CLI startup configuration, or inherited custom-process startup configuration; and documented full-restart versus worker-reload behavior without switchable propagator delegates.

### Configuration/zero-cost tests

For every metric switch, assert both behavior and absence:

- enabled creates the correct instrument and record;
- disabled creates no instrument/callback and does not execute timing/attribute code used only by it;
- only the standard case-insensitive `true` forms of `OTEL_SDK_DISABLED` build no real provider/exporter/scheduler/instrumentation graph and leave signal facade/container calls as safe no-ops, while configured propagators remain available through the installed coroutine-safe base Context exactly as the standard requires; non-standard truthy strings remain enabled;
- disabled SDK/logs still lets an explicitly configured `opentelemetry` channel resolve to a bubbling immediate-return handler without OTel record construction or suppression of later stack handlers;
- domain false and all outputs false register no listeners/middleware/hooks;
- signal `none` structurally validates every instrumentation record before pruning its conventional output; all-signals-`none` disables every built-in and third-party instrumentation, while trace-off propagation-only Queue/third-party entries remain active when another package signal is active; third-party records that declare only traces are pruned with traces off, and records with no recognized output remain active;
- malformed `depth_queues` structure fails even with metrics off, while an empty target map is ignored when the queue-depth metric is signal-pruned and fails when metrics are active for both the per-name map and `metrics: true` shorthand;
- class and built-in/third-party metric boolean shorthands plus per-name maps normalize correctly without enumerating third-party metric names; a partial built-in metric map overrides only named switches, retains every other shipped default, cannot accidentally prune the instrumentation, and remains synchronized with the complete maps in the published config; including parsed `resource_attributes` and additive resource/exporter/instrumentation contributions from another provider;
- the published config resolves representative string/integer/float/enum/list/map/boolean standard variables uniformly through the SDK environment and php.ini; keeps absent per-signal overrides `null`; preserves only `APP_*` on Hypervel `env()`; and fails at config load for wrong getters, invalid integers, malformed maps, or unexpected empty required values. Do not add a package SPI fixture: Composer-script and direct-PHPUnit launches build the upstream resolver singleton at different times, making late fixture registration launch-dependent; upstream owns SPI loading.
- package-built resources/providers honor direct post-fork `OTEL_PHP_DETECTORS` and the SDK's complete signal-specific count/value/event/link limit variables, merge cached explicit resource values last, do not falsely apply the unsupported generic limit fallbacks, and distinguish their non-cached event/task-worker versus custom-process environment lifecycle from published config values;
- a config-cache fixture disables the SDK so it exercises configuration caching without constructing an unrelated exporter graph, then freezes SDK-resolved `OTEL_*` values at cache-build time until rebuild, matching the documented deployment behavior;
- root PHPUnit disables the three built-in OTLP signals through non-forced `OTEL_{TRACES,METRICS,LOGS}_EXPORTER=none` defaults, so framework and root Testbench subprocesses that boot standalone Artisan construct no exporter graph or attempt an export. Package tests configure their own exporters. The dogfood package discovers OpenTelemetry in its parent Artisan process before Testbench's child environment applies, so its own Composer test script and the workflow's direct dogfood step set `OTEL_SDK_DISABLED=true`; the root dogfood script delegates without repeating that setting. Every other dogfood Testbench command needs the same parent-process setting when invoked directly. The exporter factory's extension-free child process and a published-configuration assertion retain coverage of the conditional extension requirement and shipped protobuf default;
- the published config omits the four currently unenforceable batch/metric export-timeout and default-histogram-aggregation settings, while complete provider overrides remain usable and the limitation tests are revisited on OTel dependency upgrades;
- Event empty allowlist, View/Scout/gRPC unavailable or all-output-off, HTTP-client disabled/manual modes, and optional trace-detail switches normalize without resolving optional-package services or registering their runtime hooks;
- invalid types/classes/names fail during boot, not at first request.

### Instrumentation tests

- HTTP server parent extraction, response propagation before send, route rename, configured known/unknown methods, unknown-method span-name component `HTTP` while the attribute remains `_OTHER`, lowercase raw-wire spelling plus `method_original`, no `method_original` for an unchanged known method, and POST `_method` / `X-HTTP-METHOD-OVERRIDE` requests retaining wire-method `POST` on spans/duration metrics without invoking `getMethod()`; absent server authority and redundant protocol-name span/metric attributes, retained protocol version, and a host-validation trap proving instrumentation never calls `getHost()`; `except_paths`/`except_methods`, status/error, known body sizes without stream reads, duration, active-request balance, redaction, send failure, event order, request-test harness parity, WebSocket 101/rejection handshake boundaries, and Reverb retained server identity plus routed/pre-request-rejection paths; user context disabled/default/custom, `hasUser()` no-auth-resolution behavior, lazy one-call exception reuse, post-`ResponseSent` terminable-failure correlation to the ended root span, exact resolver-cancellation propagation without diagnostic swallowing, and coroutine cleanup. With an always-off sampler and duration disabled, completion skips route/protocol/response-error/status attribute work while still balancing active requests and ending the non-recording span. Logs-only user-context mode registers only `RequestReceived`, performs no clock/attribute/completion work, supplies user attributes to request-origin exception logs, and creates no state or user attributes for an excluded request.
- HTTP client explicit-context injection without span activation, unchanged ambient context across asynchronous dispatch/settlement, root/nested propagation, and exact case-sensitive known-method matching at the physical PSR-7 request boundary; a mixed-case easy-API call is observed as uppercase under current Guzzle 7, while a real Nyholm PSR-7 request sent through public `Http::buildClient()->send()` retains its configured lowercase/mixed-case known method; unknown-method attribute `_OTHER` with span-name `HTTP` plus `method_original`, URL-template name update, and no package-owned client-method canonicalization; synchronous startup failure, fulfilled/rejected promise completion, exact cancellation identity with no completion telemetry in synchronous and asynchronous retry modes, one physical send, and no retry predicate/delay; default-configuration 404/500 ERROR status plus status-string `error.type`, exact one-hop wrapper exception-context handoff, duration, always-present `url.full`, userinfo redaction, query-off omission, sensitive-query redaction, implicit 80/443 and explicit server ports on spans/metrics, absence of opt-in URL template/scheme and redundant protocol name on metrics, header privacy, Content-Length-only sizes, and no body buffering; one span per physical send, exact resend ordinals across mixed redirects and Hypervel retries, and a pinned Guzzle `__redirect_count` dependency; automatic `withoutTrace()` and manual `withTrace()` use the exact `hypervel_otel_trace` option, trace-enabled macro collision/absence, option absence/presence behavior, metrics-only no-macro/no-option-lookup behavior, and one middleware path.
- DB success/final ordinary failure/retry-success consumption through `QueryExecuted` and `QueryFailed`, no telemetry for exact cancellation, exact elapsed start, metrics-only operation recording without an SDK clock read, manual `logQuery()` with null time producing no telemetry, conservative operation classifier, query text enabled/disabled, query-specific cap plus `OTEL_SPAN_ATTRIBUTE_VALUE_LENGTH_LIMIT` behavior, no binding or `toRawSql`, raw-literal warning fixture, read/write role, and pool observations. Pool coverage pins literal `database:<physical-name>` / `redis:<connection-name>` values, idle and derived-used counts including a connection unavailable during validation/checkout, max/waiters, incubating constants, one snapshot per source per collection, no pool creation, and no callback/instrument for disabled names.
- Across HTTP, DB, Redis, queue, cache, scheduler, console, Event, View, Scout, and WebSocket instrumentation, unsampled/non-recording spans skip trace-only attribute resolution while independently enabled metrics retain only their required low-cardinality work.
- Redis success/failure/duration/operation, raw configured `hypervel.redis.connection` on spans and duration metrics with distinct logical connections producing distinct series, no guessed physical endpoint/database attributes, uppercase canonicalization across mixed-case callers and formatter lookup, original command passed to a custom resolver, query text default absence, conservative known-command formatting, credentials/values/scripts/messages and unknown/module-command redaction, Unicode cap/null cap, custom resolver replacement/failure, no resolver or SDK clock work on metrics-only paths and no resolver work on non-recording spans, active command outputs enabling events even after earlier pool creation, pool-metrics-only mode leaving events unchanged, pool observations, and exact cancellation producing no completion telemetry.
- Cache span-event/counter names, unit, operation/result/error/store attributes, all hit/miss/write/forget/ordinary-flush/lock-flush/failover outcomes, failover logical/backing dimensions and transition-only interpretation, exact count arithmetic for single/per-key/multi-key completions, and ordinary active-listener dispatch. Cover non-null TTL seconds on `KeyWritten`/`KeyWriteFailed` span events including `putMany()` without derived expiry strings or metric dimensions; key default absence, enum normalization, raw opt-in warning fixture, cap/null cap, custom hash/redaction resolver and failure, bulk-event key omission; per-store event exclusion and memoized-hit/atomic-operation limitations; independently guarded recording-span/counter fast paths with no trace-only detail work on non-recording spans; no listeners when off; and no cache spans.
- Queue listener-gated finalizer absence/allocation, preservation and non-consumption of existing `JobQueueing`, flat propagation round trip from the exact persistent send span, independently disabled propagation with traced terminal correlation and original-payload envelope size, propagation-only extraction/activation, built-in background/deferred payload-hook propagation with no producer telemetry, persistent-only absence of that static hook, built-in sync ambient behavior with no propagator extraction, payload read, or retained consumer state in propagation-only mode; retained-state creation/removal for traced and valid propagation-only asynchronous jobs, with no empty state for an invalid asynchronous carrier; null/custom-driver boundaries, direct `pushRaw()` bypass/manual root injection, no Create span or package envelope, producer non-activation/ambient driver parent, ordinary finalizer failure closing/associating the started producer span with unchanged payload/no committed state/no producer metric, exact cancellation abandonment, trace-disabled producer-metric propagation failure with the same no-commit invariant, exact-payload zero-decode normal correlation, later-finalizer rewrite fallback through one validated terminal decode/UUID lookup even without trace-context propagation, cleanup of exact-payload state and its optional UUID index, missing/non-string UUID exact-only correlation with no message-ID attribute and complete producer spans/metrics, broken-finalizer and earlier-batch-sibling coroutine/process lifetime boundaries without guessed cleanup machinery, trace-disabled propagation-off producer metrics with no decode/span, standard span-only message ID/envelope size/naming, class-string and repeated-object jobs, after-commit boundary, Database/SQS write-back, injected-size overflow/chunk changes, overflow-body parity, multi-chunk duration from finalization, partial SQS per-entry outcomes, mid-batch failure exactly-once terminal coverage for later unsent chunks, producer success/failure, consumed-at-delivery timing, immediate canonical consumer completion across daemon/`--once`/sync/background/deferred execution, exact failure-context handoff, invalid-payload and earlier-`JobProcessing`-listener state misses, later-listener error attribution, consumer success/exception/timeout/failure duplicate guard, ambient-parent link behavior, cross-coroutine cleanup, controlled metric attribute sets, depth off/no callback or I/O, event-worker-zero ownership, no-event-worker documentation, explicit targets, and per-target collection failure isolation.
- A capturing always-off sampler proves Console, Queue producer/consumer, Scheduler, Scout, View, and WebSocket pass their stable operation-identity attributes to sampling and suppression before the recording decision. Queue message UUIDs and envelope sizes remain absent from sampler input and appear only on sampled spans; Scout's model class and batch size remain span-only and never enter duration-metric attributes.
- Scheduler root-span behavior through both normal and background filtered-context hops, success/non-zero/throwable/finished-then-failed guard, concurrent task isolation, and in-task log-context IDs matching the task span before prior command values are restored.
- Console post-provider-boot binding through a later `ArtisanStarting`, a container-resolved Console Application, or an instance published by an earlier direct `Kernel::getArtisan()` call, including `APP_ENV=testing`, first-command instrumentation, no binding for an application that never constructs Artisan, repeated-start idempotence, and no CLI lifecycle registration in server mode. Cover success/non-zero/throwable and final exit-code consumption; lifecycle-owned export scheduling when Console instrumentation is disabled or the command is filtered; nested/concurrent coroutine counting including a coroutine command beneath a non-coroutine command; unowned lifecycle no-op; non-coroutine Generator/Dev command spans with no timer and synchronous terminating export; report-before-shutdown order; shutdown ownership retained when post-bind instrumentation registration throws; nested `Artisan::call()` behavior in CLI and server workers; exact/wildcard allow/exclude precedence and zero-work command instrumentation; and server command exclusion.
- Event exact allowlist, passive observer ordering after success/failure, payload exclusion, recording/non-recording behavior, empty/no-wildcard registration, and proof that observing does not change `hasListeners()` or force a guarded producer to dispatch.
- A guarded cache-write producer dispatches and reaches OTel when Cache instrumentation is its only ordinary listener, proving domain instrumentations did not accidentally use passive observers.
- View success and failure consumption, exact-instance nested state, concurrent coroutine isolation, duration/span attributes, data/output/path exclusion, cancellation producing no completion telemetry, and no observer registration when both outputs are off.
- Scout all six operation descriptors, correct engine-call-only span boundaries and downstream parenting, exact read/write index and batch-size semantics, no payload/model IDs, ordinary/cancellation failure cleanup, nested/concurrent token isolation, absent-package handling, and no runner resolution/registration when outputs are off.
- gRPC unary/client-stream/server-stream/bidirectional client and server logical-call spans, exact metadata propagation/extraction and last-wins replacement, unchanged ambient client context, retained exception correlation, pre-call/preflight failures, retries/backoff under one span, non-blocking final status/failure facts, current RPC names, endpoint/privacy rules, duration metrics, explicit cancel as CANCELLED, runtime cancellation skip, concurrent/nested calls, and no runner resolution/registration when outputs are off. In the gRPC and Scout tests whose local tokens deliberately retain an abandoned scope until method exit, disable upstream `DebugScope` only around the activating call and immediately restore the prior `$_SERVER` value; keep all other scopes under normal assertion-time diagnostics.
- WebSocket message success/failure/cancellation, no completion listener after cancellation, ordinary completion event order, connection gauge balance, Reverb-handler coverage without duplicate protocol-event telemetry, and bodies excluded.
- Exception handler filtering, handled/ignored/throttled/custom-report behavior, one standard log, source timestamp/severity/event name/code constants/optional truthful origin, unsampled trace case, exact ended-operation context handoff and WeakMap release, framework-recognized inner-exception association, one-hop PHP previous-cause lookup, and unrelated custom-map fallback; shared-key identity, inherited request/job/console/schedule/WebSocket/RPC origins, request/WebSocket/process-identity fallbacks, unknown event/task-worker omission, current-context and ended-request-context paths with no registry write, message/stack/body privacy switches, ordered failing enrichers, exception counter, and direct-log dedup.
- Log channel severity/context/correlation/invalid context, no formatter invocation, disabled `isHandling()` plus `handle()` bubbling in flattened stacks with and without processors, exact reporting-exception suppression only when direct capture is registered/enabled, custom-handler/no-direct-capture record retained, normal exception-context log retained, not added to stack automatically.
- Optional ordinary-log trace/span ID Context bridge disabled path, configured keys, invalid span, nested activated-span restoration including prior values/missing keys, sync-job/`Artisan::call()` behavior, and concurrent coroutine isolation.
- Every runtime/server metric switch, shared-source single-snapshot behavior, unavailable-key omission, event-worker-zero-only OPcache/server metrics with no CLI/custom-process duplication, and object-pool `getStats()` waiters consistency. Object-pool tests pin exact borrowed/idle/max/waiter observations plus automatic fingerprint identity, manager-generated `pool.name` identity, and direct `PoolManager::pool('app:reports', ...)` identity; recycler removal permits disappearance; callback registration is instance-bound and container teardown removes it; no resource-type dimension or observation-time pool mutation exists.

Redis-backed instrumentation tests live under `tests/Integration/OpenTelemetry/Redis/`, use `InteractsWithRedis`, and are added to each applicable standalone Redis, Redis Cluster, and Valkey command in `.github/workflows/redis.yml`; placing them under `tests/Integration` alone does not make the service workflow discover them.

### Lifecycle/process tests

Use subprocess/Swoole fixture tests where fork boundaries matter:

- no exporter transport/socket created in master;
- each worker gets unique resource identity and independent aggregation;
- worker-exit coordinator wakes the scheduler for best-effort shutdown, honoring the configured per-attempt transport timeout and retry policy;
- task workers do not report shared OPcache/server metrics;
- CLI timer does not keep `Coroutine::run()` alive;
- non-coroutine commands and processes never start a timer and drain synchronously at their terminating lifecycle;
- nested and concurrent command spans retain their own scopes and only the last outer invocation clears the shared CLI timer;
- coroutine and non-coroutine custom server processes bind usable deferred handles, receive unique process resources, honor the exact-class exclusion list, and unbind/shut down once; `AfterProcessHandle` clears the package tick and shuts down traces/logs before metrics ahead of native timer clearing/`WORKER_EXIT`; an excluded relay remains no-op, provider-construction failure performs no cleanup or replacement throw, and post-bind instrumentation-registration failure closes the retained graph;
- direct OTLP produces per-worker batches; custom fixture relay produces one relay stream without changing instrumentation.
- class-string provider overrides, custom samplers, metric views, processors, and instrumentations are resolved independently in each worker after configuration reload. Package-built providers receive the final merged resource map and unique worker identity; a provider-override fixture proves the package neither builds nor mutates that provider's resource and documents the override's identity responsibility. Boot definitions and resolver closures may be registered pre-fork and copied into workers, but are never invoked in the master and must not capture a resolved provider, exporter, transport, or other live SDK resource there.

### Performance verification

Final methodology, measurements, and recommendations are recorded in the separate [benchmark report](./2026-08-27-1815-hypervel-opentelemetry-package-benchmarks.md) so they remain available without inflating this implementation plan.

Do not substitute architectural assertions for measurement. Before final signoff, run the same warmed Swoole HTTP/DB/queue fixture repeatedly in these modes: package absent, installed with `OTEL_SDK_DISABLED=true`, all three signals `none`, traces enabled with an always-off sampler, and normal enabled instrumentation exporting to a local sink. Use identical worker counts and workloads, take at least five samples, and compare median throughput, p50/p99 latency, worker RSS, CPU time, live coroutine count, and export/request counts.

All baseline modes keep `internal_metrics` off. The disabled and all-signals-`none` modes must show no package-created per-request listeners, timing, context operations, instruments, callbacks, or coroutines; investigate any measurable steady-state delta rather than declaring a percentage acceptable in advance. For unsampled traces, profile allocations to confirm package trace-only attribute resolution is skipped. For enabled signals, use profiling to remove avoidable formatter work, repeated config/container lookups, duplicate time calls, temporary arrays, and repeated source snapshots. Run normal enabled instrumentation a second time with `internal_metrics` on to quantify its per-span cost and capture cumulative processed/drop counts plus coarse queue samples. Do not use the self-observing run as the package's baseline or let good application latency hide telemetry loss. Keep reliable structural assertions in the automated suite, report benchmark commands/results in the implementation handoff, and do not commit a one-off benchmark harness or flaky wall-clock thresholds.

Apply the same scrutiny beyond the representative combined fixture. Exercise each built-in domain alone and the full enabled graph under high coroutine concurrency, then sustain the combined run across many collection/export intervals, worker reloads, failures, and recoveries. Verify bounded RSS and queue/map/stack sizes, cleanup back to baseline after requests/jobs/RPCs/renders/Scout operations complete, no cross-coroutine state leakage, no timer/export overlap, and no backend failure or slow export causing network waits on application paths. Vary application-defined namespace/destination/index/method values, confirm only the documented metric attribute set is emitted, and verify the documented typed views can remove deployment-specific dynamic dimensions. These are implementation-time profiles/stress checks plus deterministic structural tests for ownership and bounds—not a permanent elaborate benchmark framework or timing-sensitive CI gate.

### Test cleanup ownership

An OpenTelemetry test base owns only genuinely shared setup such as the fixture exporter driver and test SDK configuration. For normal coroutine-backed application tests, do not call manager shutdown from `tearDownInCoroutine()`: `RunTestsInCoroutine` resumes `WORKER_EXIT` after that method, so the package scheduler performs its real worker shutdown while the test application still exists. The outer `tearDown()` clears fixture in-memory storage only after the coroutine lifecycle completes. A focused unit test that binds a manager without the worker/CLI/process scheduler must explicitly shut down that manager itself. Each application registration replaces the package-owned Context storage/deferred graph. The framework-owned `Queue::flushState()` already clears static payload callbacks between tests; add no package-specific queue cleanup.

Do not add OTel resets to the global `AfterEachTestSubscriber`: the subscriber runs after the container is destroyed, and this package does not own upstream `Globals`, clock, logger holder, or diagnostic state. If implementation introduces package-owned static state that cannot be removed, redesign it into the manager before adding a global reset.

## Documentation

Create canonical `src/docs/opentelemetry.md`, add it to the documentation navigation, and keep the component README to a short description plus canonical-doc link. The docs cover:

- installation, config publishing, the default built-in protobuf exporter's conditional `ext-protobuf` requirement and startup failure for event/task workers, standalone CLI processes, and custom server processes, the lower-volume JSON alternative and measured high-throughput tradeoff, SDK environment/php.ini/SPI resolution for supported standard `OTEL_*` variables when the config file is evaluated, `config:cache` freezing those published values until rebuild, direct post-fork SDK resolution/lifecycle of `OTEL_PHP_DETECTORS` and signal-specific span/log limits, cached-resource-over-detector precedence, the current PHP SDK limitations that prevent generic limit fallback plus truthful batch/metric export-timeout and default-histogram-aggregation support, complete-provider override guidance, Hypervel-owned `APP_*` fallbacks, full-restart ownership of `enabled`/propagators, rebuilt event/task-worker signal settings, standalone CLI startup settings, inherited custom-process startup settings and its full-restart requirement, HTTP exclusions, and experimental response propagators;
- standard case-sensitive `OTEL_INSTRUMENTATION_HTTP_KNOWN_METHODS` full-replacement behavior; server wire-method canonicalization versus application method overrides; exact outgoing client method handling at the physical PSR-7 boundary; current Guzzle 7 easy-API uppercasing versus case-preserving built-client requests; and why client instrumentation does not copy the server's framework-specific canonicalization;
- strict standard `OTEL_SDK_DISABLED` parsing: only case-insensitive `true` disables signal SDKs; Laravel-style `1`, `yes`, and `on` are invalid, produce an SDK diagnostic, and leave telemetry enabled; configured propagation remains available in disabled mode;
- OTLP/HTTP protobuf default and JSON alternative, complete shared/per-signal endpoint/header/protocol/compression/timeout/CA/mTLS configuration and precedence, custom trust stores, uncompressed upstream default and gzip opt-in, batch structure, and the fact that the package keeps only bounded in-memory SDK queues and stores no telemetry locally;
- batch queue sizing from `max_queue_size` and cadence, the shipped per-worker baseline arithmetic, burst/export-failure effects, and opt-in `OTEL_PHP_INTERNAL_METRICS_ENABLED` tradeoff: cumulative processed/error counters are the reliable drop alert, queue size is a coarse collection-interval sample, and upstream span-start/live records add application-path overhead even for rejected spans; do not describe this as an application job-queue limit;
- export shutdown behavior: trace/log providers shut down before metrics so final-drain SDK self-metrics can be collected; the OTLP timeout applies per HTTP attempt, retries/backoff can extend the total best-effort flush, the current SDK cannot enforce standard batch/metric export timeouts, and standalone CLI shutdown is synchronous with lower transport-timeout/no-retry guidance for CLI-heavy deployments;
- signals and exporter `none` behavior;
- every built-in instrumentation/metric and its default, including HTTP upgrade/Reverb coverage and logical gRPC calls;
- custom standard metrics, spans, logs, propagation, and instrumentation classes;
- a third-party service-provider example that merges its resource attributes and class-keyed instrumentation/exporter defaults through `mergeConfigFrom()`/`mergeableOptions()` so Hypervel replays them after worker config reload;
- `trace()` helper and facade/provider injection;
- log-channel opt-in versus direct exception capture;
- exception enrichers and private-agent enrichment timing;
- query-text default, configurable 500-character query cap plus the SDK's span-specific standard limit, no bindings/interpolation, raw-literal privacy warning, and disable option;
- user context default/custom resolver, `hasUser()` behavior, request-only scope, privacy/cardinality, and request-origin exception reuse;
- HTTP `url.full` credential redaction, the server query-capture privacy divergence, header/query/cache-key/Redis-argument/body cardinality and privacy rules, canonical Redis command casing, conservative Redis formatting, unknown-command behavior, logical Redis connection identity and its pairing with `redis:<connection-name>` pool series without guessed Cluster/Sentinel endpoint or mutable database attributes, cache write TTL seconds on span events, and the cache/Redis resolver examples. The cache section also explains per-store `events` exclusion, custom repositories' ownership of their event integration, memoized local-hit undercounting, absent `add()`/`increment()`/`decrement()` completion coverage, and independent failover transitions with logical-versus-backing store dimensions;
- HTTP client automatic/manual modes and per-request `withTrace()` / `withoutTrace()` controls;
- exact passive Event allowlists, View spans/metrics, Scout operation coverage, and the documented limits of guarded events and engines constructed outside `EngineManager`;
- queue producer semantics: listener-gated final-payload timing plus optional flat injection at persistent async enqueue boundaries; no `JobQueueing` listener; independent tracing/propagation behavior, including trace-disabled metrics without decoding and traced sends without payload mutation; config-gated local background/deferred propagation without producer telemetry; local sync/background/deferred deliveries increment the consumed counter without producer sent/duration telemetry, so the two counters are not comparable when those drivers are used; one exact send context/span per persistent framework job; direct-`pushRaw()` lifecycle limitation/manual root propagation; ordinary/cancellation finalizer-failure ownership and why a failure before the broker attempt has no sent metric; after-commit behavior; ambient process-only sync connections; per-message bulk/failure coverage; span-only message ID/envelope size/naming; multi-chunk SQS duration from finalization; event-worker-zero depth ownership; the uncommon one-decode terminal fallback when another finalizer rewrites the payload afterward; and the broken-finalizer/earlier-batch-sibling non-coroutine process lifetime boundary;
- exporter drivers, provider overrides, custom samplers, typed metric views, upstream retry-limit configuration, internal SDK instruments registering on an application metrics-provider override only when explicitly enabled, and a complete custom relay example;
- application-defined metric dimensions (`db.namespace`, messaging destination, Scout index, recognized RPC method) and a typed `MetricView` example that drops attributes for a deployment's cardinality policy;
- connection-pool naming with the documented `database:` / `redis:` prefixes; object-pool identity/cardinality behavior, including automatic fingerprint churn, stable explicit `pool.name`, deliberately dynamic direct names, recycler-driven series disappearance, and typed-view/metric-disable controls. The framework prerequisite plan owns one concise `src/docs/pools.md` cross-reference to this canonical section without duplicating it;
- why direct OTLP drains per worker, how trace/log `max_export_batch_size` can split one cadence into multiple bounded requests, how `service.instance.id` identifies producers, and when a Collector/sidecar or SaaS relay is useful;
- Collector/Prometheus OTLP ingestion without suggesting a built-in scrape endpoint;
- coroutine inheritance through `fork()` versus isolated `create()`, explicit propagation when bypassing Hypervel's inheritance API, CLI/worker/custom-process lifecycle, Artisan-owned CLI coverage and the direct-command/provider-skipping environment-command limits, process exclusion for a relay, non-coroutine long-running process manual-flush responsibility, shutdown guarantees, and performance model;
- one package-wide explanation that runtime coroutine cancellation emits no completion span, metric, or event and releases coroutine-local telemetry state when that coroutine ends; retain the gRPC-specific distinction for explicit logical `Call::cancel()`;
- gRPC propagation, one logical span across retries/streams, current literal RPC names despite stale installed semconv constants, status/error conventions, the bounded `rpc` exception origin, client endpoint attributes and opt-in server-metric endpoint attributes, and explicit `Call::cancel()`;
- ordinary Hypervel log-context correlation as a default-off convenience distinct from standard OTel log correlation, including nested scope restoration;
- head versus tail sampling, with Collector/backend tail sampling recommended for distributed traces; explain that `traces.processors` are sibling observers and cannot gate built-in export, then include a complete custom `traces.provider` that owns/decorates its downstream processor or relay example and spell out worker-local visibility, bounded traces/spans/bytes, decision timeout, late-span policy, shutdown/flush behavior, and coroutine safety. Do not present an in-process implementation as equivalent to a distributed tail sampler;
- migration/stability note for development OTel DB pool and messaging conventions.

This is original first-party work, so do not add a “ported from” claim.

## Implementation sequence

Each step includes its tests and leaves no compatibility shim or dead alternative behind.

1. Add root/component Composer metadata, package discovery, facade alias, config skeleton, docs skeleton, and dependency/plugin changes. Install/update dependencies and verify autoload.
2. Implement package-owned context storage and its complete unit suite.
3. Implement deferred trace/metric/log graphs and rebind tests.
4. Implement exporter and metric-view contracts, OTLP/console factories, manager provider/resource/sampler/exemplar/view construction, custom provider/sampler/driver resolution, and manager tests, including re-checking and reporting the upstream TLS-argument defect before retaining explicit post-fork Guzzle TLS option wiring.
5. Implement the single scheduler, worker/CLI/custom-process bind/shutdown lifecycles, and process tests.
6. Complete and validate the separately planned [framework prerequisites](./2026-08-29-0533-hypervel-observability-framework-prerequisites.md), then pin package integration tests to their public contracts. Do not duplicate their implementation in this component.
7. Implement HTTP server/client, DB/Redis/pools, cache, queue, scheduler, console, Event, View, Scout, gRPC, WebSocket, exception/log, and runtime instrumentations in that order, running focused tests after each class.
8. Complete the published config/default matrix and zero-cost switch tests.
9. Complete canonical docs/README/navigation and private exporter example.
10. Run focused suites, full unit tests, integration suites relevant to changed packages, PHPStan, PHP-CS-Fixer, Composer validation, and final dead-code/stale-documentation searches.

## Validation commands

Use repository-standard commands, narrowing first and expanding after each phase:

```bash
composer validate --strict
composer test -- --filter OpenTelemetry
vendor/bin/phpunit --no-progress tests/Integration/OpenTelemetry/Redis
composer fix
git diff --check
```

`composer fix` is the required final checkpoint and runs formatting, both PHPStan configurations, the full `composer test:parallel` suite, Testbench, and dogfood. If it fails, follow the repository workflow: correct the failure, then run that failed check and every remaining script entry rather than repeatedly restarting earlier expensive checks.

The dogfood package's own `test` script deliberately sets `OTEL_SDK_DISABLED=true`: its parent Testbench command discovers the package and binds the CLI SDK before PHPUnit or `testbench.yaml` child environment is available. Root `test:dogfood` delegates to that script, while the workflow's direct binary invocation supplies the same setting through the step environment. Dogfood validates Testbench rather than OTLP and must remain independent of the default protobuf exporter's conditional extension requirement.

Also search for forbidden/stale design remnants before completion:

```bash
grep -rnE "Globals::registerInitializer|setLogWriter|memory.*driver|Metric::|Trace::|SemConv\\\\(Resource|Trace)Attribute|Prometheus|StatsD|TODO|FIXME" src/opentelemetry src/docs/opentelemetry.md
```

Interpret matches; documentation may legitimately mention unsupported Prometheus/StatsD topology. Do not mechanically suppress analysis or retain unused code to satisfy a search.

## Completion criteria

- Installing the component gives usable OTLP metrics, traces, and exception logs with one documented configuration surface.
- Pre-fork master state contains no live SDK exporter/provider transport resources.
- Context is isolated across concurrent operations and inherited only through Hypervel `Coroutine::fork()` semantics; bypassing code propagates explicitly.
- Every metric can be independently disabled with no source recording path for that metric.
- Applications and third-party packages can declare standard OTel long-lived handles before fork and record custom instruments/spans normally after worker binding.
- Advanced applications can replace complete signal providers, supply a custom sampler, register metric views, add class-keyed instrumentation, observe Scout/gRPC operations, and use standard/custom propagation without patching the package.
- A private SaaS agent can replace exporters with bounded IPC without patching built-in instrumentation.
- Query text behavior matches the settled Laravel-style default and its privacy boundary is explicit and tested.
- HTTP manual controls, user context, Event/View/Scout coverage, cache-key/Redis detail, and ordinary-log trace correlation remain independently configurable and perform no disabled-path registration or detail work.
- Worker/CLI shutdown exports best effort without blocking application operation paths or retaining timer coroutines.
- High-concurrency and sustained-failure measurements show bounded telemetry memory/state, no cross-coroutine leakage, no application-path exporter I/O, controlled loss under backpressure, controlled metric attribute sets, and documented handling of application-defined dynamic dimensions across the complete built-in graph.
- The package emits current semantic conventions where stable and clearly identifies development conventions.
- No duplicate exception records, recursive telemetry logging, unbounded queues, unsafe Redis serialization, hidden high-cardinality defaults, incomplete logical-RPC lifecycle, local tail-sampling subsystem, stale comments, TODOs, dead compatibility code, or abandoned design variants remain.
