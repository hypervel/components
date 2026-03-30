<?php

declare(strict_types=1);

use Hypervel\Sentry\Features\CacheFeature;
use Hypervel\Sentry\Features\ConsoleIntegration;
use Hypervel\Sentry\Features\ConsoleSchedulingFeature;
use Hypervel\Sentry\Features\HttpClientIntegration;
use Hypervel\Sentry\Features\LogFeature;
use Hypervel\Sentry\Features\NotificationsFeature;
use Hypervel\Sentry\Features\QueueFeature;
use Hypervel\Sentry\Features\RedisFeature;
use Hypervel\Validation\ValidationException;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\TransactionIntegration;

/*
 * Sentry Hypervel SDK configuration file.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [
    // @see https://docs.sentry.io/concepts/key-terms/dsn-explainer/
    'dsn' => env('SENTRY_HYPERVEL_DSN'),

    // @see https://spotlightjs.com/
    // 'spotlight' => env('SENTRY_SPOTLIGHT', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#logger
    // 'logger' => Sentry\Logger\DebugFileLogger::class, // By default this will log to `storage_path('logs/sentry.log')`

    // The release version of your application
    // Example with dynamic git hash: trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD'))
    'release' => env('SENTRY_RELEASE'),

    // When left empty or `null` the Hypervel environment will be used (usually discovered from `APP_ENV` in your `.env`)
    'environment' => env('SENTRY_ENVIRONMENT'),

    // Override the organization ID used for trace continuation checks.
    'org_id' => env('SENTRY_ORG_ID') === null ? null : (int) env('SENTRY_ORG_ID'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#sample_rate
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sample_rate
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#profiles_sample_rate
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    // Only continue incoming traces when the organization IDs are compatible with this SDK instance.
    'strict_trace_continuation' => env('SENTRY_STRICT_TRACE_CONTINUATION', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_logs
    'enable_logs' => env('SENTRY_ENABLE_LOGS', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#log_flush_threshold
    'log_flush_threshold' => env('SENTRY_LOG_FLUSH_THRESHOLD') === null ? null : (int) env('SENTRY_LOG_FLUSH_THRESHOLD'),

    // The minimum log level that will be sent to Sentry as logs using the `sentry_logs` logging channel
    'logs_channel_level' => env('SENTRY_LOG_LEVEL', env('SENTRY_LOGS_LEVEL', env('LOG_LEVEL', 'debug'))),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send_default_pii
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_exceptions
    // 'ignore_exceptions' => [],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_transactions
    'ignore_transactions' => [],

    // Breadcrumb specific configuration
    'breadcrumbs' => [
        // Capture log messages as breadcrumbs
        'logs' => env('SENTRY_BREADCRUMBS_LOGS_ENABLED', true),

        // Capture cache events (hits, writes etc.) as breadcrumbs
        'cache' => env('SENTRY_BREADCRUMBS_CACHE_ENABLED', true),

        // Capture SQL queries as breadcrumbs
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query breadcrumbs
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED', false),

        // Capture SQL transactions (begin, commit, rollbacks) as breadcrumbs
        'sql_transactions' => env('SENTRY_BREADCRUMBS_SQL_TRANSACTIONS_ENABLED', true),

        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),

        // Capture command information as breadcrumbs
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),

        // Capture HTTP client request information as breadcrumbs
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),

        // Capture SQL queries as spans
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query spans
        'sql_bindings' => env('SENTRY_TRACE_SQL_BINDINGS_ENABLED', false),

        // Capture where the SQL query originated from on the SQL query spans
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),

        // Define a threshold in milliseconds for SQL queries to resolve their origin
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),

        // Capture views rendered as spans
        'views' => env('SENTRY_TRACE_VIEWS_ENABLED', true),

        // Capture HTTP client requests as spans
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture cache events (hits, writes etc.) as spans
        'cache' => env('SENTRY_TRACE_CACHE_ENABLED', true),

        // Capture Redis operations as spans (this enables Redis events in Hypervel)
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

        // Capture where the Redis command originated from on the Redis command spans
        'redis_origin' => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),

        // Capture send notifications as spans
        'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),

        // Enable tracing for requests without a matching route (404's)
        'missing_routes' => env('SENTRY_TRACE_MISSING_ROUTES_ENABLED', false),

        // Enable the tracing integrations supplied by Sentry (recommended)
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hypervel-Specific Configuration
    |--------------------------------------------------------------------------
    |
    | The following options are specific to Hypervel and are not present in the
    | upstream sentry-laravel package. They configure Swoole-specific behavior
    | such as the async transport pool and feature registration.
    |
    */

    // Additional SDK integrations to register (resolved from the container)
    'integrations' => [
        TransactionIntegration::class,
        FrameContextifierIntegration::class,
        EnvironmentIntegration::class,
    ],

    // Feature classes to register (Hypervel loads from config, upstream hardcodes in service provider)
    'features' => [
        CacheFeature::class,
        QueueFeature::class,
        NotificationsFeature::class,
        LogFeature::class,
        ConsoleIntegration::class,
        ConsoleSchedulingFeature::class,
        HttpClientIntegration::class,
        RedisFeature::class,
    ],

    // Exceptions that should not be reported to Sentry
    'ignore_exceptions' => [
        ValidationException::class,
    ],

    // Artisan commands that should not be traced
    'ignore_commands' => [
        'crontab:run',
        'make:*',
        'migrate*',
        'tinker',
        'vendor:publish',
    ],

    // HTTP timeout for the Sentry SDK transport (seconds)
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
