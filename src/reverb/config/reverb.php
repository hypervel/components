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
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'connection' => env('REVERB_SCALING_CONNECTION', 'default'),
            ],
            'table' => [
                'rows' => env('REVERB_TABLE_ROWS', 65536),
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
                */

                'webhooks' => [
                    'url' => env('REVERB_WEBHOOK_URL'),
                    'events' => [
                        // 'channel_occupied',
                        // 'channel_vacated',
                        // 'member_added',
                        // 'member_removed',
                        // 'client_event',
                    ],
                    'timeout' => env('REVERB_WEBHOOK_TIMEOUT', 5),
                    'retries' => env('REVERB_WEBHOOK_RETRIES', 3),
                    'retry_delay' => env('REVERB_WEBHOOK_RETRY_DELAY', 1),
                ],
            ],
        ],
    ],
];
