# Hypervel Sentry

- [Introduction](#introduction)
- [Installation](#installation)
    - [Configuration](#configuration)
    - [Replacing the Service Provider](#replacing-the-service-provider)
    - [Testing Your Installation](#testing-your-installation)
- [Reporting Exceptions](#reporting-exceptions)
- [Logging](#logging)
    - [Sentry Events](#sentry-events)
    - [Sentry Logs](#sentry-logs)
- [Performance Monitoring](#performance-monitoring)
    - [Sampling](#sampling)
    - [Queued Jobs](#queued-jobs)
    - [Metrics](#metrics)
    - [Scheduled Tasks](#scheduled-tasks)
- [Filesystem Monitoring](#filesystem-monitoring)
- [Sensitive Data](#sensitive-data)
- [Spotlight](#spotlight)
- [Delivery and Shutdown](#delivery-and-shutdown)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

[Sentry](https://sentry.io) provides error tracking and performance monitoring for your Hypervel application. Hypervel's Sentry integration captures exceptions, logs, requests, database queries, cache operations, queued jobs, notifications, Redis commands, scheduled tasks, and filesystem operations.

The integration is designed for Hypervel's long-running Swoole workers. Request state is isolated between coroutines, while Sentry's HTTP connections are pooled and reused across requests.

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

Once Sentry is registered with Hypervel's exception handler, reportable exceptions are captured automatically. You may also report an exception manually using Sentry's `captureException` function:

```php
use Throwable;

use function Sentry\captureException;

try {
    // ...
} catch (Throwable $exception) {
    captureException($exception);
}
```

Hypervel's normal exception filtering still applies. For example, validation exceptions are ignored by the default Sentry configuration.

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

> [!WARNING]
> Sentry Logs are currently unsupported in Hypervel. The Sentry SDK shares its buffered log records across application executions, so one execution may flush records collected by another. Keep `SENTRY_ENABLE_LOGS` disabled. The regular `sentry` event channel remains fully supported.

The `sentry_logs` channel and its configuration remain available so applications can adopt Sentry Logs when the SDK provides isolated runtime contexts. The channel uses `SENTRY_LOG_LEVEL` and falls back directly to your application's `LOG_LEVEL` value. The upstream `SENTRY_LOGS_LEVEL` compatibility alias is not supported.

<a name="performance-monitoring"></a>
## Performance Monitoring

To enable performance monitoring, configure a trace sample rate between `0.0` and `1.0`:

```ini
SENTRY_TRACES_SAMPLE_RATE=0.1
```

The default configuration traces requests, database queries, HTTP client requests, cache operations, queued jobs, notifications, and views. The conventional `/up` health route path is ignored by default.

Cache spans and breadcrumbs follow cache repository events. Configured stores honor their `events` option, but the `failover` store defaults this option to `false`; enable it explicitly when failover operations should produce telemetry. `Cache::memo()` does not emit a second event for its memoization layer, while operations that reach the wrapped repository follow that store's setting.

Incoming trace headers are still propagated when local trace recording is disabled. This allows a Hypervel service to remain part of a distributed trace without recording its own transaction.

By default, request transactions include work performed after the response is sent. You may finish transactions during the HTTP terminate phase instead:

```ini
SENTRY_TRACE_CONTINUE_AFTER_RESPONSE=false
```

<a name="sampling"></a>
### Sampling

The `traces_sample_rate` option provides a fixed sample rate. For application-specific decisions, you may define a `traces_sampler` callback in `config/sentry.php`:

```php
use Sentry\Tracing\SamplingContext;

'traces_sampler' => function (SamplingContext $context): float {
    $transaction = $context->getTransactionContext();

    return $transaction !== null && str_starts_with($transaction->getName(), 'admin.')
        ? 1.0
        : 0.1;
},
```

Profiling may be configured with `SENTRY_PROFILES_SAMPLE_RATE` or a `profiles_sampler` callback. Profiles are only collected for sampled transactions.

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

This middleware can downsample a transaction that was already sampled by your global configuration. It does not force an unsampled transaction to be recorded.

<a name="metrics"></a>
### Metrics

> [!WARNING]
> Sentry trace metrics are currently unsupported in Hypervel. The Sentry SDK shares its metric aggregators across application executions, so one execution may flush metrics collected by another. This does not affect transaction tracing or spans.

Trace metrics are disabled by default. The `SENTRY_ENABLE_METRICS` option remains available, but enabling it is unsupported until the SDK can isolate metric aggregators between application executions.

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

Sentry does not include personally identifiable information by default. You may allow it using the `SENTRY_SEND_DEFAULT_PII` environment variable:

```ini
SENTRY_SEND_DEFAULT_PII=true
```

Command breadcrumbs omit command-line input unless this option is enabled. When enabled, the raw command arguments and options are sent to Sentry, so do not pass credentials on the command line.

Redis command parameters are omitted unless this option is enabled. When enabled, the active session key is still redacted from Redis and cache telemetry.

SQL bindings are controlled separately using `SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED` and `SENTRY_TRACE_SQL_BINDINGS_ENABLED`. Review these settings carefully before enabling them in production.

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

<a name="delivery-and-shutdown"></a>
## Delivery and Shutdown

Sentry events are sent from detached coroutines using a bounded pool of reusable HTTP transports. Normal requests and queued jobs do not wait for event delivery, and commands use the same non-blocking delivery by default. Sends started outside a coroutine complete before returning so short-lived CLI processes cannot exit while an accepted send is still running. If the pool is exhausted during an exception storm, new telemetry is dropped instead of delaying application work.

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
