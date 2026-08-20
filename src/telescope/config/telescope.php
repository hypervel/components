<?php

declare(strict_types=1);

use Hypervel\Telescope\Http\Middleware\Authorize;
use Hypervel\Telescope\Watchers;

$queueDelay = env('TELESCOPE_QUEUE_DELAY', 10);

return [
    /*
    |--------------------------------------------------------------------------
    | Telescope Master Switch
    |--------------------------------------------------------------------------
    |
    | This option may be used to disable all Telescope watchers regardless
    | of their individual configuration, which simply provides a single
    | and convenient way to enable or disable Telescope data storage.
    |
    */

    'enabled' => (bool) env('TELESCOPE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Telescope Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Telescope will be accessible from. If the
    | setting is omitted or null, Telescope will reside under the same domain
    | as the application. Otherwise, this value will be used as the subdomain.
    |
    */

    'domain' => env('TELESCOPE_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Telescope Path
    |--------------------------------------------------------------------------
    |
    | This required value is the URI path prefix for Telescope's dashboard
    | and API routes. Feel free to change it to any path your application
    | and infrastructure expose.
    |
    */

    'path' => env('TELESCOPE_PATH', 'telescope'),

    /*
    |--------------------------------------------------------------------------
    | Telescope Storage Driver
    |--------------------------------------------------------------------------
    |
    | This option determines the storage driver used for Telescope's data.
    | The database connection identifies where its tables reside, while the
    | chunk size controls how many entries are inserted in each batch. When
    | omitted, the chunk size uses the database repository's default.
    |
    */

    'driver' => env('TELESCOPE_DRIVER', 'database'),

    'storage' => [
        'database' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
            'chunk' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telescope Defer
    |--------------------------------------------------------------------------
    |
    | This option determines whether Telescope storage should be deferred
    | until the current coroutine finishes. When omitted, storage is deferred.
    |
    */

    'defer' => (bool) env('TELESCOPE_STORE_DEFER', true),

    /*
    |--------------------------------------------------------------------------
    | Telescope Queue
    |--------------------------------------------------------------------------
    |
    | These options determine the queue connection and queue which will be
    | used to process ProcessPendingUpdates jobs. A null connection or queue
    | uses the queue worker defaults, while a null or non-positive delay
    | dispatches follow-up updates without a delay.
    |
    */

    'queue' => [
        'connection' => env('TELESCOPE_QUEUE_CONNECTION'),
        'queue' => env('TELESCOPE_QUEUE'),
        'delay' => $queueDelay === null ? null : (int) $queueDelay,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telescope Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware are assigned to every Telescope route. The Authorize
    | middleware enforces Telescope's access policy and should only be removed
    | when it is replaced with equivalent protection.
    |
    */

    'middleware' => [
        'web',
        Authorize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed / Ignored Paths & Commands
    |--------------------------------------------------------------------------
    |
    | A non-empty only-paths list limits recording to matching requests. The
    | ignore-paths and ignore-commands lists add exclusions. Omitted lists are
    | treated as empty, while some framework paths and commands are always
    | ignored by Telescope.
    |
    */

    'only_paths' => [
        // 'api/*'
    ],

    'ignore_paths' => [
        '.well-known*',
    ],

    'ignore_commands' => [],

    /*
    |--------------------------------------------------------------------------
    | Telescope Watchers
    |--------------------------------------------------------------------------
    |
    | The following array lists the "watchers" that will be registered with
    | Telescope. The watchers gather the application's profile data when
    | a request or task is executed. Feel free to customize this list.
    |
    */

    'watchers' => [
        Watchers\BatchWatcher::class => (bool) env('TELESCOPE_BATCH_WATCHER', true),

        Watchers\CacheWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_CACHE_WATCHER', true),
            'hidden' => [],
            'ignore' => [],
        ],

        Watchers\ClientRequestWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_CLIENT_REQUEST_WATCHER', true),
            'ignore_hosts' => [],
            'request_size_limit' => (int) env('TELESCOPE_HTTP_CLIENT_REQUEST_SIZE_LIMIT', 64),
            'response_size_limit' => (int) env('TELESCOPE_HTTP_CLIENT_RESPONSE_SIZE_LIMIT', 64),

            // When false (default), oversized payloads are replaced with "Purged By Telescope"
            // without reading or processing the body — the most performant option. When true,
            // the full body is read, sensitive fields are masked, and the result is truncated
            // to the size limit with a "(truncated...)" suffix, giving partial visibility at
            // the cost of additional memory and CPU for large payloads.
            'truncate_oversized' => (bool) env('TELESCOPE_HTTP_CLIENT_TRUNCATE_OVERSIZED', false),
        ],

        Watchers\CommandWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_COMMAND_WATCHER', true),
            'ignore' => [],
        ],

        Watchers\DumpWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_DUMP_WATCHER', true),
            'always' => (bool) env('TELESCOPE_DUMP_WATCHER_ALWAYS', false),
        ],

        // Hypervel skips firing most events when no listeners are registered,
        // as a performance optimization. EventWatcher uses a catch-all wildcard
        // listener and is treated as a passive observer, so it will not cause
        // listener-guarded events to be fired just for Telescope.
        Watchers\EventWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_EVENT_WATCHER', true),
            'ignore' => [],
        ],

        Watchers\ExceptionWatcher::class => (bool) env('TELESCOPE_EXCEPTION_WATCHER', true),

        Watchers\GateWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_GATE_WATCHER', true),
            'ignore_abilities' => [],
            'ignore_packages' => true,
            'ignore_paths' => [],
        ],

        Watchers\JobWatcher::class => (bool) env('TELESCOPE_JOB_WATCHER', true),

        Watchers\LogWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_LOG_WATCHER', true),
            'level' => 'error',
        ],

        Watchers\MailWatcher::class => (bool) env('TELESCOPE_MAIL_WATCHER', true),

        Watchers\ModelWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_MODEL_WATCHER', true),
            'events' => ['eloquent.*'],
            'hydrations' => true,
        ],

        Watchers\NotificationWatcher::class => (bool) env('TELESCOPE_NOTIFICATION_WATCHER', true),

        Watchers\QueryWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_QUERY_WATCHER', true),
            'ignore_packages' => true,
            'ignore_paths' => [],
            'slow' => 100,
        ],

        Watchers\RedisWatcher::class => (bool) env('TELESCOPE_REDIS_WATCHER', true),

        // Reverb — enabling message_received or message_sent adds a database write per
        // WebSocket message and should only be used for targeted debugging, not sustained
        // production use.
        Watchers\ReverbWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_REVERB_WATCHER', true),
            'events' => [
                'connection_established',
                'connection_closed',
                'channel_created',
                'channel_removed',
                'connection_pruned',
                // 'message_received',
                // 'message_sent',  // Warning: fires per subscriber per broadcast — high volume.
            ],
            'message_size_limit' => (int) env('TELESCOPE_REVERB_MESSAGE_SIZE_LIMIT', 64), // KB
        ],

        Watchers\RequestWatcher::class => [
            'enabled' => (bool) env('TELESCOPE_REQUEST_WATCHER', true),
            'size_limit' => (int) env('TELESCOPE_RESPONSE_SIZE_LIMIT', 64), // KB
            'ignore_http_methods' => [],
            'ignore_status_codes' => [],
        ],

        Watchers\ScheduleWatcher::class => (bool) env('TELESCOPE_SCHEDULE_WATCHER', true),
        Watchers\ViewWatcher::class => (bool) env('TELESCOPE_VIEW_WATCHER', true),
    ],
];
