<?php

declare(strict_types=1);

/**
 * This file is part of Hypervel components.
 *
 * @link     https://github.com/hypervel/components
 * @document https://github.com/hypervel/components/blob/main/README.md
 * @contact  albert@hypervel.org
 */

use FriendsOfHyperf\Sentry\Integration\RequestIntegration;
use Hypervel\Sentry\Features\CacheFeature;
use Hypervel\Sentry\Features\ConsoleSchedulingFeature;
use Hypervel\Sentry\Features\LogFeature;
use Hypervel\Sentry\Features\NotificationsFeature;
use Hypervel\Sentry\Features\QueueFeature;
use Hypervel\Validation\ValidationException;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\TransactionIntegration;

use function Hyperf\Support\env;

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
    //     if (str_contains($context->getTransactionContext()->getDescription(), '/health')) {
    //         return 0;
    //     }
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

    // Must instanceof Psr\Log\LoggerInterface
    // 'logger' => Hyperf\Contract\StdoutLoggerInterface::class,

    'enable' => [
        'amqp' => env('SENTRY_ENABLE_AMQP', false),
        'async_queue' => env('SENTRY_ENABLE_ASYNC_QUEUE', false),
        'command' => env('SENTRY_ENABLE_COMMAND', false),
        'crontab' => env('SENTRY_ENABLE_CRONTAB', false),
        'kafka' => env('SENTRY_ENABLE_KAFKA', false),
        'request' => env('SENTRY_ENABLE_REQUEST', true),
        'cache' => env('SENTRY_ENABLE_CACHE', true),
    ],

    'breadcrumbs' => [
        'cache' => env('SENTRY_BREADCRUMBS_CACHE', true),
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES', true),
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS', false),
        'sql_transaction' => env('SENTRY_BREADCRUMBS_SQL_TRANSACTION', false),
        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),
        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
        'redis' => env('SENTRY_BREADCRUMBS_REDIS', true),
        'guzzle' => env('SENTRY_BREADCRUMBS_GUZZLE', true),
        'logs' => env('SENTRY_BREADCRUMBS_LOGS', true),
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
        ConsoleSchedulingFeature::class,
    ],

    'ignore_exceptions' => [
        ValidationException::class,
    ],

    'ignore_transactions' => [
        'GET /health',
    ],

    'ignore_commands' => [
        'crontab:run',
        'gen:*',
        'migrate*',
        'tinker',
        'vendor:publish',
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        'enable' => [
            'amqp' => env('SENTRY_TRACING_ENABLE_AMQP', true),
            'async_queue' => env('SENTRY_TRACING_ENABLE_ASYNC_QUEUE', true),
            // Capture queue jobs as spans when executed on the sync driver
            'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),
            // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
            'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),
            'cache' => env('SENTRY_TRACING_ENABLE_CACHE', true),
            'command' => env('SENTRY_TRACING_ENABLE_COMMAND', true),
            'crontab' => env('SENTRY_TRACING_ENABLE_CRONTAB', true),
            'kafka' => env('SENTRY_TRACING_ENABLE_KAFKA', true),
            'missing_routes' => env('SENTRY_TRACING_ENABLE_MISSING_ROUTES', true),
            'request' => env('SENTRY_TRACING_ENABLE_REQUEST', true),
            // Capture send notifications as spans
            'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),
        ],
        'spans' => [
            'coroutine' => env('SENTRY_TRACING_SPANS_COROUTINE', true),
            'db' => env('SENTRY_TRACING_SPANS_DB', true),
            'elasticsearch' => env('SENTRY_TRACING_SPANS_ELASTICSEARCH', true),
            'guzzle' => env('SENTRY_TRACING_SPANS_GUZZLE', true),
            'rpc' => env('SENTRY_TRACING_SPANS_RPC', true),
            'grpc' => env('SENTRY_TRACING_SPANS_GRPC', true),
            'redis' => env('SENTRY_TRACING_SPANS_REDIS', true),
            'sql_queries' => env('SENTRY_TRACING_SPANS_SQL_QUERIES', true),
        ],
        'extra_tags' => [
            'exception.stack_trace' => true,
            'amqp.result' => true,
            'annotation.result' => true,
            'db.result' => true,
            'elasticsearch.result' => true,
            'response.body' => true,
            'redis.result' => true,
            'rpc.result' => true,
        ],
    ],

    'crons' => [
        'enable' => env('SENTRY_CRONS_ENABLE', false),
        'checkin_margin' => (int) env('SENTRY_CRONS_CHECKIN_MARGIN', 5),
        'max_runtime' => (int) env('SENTRY_CRONS_MAX_RUNTIME', 15),
        'timezone' => env('SENTRY_CRONS_TIMEZONE', date_default_timezone_get()),
    ],

    'http_timeout' => (float) env('SENTRY_HTTP_TIMEOUT', 2.0),
];
