<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | When set to false, Reverb will not register any routes, bindings, or
    | background tasks. The package remains installed but completely inert.
    | This is useful when the package is pulled in as a dependency but
    | WebSocket support is not needed in a particular environment.
    |
    */

    'enabled' => env('REVERB_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting messages to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            /*
            |--------------------------------------------------------------
            | Multi-Instance Scaling via Redis
            |--------------------------------------------------------------
            |
            | Hypervel Reverb automatically scales across all Swoole
            | workers on a single server using shared memory — no
            | external dependencies required. This is sufficient for
            | most workloads.
            |
            | Enable this only when running multiple Reverb instances
            | behind a load balancer. When enabled, Redis pub/sub
            | coordinates broadcasts across instances, replacing the
            | shared-memory coordination used in single-instance mode.
            |
            | Shared memory is significantly faster than Redis, so
            | scaling a single instance by increasing the worker count
            | will often outperform adding more instances. Use the
            | 'reverb' connection in database.redis to configure
            | which Redis server to use.
            |
            */

            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'connection' => env('REVERB_SCALING_CONNECTION', 'reverb'),
            ],
            /*
            |--------------------------------------------------------------
            | Swoole Shared State
            |--------------------------------------------------------------
            |
            | In single-instance mode (scaling disabled), Reverb uses a
            | Swoole Table — a fixed-size shared memory hash map — to
            | track channel subscription counts, presence member counts,
            | and per-app connection limits across all workers.
            |
            | Each active channel and each unique user in a presence
            | channel consumes one row. A typical busy app with 1,000
            | channels and 200 presence channels averaging 50 users
            | each uses ~11,000 rows. The default of 65,536 is
            | sufficient for most workloads. Reverb logs a warning
            | at 80% capacity.
            |
            | This setting has no effect when scaling is enabled —
            | Redis is used for shared state instead.
            |
            */

            'swoole_shared_state' => [
                'rows' => env('REVERB_SWOOLE_SHARED_STATE_ROWS', 65536),

                // Rows for the webhook throttle/dedupe lock table. Only
                // used for subscription_count throttling, cache_miss
                // deduplication, and disconnect smoothing markers. A small
                // fraction of channels need lock rows, so this can be much
                // smaller than the main table.
                'lock_rows' => env('REVERB_SWOOLE_SHARED_STATE_LOCK_ROWS', 8192),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [
        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),
                'rate_limiting' => [
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
                /*
                |--------------------------------------------------------------
                | Webhooks
                |--------------------------------------------------------------
                |
                | Configure a webhook URL to receive Pusher-compatible webhook
                | notifications when channel lifecycle events occur. Uncomment
                | the events you want to receive. Webhooks are delivered via
                | queued jobs on the "reverb-webhooks" Redis queue. Payloads
                | are signed with HMAC-SHA256 using the app secret and include
                | an X-Pusher-Key header for app identification.
                |
                | Enable batching for production workloads — it consolidates
                | many events into fewer HTTP requests, significantly reducing
                | queue and network overhead at scale.
                |
                */

                'webhooks' => [
                    'url' => env('REVERB_WEBHOOK_URL'),

                    'events' => [
                        // 'channel_occupied',
                        // 'channel_vacated',
                        // 'member_added',
                        // 'member_removed',
                        // 'client_event',
                        // 'cache_miss',
                    ],

                    'headers' => [
                        // 'Authorization' => 'Bearer ' . env('REVERB_WEBHOOK_TOKEN'),
                        // 'X-Webhook-Source' => 'reverb',
                    ],

                    'filter' => [
                        'channel_name_starts_with' => env('REVERB_WEBHOOK_CHANNEL_PREFIX'),
                        'channel_name_ends_with' => env('REVERB_WEBHOOK_CHANNEL_SUFFIX'),
                    ],

                    // Enable subscription_count webhooks, which fire on every
                    // subscribe/unsubscribe for non-presence channels. Throttled
                    // to once per 5 seconds for channels with over 100 subscribers.
                    // Controlled separately from the events list above.
                    'subscription_count' => env('REVERB_WEBHOOK_SUBSCRIPTION_COUNT', false),

                    // Delay in milliseconds before firing channel_vacated and
                    // member_removed webhooks after a client disconnects. If the
                    // client reconnects within this window, both the removal and
                    // the subsequent re-addition webhooks are suppressed. Set to
                    // 0 to disable and fire immediately on disconnect.
                    'disconnect_smoothing_ms' => env('REVERB_WEBHOOK_DISCONNECT_SMOOTHING_MS', 3000),

                    'timeout' => env('REVERB_WEBHOOK_TIMEOUT', 5),
                    'retries' => env('REVERB_WEBHOOK_RETRIES', 3),
                    'retry_delay' => env('REVERB_WEBHOOK_RETRY_DELAY', 1),

                    'batching' => [
                        'enabled' => env('REVERB_WEBHOOK_BATCHING_ENABLED', false),
                        'max_events' => env('REVERB_WEBHOOK_BATCHING_MAX_EVENTS', 50),
                        'max_delay_ms' => env('REVERB_WEBHOOK_BATCHING_MAX_DELAY_MS', 250),
                        'max_payload_bytes' => env('REVERB_WEBHOOK_BATCHING_MAX_PAYLOAD_BYTES', 262144),
                    ],
                ],
            ],
        ],
    ],
];
