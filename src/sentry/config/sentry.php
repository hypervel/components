<?php

declare(strict_types=1);

use Hypervel\Sentry\Features\CacheFeature;
use Hypervel\Sentry\Features\ConsoleIntegration;
use Hypervel\Sentry\Features\ConsoleSchedulingFeature;
use Hypervel\Sentry\Features\DbQueryFeature;
use Hypervel\Sentry\Features\HttpClientIntegration;
use Hypervel\Sentry\Features\LogFeature;
use Hypervel\Sentry\Features\NotificationsFeature;
use Hypervel\Sentry\Features\QueueFeature;
use Hypervel\Sentry\Features\RedisFeature;
use Hypervel\Sentry\Integrations\RequestIntegration;
use Hypervel\Validation\ValidationException;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\TransactionIntegration;

return [
    'dsn' => env('SENTRY_DSN', ''),

    // Whether to enable default integrations (includes ModulesIntegration)
    'default_integrations' => env('SENTRY_DEFAULT_INTEGRATIONS', false),

    // The release version of your application
    // Example with dynamic git hash: trim(exec('git log --pretty="%h" -n1 HEAD'))
    'release' => env('SENTRY_RELEASE'),

    // When left empty or `null` the environment will be used (usually discovered from `APP_ENV` in your `.env`)
    'environment' => env('APP_ENV', 'production'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#sample_rate
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    // Switch tracing on/off
    'enable_tracing' => env('SENTRY_ENABLE_TRACING', true),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sample_rate
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sampler
    // 'traces_sampler' => function (Sentry\Tracing\SamplingContext $context): float {
    //     return env('SENTRY_TRACES_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_TRACES_SAMPLE_RATE');
    // },

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#profiles_sample_rate
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env(
        'SENTRY_PROFILES_SAMPLE_RATE'
    ),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_logs
    'enable_logs' => env('SENTRY_ENABLE_LOGS', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send_default_pii
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    'breadcrumbs' => [
        // Capture Hypervel cache events (hits, writes etc.) as breadcrumbs
        'cache' => env('SENTRY_BREADCRUMBS_CACHE', true),
        // Capture SQL queries as breadcrumbs
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES', true),
        // Capture SQL query bindings (parameters) in SQL query breadcrumbs
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS', true),
        // Capture SQL transactions (begin, commit, rollbacks) as breadcrumbs
        'sql_transaction' => env('SENTRY_BREADCRUMBS_SQL_TRANSACTION', true),
        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),
        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
        // Capture log messages as breadcrumbs
        'logs' => env('SENTRY_BREADCRUMBS_LOGS', true),
        // Capture artisan command information as breadcrumbs
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_INFO', true),
    ],

    'integrations' => [
        RequestIntegration::class,
        TransactionIntegration::class,
        FrameContextifierIntegration::class,
        EnvironmentIntegration::class,
    ],

    'features' => [
        CacheFeature::class,
        QueueFeature::class,
        NotificationsFeature::class,
        LogFeature::class,
        ConsoleIntegration::class,
        ConsoleSchedulingFeature::class,
        DbQueryFeature::class,
        HttpClientIntegration::class,
        RedisFeature::class,
    ],

    'ignore_exceptions' => [
        ValidationException::class,
    ],

    'ignore_transactions' => [
    ],

    'ignore_commands' => [
        'crontab:run',
        'make:*',
        'migrate*',
        'tinker',
        'vendor:publish',
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        // Enable default tracing integrations
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS', true),
        // Capture view rendering as spans
        'views' => env('SENTRY_TRACE_VIEWS', true),
        // Capture HTTP client requests as spans
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS', true),
        // Capture SQL queries as spans
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),
        // Capture SQL query bindings (parameter values) in query spans
        'sql_bindings' => env('SENTRY_TRACE_SQL_BINDINGS_ENABLED', false),
        // Capture where a SQL query originated from on the query span
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),
        // Minimum query duration (in ms) before the origin is captured on the query span
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),
        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),
        // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),
        // Capture Hypervel cache events (hits, writes etc.) as spans
        'cache' => env('SENTRY_TRACE_CACHE_ENABLED', true),
        // Capture send notifications as spans
        'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),
        // Capture Redis operations as spans (this enables Redis events in Hypervel)
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', true),
        // Capture where the Redis command originated from on the Redis command spans
        'redis_origin' => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),
        // Discard transactions for routes that were not matched (404s, etc.)
        'missing_routes' => env('SENTRY_TRACE_MISSING_ROUTES', false),
    ],

    'http_timeout' => (float) env('SENTRY_HTTP_TIMEOUT', 2.0),

    // HTTP transport pool configuration for async Sentry event sending via Swoole coroutines.
    // wait_timeout is set low (10ms) so pool exhaustion fails fast (backpressure)
    // rather than blocking request coroutines for seconds during exception storms.
    'pool' => [
        'min_objects' => 1,
        'max_objects' => 10,
        'wait_timeout' => 0.01,
        'max_lifetime' => 60.0,
    ],
];
