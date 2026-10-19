# Hypervel Sentry

- [Introduction](#introduction)
- [Installation](#installation)
    - [Configuration](#configuration)
    - [Replacing the Service Provider](#replacing-the-service-provider)
    - [Testing Your Installation](#testing-your-installation)
- [Reporting Exceptions](#reporting-exceptions)
    - [Model Violations](#model-violations)
- [Logging](#logging)
    - [Sentry Events](#sentry-events)
    - [Sentry Logs](#sentry-logs)
- [Performance Monitoring](#performance-monitoring)
    - [Sampling](#sampling)
    - [Distributed Tracing](#distributed-tracing)
    - [Queued Jobs](#queued-jobs)
    - [AI Monitoring](#ai-monitoring)
    - [Metrics](#metrics)
    - [Scheduled Tasks](#scheduled-tasks)
    - [OpenTelemetry](#opentelemetry)
- [Filesystem Monitoring](#filesystem-monitoring)
- [Sensitive Data](#sensitive-data)
- [Spotlight](#spotlight)
- [Customizing the Integration](#customizing-the-integration)
    - [Features](#features)
    - [Client Builder](#client-builder)
- [Delivery and Shutdown](#delivery-and-shutdown)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

[Sentry](https://sentry.io) provides error tracking and performance monitoring for your Hypervel application. Hypervel's Sentry integration captures exceptions, logs, requests, database queries, cache operations, queued jobs, notifications, Redis commands, scheduled tasks, and filesystem operations.

The integration is designed for Hypervel's long-running Swoole workers. Sentry state is isolated across requests, queued jobs, scheduled tasks, and WebSocket callbacks, while HTTP connections are pooled and reused across executions. An execution remains active until the child coroutines sharing its runtime context have finished.

[Detached background coroutines](/docs/{{version}}/coroutines#detached-background-work) do not inherit the creator's scope, request, or runtime context. Values copied into a detached child explicitly, such as by `forkOwned`, are kept: a copied Sentry scope is cloned, so the child's changes do not reach the creator, while a copied request is shared rather than cloned. Sentry starts an execution when a child handles an HTTP request, queued job, scheduled task, or WebSocket event through the usual framework lifecycle. Without an execution, logs and metrics use the SDK's shared global buffer, which is not automatically flushed when detached work finishes.

<a name="installation"></a>
## Installation

You may install the Sentry integration using the Composer package manager:

```shell
composer require hypervel/sentry
```

Next, register Sentry with Hypervel's exception handler in your application's `bootstrap/app.php` file:

```php
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Sentry\Integration;

->withExceptions(function (Exceptions $exceptions): void {
    Integration::handles($exceptions);
})
```

Finally, run the `sentry:publish` Artisan command. This command publishes the Sentry configuration file, stores your DSN in the application's `.env` file, and can send a test event:

```shell
php artisan sentry:publish --dsn=https://examplePublicKey@o0.ingest.sentry.io/0
```

<a name="configuration"></a>
### Configuration

After publishing Sentry's configuration, its primary configuration file will be located at `config/sentry.php`. Each option includes a description of its purpose.

Sentry first reads the `SENTRY_HYPERVEL_DSN` environment variable. If it is not set, the generic `SENTRY_DSN` variable is used instead:

```ini
SENTRY_HYPERVEL_DSN=https://examplePublicKey@o0.ingest.sentry.io/0
```

You may leave the DSN unset to disable event delivery while keeping the package installed, unless Spotlight is enabled.

The `breadcrumbs` and `tracing` groups may be replaced with partial arrays. Omitted built-in members retain the defaults shown in the published configuration file, while application-defined SDK options remain unchanged.

<a name="replacing-the-service-provider"></a>
### Replacing the Service Provider

You may extend `SentryServiceProvider` when your application needs to bind Sentry under a different container and configuration key:

```php
use Hypervel\Sentry\SentryServiceProvider as BaseSentryServiceProvider;

class SentryServiceProvider extends BaseSentryServiceProvider
{
    public static string $abstract = 'custom-sentry';
}
```

Add `hypervel/sentry` to `extra.hypervel.dont-discover` in your application's `composer.json`, then register the custom provider in `bootstrap/providers.php`. The custom provider replaces the discovered provider; registering both is not supported because they would share one Sentry SDK hub while reading different configuration roots.

<a name="testing-your-installation"></a>
### Testing Your Installation

You may send a test event using the `sentry:test` Artisan command:

```shell
php artisan sentry:test
```

To test both error reporting and performance monitoring, include the `transaction` option:

```shell
php artisan sentry:test --transaction
```

<a name="reporting-exceptions"></a>
## Reporting Exceptions

Once Sentry is registered with Hypervel's exception handler, reportable exceptions are captured automatically. Hypervel's normal exception filtering still applies, so exceptions the handler does not report, such as validation exceptions, are not sent to Sentry.

You may also capture exceptions and messages yourself using the Sentry SDK's [`captureException` and `captureMessage` functions](https://docs.sentry.io/platforms/php/guides/laravel/usage/).

<a name="model-violations"></a>
### Model Violations

Eloquent can [prevent lazy loading](/docs/{{version}}/eloquent-relationships#preventing-lazy-loading), as well as [silently discarded and missing attributes](/docs/{{version}}/eloquent#configuring-eloquent-strictness). Rather than throwing an exception in production, you may report these violations to Sentry from the `boot` method of your application's `AppServiceProvider`:

```php
use Hypervel\Database\Eloquent\Model;
use Hypervel\Sentry\Integration;

Model::preventLazyLoading();

if ($this->app->isProduction()) {
    Model::handleLazyLoadingViolationUsing(
        Integration::lazyLoadingViolationReporter()
    );
}
```

Likewise, the `discardedAttributeViolationReporter` and `missingAttributeViolationReporter` methods may be passed to `Model::handleDiscardedAttributeViolationUsing` and `Model::handleMissingAttributeViolationUsing`.

By default, each violation is reported once per request or job, after it finishes. You may change this behavior, or pass a closure that should also be called for each violation:

```php
use Hypervel\Support\Facades\Log;

Model::handleLazyLoadingViolationUsing(
    Integration::lazyLoadingViolationReporter(
        function (Model $model, string $relation): void {
            Log::warning('Lazy loading violation', ['model' => $model::class, 'relation' => $relation]);
        },
        suppressDuplicateReports: false,
        reportAfterResponse: false,
    )
);
```

<a name="logging"></a>
## Logging

Hypervel registers two Sentry log channels. The `sentry` channel creates Sentry events, while the `sentry_logs` channel sends records to Sentry's structured Logs product.

<a name="sentry-events"></a>
### Sentry Events

You may add the `sentry` channel to a log stack in your application's `config/logging.php` file:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['single', 'sentry'],
    ],
],
```

Log records sent through this channel are converted into Sentry events. Exceptions included in the log context retain their exception details and stack trace.

<a name="sentry-logs"></a>
### Sentry Logs

The `sentry_logs` channel sends structured records to Sentry Logs. You may add it to your application's default log stack using the `LOG_STACK` environment variable:

```ini
LOG_STACK=single,sentry_logs
```

Records are isolated between concurrent requests, queued jobs, scheduled tasks, and WebSocket callbacks, then flushed when that execution finishes. If a single execution can produce a large number of records, you may use `SENTRY_LOG_FLUSH_THRESHOLD` to send them in batches of the given size before it finishes. Each batch is a separate request to Sentry.

The channel uses `SENTRY_LOG_LEVEL` and falls back directly to your application's `LOG_LEVEL` value. The upstream `SENTRY_LOGS_LEVEL` compatibility alias is not supported.

The `SENTRY_ENABLE_LOGS` option is kept for compatibility but is deprecated and no longer turns logs on or off. To disable Sentry Logs, configure `before_send_log` to return `null`.

<a name="performance-monitoring"></a>
## Performance Monitoring

To enable performance monitoring, configure a trace sample rate between `0.0` and `1.0`:

```ini
SENTRY_TRACES_SAMPLE_RATE=0.1
```

The default configuration traces requests, database queries, HTTP client requests, cache operations, queued jobs, notifications, and views. The conventional `/up` health route path is ignored by default.

Cache spans and breadcrumbs follow cache repository events. Configured stores honor their `events` option, but the `failover` store defaults this option to `false`; enable it explicitly when failover operations should produce telemetry. `Cache::memo()` does not emit a second event for its memoization layer, while operations that reach the wrapped repository follow that store's setting.

HTTP client spans and breadcrumbs cover every Guzzle client, including the HTTP client and SDKs built on Guzzle, such as the AWS SDK. When a request takes longer than `SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_THRESHOLD_MS` (250 milliseconds by default), its span also records the application code that sent it. You may disable this with `SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_ENABLED=false`. To leave a client or a single request out entirely, set the `sentry_enabled` option to `false`:

```php
use GuzzleHttp\Client;
use Hypervel\Support\Facades\Http;

$client = new Client(['sentry_enabled' => false]);

Http::withOptions(['sentry_enabled' => false])->get('https://example.com/health');
```

By default, request transactions include work performed after the response is sent. You may finish transactions during the HTTP terminate phase instead:

```ini
SENTRY_TRACE_CONTINUE_AFTER_RESPONSE=false
```

The `breadcrumbs` and `tracing` groups in `config/sentry.php` turn individual instrumentation on or off. For example, Redis commands are only traced when `SENTRY_TRACE_REDIS_COMMANDS` is enabled, and requests that do not match a route are only traced when `SENTRY_TRACE_MISSING_ROUTES_ENABLED` is enabled.

You may also add your own spans using the Sentry SDK's [custom instrumentation](https://docs.sentry.io/platforms/php/guides/laravel/tracing/instrumentation/custom-instrumentation/). Child coroutines inherit the current span, so the spans they start belong to the parent's transaction.

<a name="sampling"></a>
### Sampling

The `traces_sample_rate` option provides a fixed sample rate. For application-specific decisions, you may define a `traces_sampler` callback in `config/sentry.php`. Use a static method rather than a closure, since closures prevent your configuration from being cached. This also applies to the other callback options, such as `before_send`:

```php
'traces_sampler' => [App\Support\SentrySampler::class, 'tracesSampler'],
```

```php
namespace App\Support;

use Sentry\Tracing\SamplingContext;

class SentrySampler
{
    /**
     * Determine the sample rate for a transaction.
     */
    public static function tracesSampler(SamplingContext $context): float
    {
        if ($context->getParentSampled()) {
            return 1.0;
        }

        $transaction = $context->getTransactionContext();

        return $transaction !== null && str_starts_with($transaction->getName(), '/admin')
            ? 1.0
            : 0.1;
    }
}
```

Returning `1.0` when the parent transaction was sampled keeps distributed traces complete. HTTP transactions are sampled before routing, so their name is still the request path.

Profiling may be configured with `SENTRY_PROFILES_SAMPLE_RATE` or a `profiles_sampler` callback. Profiles are only collected for sampled transactions.

> [!WARNING]
> Profiling is disabled by default. A profile can include work from other requests running at the same time in the same worker, whether profiling is enabled with `SENTRY_PROFILES_SAMPLE_RATE` or a `profiles_sampler` callback. Error reporting and tracing are not affected.

<a name="distributed-tracing"></a>
### Distributed Tracing

Requests that include Sentry trace headers continue the incoming trace, and outgoing HTTP client requests and queued jobs carry the trace onward. Incoming trace headers are still propagated when local trace recording is disabled, so a Hypervel service can remain part of a distributed trace without recording its own transaction.

To connect a page's frontend telemetry to the request that rendered it, add the Sentry meta tags to the `<head>` of your Blade layout. Sentry's JavaScript SDK reads these tags to continue the trace:

```blade
<head>
    {!! \Hypervel\Sentry\Integration::sentryMeta() !!}
</head>
```

<a name="queued-jobs"></a>
### Queued Jobs

Trace context is propagated through queued jobs automatically. If a particular job should use a lower sample rate than the rest of your application, add the `SentryTracesSampleRate` middleware to the job:

```php
use Hypervel\Sentry\Jobs\Middleware\SentryTracesSampleRate;

/**
 * Get the middleware the job should pass through.
 */
public function middleware(): array
{
    return [new SentryTracesSampleRate(0.1)];
}
```

This middleware can downsample a transaction that was already sampled by your global configuration. It does not force an unsampled transaction to be recorded. Since it acts on the job's own transaction, job transactions must remain enabled using `SENTRY_TRACE_QUEUE_ENABLED`. A job that runs within another transaction, such as one dispatched synchronously during a request or by another job, keeps that transaction's sampling decision.

<a name="ai-monitoring"></a>
### AI Monitoring

When the [AI SDK](/docs/{{version}}/ai-sdk) is installed, Sentry records agent calls, model steps, tool execution, embeddings, and classifications within sampled transactions. Spans include provider and model names, token usage, and conversation IDs when available. Console commands do not start transactions unless they run as scheduled tasks, so wrap AI calls made from other commands in a transaction of your own using Sentry's [custom instrumentation](https://docs.sentry.io/platforms/php/guides/laravel/tracing/instrumentation/custom-instrumentation/).

Each model step gets a `gen_ai.chat` span. HTTP attempts appear beneath that step when HTTP tracing is enabled, so a retry does not create another model step. Streaming spans begin when the stream is consumed and remain open until it completes, fails, or is abandoned.

The following options in the `tracing` section of `config/sentry.php` are enabled by default:

| Option | Records |
| --- | --- |
| `gen_ai` | Enables AI monitoring. Set to `false` to disable all AI spans. |
| `gen_ai_invoke_agent` | Agent invocations and their model steps. |
| `gen_ai_chat` | Individual model steps within agent invocations. |
| `gen_ai_execute_tool` | Tool execution. |
| `gen_ai_embeddings` | Embedding generation. |
| `gen_ai_evaluate` | Classifications. |

Remembering agents supply their conversation IDs automatically. Classifications join the conversation of an agent in the same trace, including classifications made from the agent's tools. You may also associate subsequent AI operations in the current trace with a conversation of your own, such as a classification made without an agent or AI calls that span multiple requests. A transaction must be active when you set the conversation:

```php
use Hypervel\Sentry\Integration;

Integration::setConversationId($conversationId);

// Run AI operations associated with this conversation...

Integration::setConversationId(null);
```

A top-level agent's conversation remains selected for later AI operations in that coroutine. Nested agents restore the caller's selection when they finish. Child coroutines inherit the selected conversation, but selecting another conversation in a child does not change the parent's selection.

Prompts, system instructions, attachments, responses, tool arguments and results, embedding inputs, and classification content follow the [sensitive data settings](#sensitive-data). With the default `data_collection: null`, they are omitted unless `send_default_pii` is enabled. The names and descriptions of tools that run are always sent, and tool definitions, including their schemas, are sent by default. If you configure `data_collection`, its `gen_ai.inputs` and `gen_ai.outputs` settings control this data instead, with tool definitions treated as inputs; both settings default to `true` when omitted:

```php
'data_collection' => [
    'gen_ai' => [
        'inputs' => false,
        'outputs' => false,
    ],
],
```

Attachment telemetry contains declared metadata, remote URLs, or provider file IDs, never file contents or local storage paths. User credentials, query parameters and fragments are removed from URLs, but URL paths and filenames may still contain sensitive information. Long text is shortened, and message history leaves out the oldest messages first when it exceeds the size limit. Oversized structured fields are omitted rather than sent as incomplete JSON.

<a name="metrics"></a>
### Metrics

You may record trace metrics using the Sentry SDK's [`traceMetrics` function](https://docs.sentry.io/platforms/php/guides/laravel/metrics/). Metrics are isolated between concurrent requests, queued jobs, scheduled tasks, and WebSocket callbacks, then flushed when that execution finishes.

The `SENTRY_ENABLE_METRICS` option is kept for compatibility but is deprecated and no longer turns metrics on or off. To disable trace metrics, configure `before_send_metric` to return `null`.

<a name="scheduled-tasks"></a>
### Scheduled Tasks

You may monitor a scheduled task using the `sentryMonitor` macro:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('reports:generate')
    ->daily()
    ->sentryMonitor('daily-reports');
```

The task's schedule is detected automatically. You may provide a different cron expression when the monitor should use a schedule that cannot be derived from the event:

```php
Schedule::command('reports:generate')
    ->sentryMonitor('daily-reports', schedule: '0 2 * * *');
```

When no monitor slug is given, one is derived from the task's command or description. The macro also accepts the `checkInMargin`, `maxRuntime`, `failureIssueThreshold`, and `recoveryThreshold` monitor settings. Pass `updateMonitorConfig: false` when the monitor is configured in Sentry instead.

Sentry expects a check-in for every run of the task's schedule. Tasks limited by `between`, `unlessBetween`, `when`, or `skip` do not check in when they are skipped, so their skipped runs are reported as missed. Prefer expressing the schedule with the `cron` method.

<a name="opentelemetry"></a>
### OpenTelemetry

If your application traces requests using [Hypervel's OpenTelemetry integration](/docs/{{version}}/opentelemetry), you may send those traces to Sentry instead of using Sentry's own performance monitoring. Point the OTLP trace exporter at your Sentry project's OTLP traces endpoint, authenticating with your DSN's public key:

```ini
OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=https://o0.ingest.sentry.io/api/0/integration/otlp/v1/traces/
OTEL_EXPORTER_OTLP_TRACES_HEADERS="x-sentry-auth=sentry sentry_key=examplePublicKey"
```

Metrics and logs are exported separately, so send them to another destination or [disable them](/docs/{{version}}/opentelemetry#signals). See Sentry's [OTLP documentation](https://docs.sentry.io/concepts/otlp/direct/traces/) for the endpoint details.

To link Sentry errors and logs to the active OpenTelemetry trace, add Sentry's `OTLPIntegration` with its automatic exporter setup disabled, since Hypervel already exports the traces. Bind the integration in the `register` method of a service provider:

```php
use Sentry\Integration\OTLPIntegration;

$this->app->bind(OTLPIntegration::class, fn (): OTLPIntegration => new OTLPIntegration(setupOtlpTracesExporter: false));
```

Then add it to the `integrations` list in `config/sentry.php`:

```php
'integrations' => [
    Sentry\Integration\OTLPIntegration::class,
],
```

The integration is skipped while Sentry's own tracing is enabled, so leave `traces_sample_rate` and `traces_sampler` unset.

<a name="filesystem-monitoring"></a>
## Filesystem Monitoring

Sentry can record filesystem operations as spans and breadcrumbs. Wrap one disk using `StorageIntegration::configureDisk`, or wrap every configured disk using `configureDisks`:

```php
use Hypervel\Sentry\Features\Storage\Integration as StorageIntegration;

'disks' => StorageIntegration::configureDisks([
    'local' => [
        'driver' => 'local',
        'root' => storage_path('app/private'),
    ],

    's3' => [
        'driver' => 's3',
        // ...
    ],
]),
```

To configure a single disk, pass its name and configuration:

```php
'archive' => StorageIntegration::configureDisk('archive', [
    'driver' => 's3',
    // ...
]),
```

Both methods accept `enableSpans` and `enableBreadcrumbs` arguments. Per-disk settings cannot enable telemetry that is disabled globally.

Filesystem spans and breadcrumbs are enabled globally by default. You may disable either form of telemetry using `SENTRY_TRACE_STORAGE_ENABLED` or `SENTRY_BREADCRUMBS_STORAGE_ENABLED`:

```ini
SENTRY_TRACE_STORAGE_ENABLED=false
SENTRY_BREADCRUMBS_STORAGE_ENABLED=false
```

The integration preserves filesystem pooling, scoped prefixes, temporary URLs, streaming behavior, and fluent filesystem operations.

<a name="sensitive-data"></a>
## Sensitive Data

By default, Sentry leaves out data that identifies your users, such as their IP addresses and account details. Other data it sends, such as request URLs and bodies, may still contain personal information. You may include identifying data using the `SENTRY_SEND_DEFAULT_PII` environment variable:

```ini
SENTRY_SEND_DEFAULT_PII=true
```

When this option is enabled, events also include cookies, the client's IP address, and the authenticated user's ID, email address, and username, including users authenticated with Sanctum tokens. Request headers are sent either way, but the values of sensitive headers, such as `Authorization`, `Cookie`, and `X-Forwarded-For`, are filtered unless this option is enabled.

For finer control, you may set the `data_collection` option in `config/sentry.php`. It replaces `send_default_pii` and lets you collect or omit each category of data individually, such as headers, cookies, request bodies, the authenticated user, and query bindings. Categories you do not set use Sentry's defaults, which collect more than Sentry does when `send_default_pii` is disabled, so review them before enabling the option. See Sentry's [data collection reference](https://docs.sentry.io/platforms/php/guides/laravel/data-management/data-collection/) for the available categories. The `php artisan about` command shows whether the option is set.

Command breadcrumbs omit command-line input unless `send_default_pii` is enabled. When enabled, the raw command arguments and options are sent to Sentry, so do not pass credentials on the command line.

Once the `data_collection` option is set, command breadcrumbs instead include the arguments and options that differ from their defaults, as separate `input.arguments` and `input.options` arrays. Values whose names suggest secrets, such as `password` or `token`, are replaced with `[Filtered]`.

Redis command parameters are omitted unless `send_default_pii` is enabled. When they are included, the active session key is still redacted from Redis and cache telemetry.

SQL bindings are controlled separately using `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED` and `SENTRY_TRACE_SQL_BINDINGS_ENABLED`. Review these settings carefully before enabling them in production.

Once the `data_collection` option is set, its `database_query_data` setting controls both SQL bindings and Redis command parameters instead.

<a name="spotlight"></a>
## Spotlight

[Sentry Spotlight](https://spotlightjs.com/) displays Sentry telemetry locally while you develop your application. You may enable its default endpoint using `true`, or provide a custom Spotlight URL:

```ini
SENTRY_SPOTLIGHT=true
```

```ini
SENTRY_SPOTLIGHT=http://localhost:8969/stream
```

Spotlight may be used without configuring a Sentry DSN.

<a name="customizing-the-integration"></a>
## Customizing the Integration

<a name="features"></a>
### Features

The `features` list in `config/sentry.php` determines which parts of your application Sentry instruments. Remove a feature from the list to skip its instrumentation entirely. You may also add your own feature by extending `Hypervel\Sentry\Features\Feature`:

```php
namespace App\Sentry;

use App\Events\OrderShipped;
use Hypervel\Sentry\Features\Feature;
use Hypervel\Sentry\Integration;
use Sentry\Breadcrumb;

class OrderFeature extends Feature
{
    /**
     * Indicate if the feature is applicable to the current environment.
     */
    public function isApplicable(): bool
    {
        return true;
    }

    /**
     * Set up the feature in the environment.
     */
    public function onBoot(): void
    {
        $this->container->make('events')->listen(OrderShipped::class, function (OrderShipped $event): void {
            Integration::addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'orders',
                'Order shipped',
                ['order_id' => $event->order->id],
            ));
        });
    }
}
```

Then add the class to the `features` list:

```php
'features' => [
    // ...
    App\Sentry\OrderFeature::class,
],
```

Features are resolved from the container. The `register` method runs while the service provider registers. When the provider boots, an applicable feature's `onBoot` method runs if a DSN or Spotlight is configured, and its `onBootInactive` method runs otherwise. Features resolve their dependencies from `$this->container`, and their instances are shared by every request, so keep request-specific state out of their properties.

<a name="client-builder"></a>
### Client Builder

You may customize how the Sentry client is built by extending the `Sentry\ClientBuilder` binding from the `register` method of a service provider:

```php
use Sentry\ClientBuilder;
use Sentry\Serializer\RepresentationSerializer;

$this->app->extend(ClientBuilder::class, function (ClientBuilder $clientBuilder): ClientBuilder {
    $clientBuilder->setRepresentationSerializer(
        new RepresentationSerializer($clientBuilder->getOptions(), 5)
    );

    return $clientBuilder;
});
```

The builder uses Hypervel's pooled transport by default. Setting your own transport on the builder replaces the asynchronous delivery described below. Setting an HTTP client keeps it, since the pooled transports send through your client. A transport or HTTP client you provide is shared by concurrent requests, so it must be safe for concurrent use.

<a name="delivery-and-shutdown"></a>
## Delivery and Shutdown

Buffered logs and metrics are flushed when their execution finishes. Sentry envelopes are then sent from detached coroutines using a bounded pool of reusable HTTP transports. Requests, queued jobs, scheduled tasks, and WebSocket callbacks do not wait for event delivery, and commands use the same non-blocking delivery by default. Sends started outside a coroutine complete before returning so short-lived CLI processes cannot exit while an accepted send is still running.

If no transport becomes available within `sentry.pool.wait_timeout`, or the pool has closed, the event is skipped. Unexpected transport creation errors reach the Sentry SDK's error handling instead of being treated as pool exhaustion.

Graceful worker shutdown performs a bounded drain after the worker-exit coordinator is released, then closes the transport pool. Delivery during a worker exit is best effort because Swoole may terminate outstanding reactor work after its shutdown deadline.

The Sentry HTTP timeout and Swoole's worker shutdown timeout are configured independently. Your server's `server.settings.max_wait_time` value should be greater than `SENTRY_HTTP_TIMEOUT`, with enough additional time for other shutdown work:

```ini
SENTRY_HTTP_TIMEOUT=2
```

```php
// config/server.php
use Swoole\Constant;

'settings' => [
    Constant::OPTION_MAX_WAIT_TIME => 3,
],
```

Increasing these values does not change normal request latency. They only bound transport operations and graceful shutdown work.

<a name="credits"></a>
## Credits

Hypervel Sentry began as a port of [Sentry Laravel](https://github.com/getsentry/sentry-laravel) and has been adapted for Hypervel's framework architecture and coroutine runtime.
