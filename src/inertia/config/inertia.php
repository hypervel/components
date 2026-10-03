<?php

declare(strict_types=1);

$devtoolsEnabled = env('INERTIA_DEVTOOLS_ENABLED');

return [
    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configure if and how Inertia uses Server Side Rendering
    | to pre-render the initial visits made to your application's pages.
    |
    | You can specify a custom SSR bundle path, or set it to null to let
    | Inertia try and automatically detect it for you.
    | Omitted SSR members use the defaults shown below.
    |
    | Do note that enabling these options will NOT automatically make SSR work,
    | as a separate rendering service needs to be available. To learn more,
    | please visit https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),

        'runtime' => env('INERTIA_SSR_RUNTIME', 'node'),

        'ensure_runtime_exists' => (bool) env('INERTIA_SSR_ENSURE_RUNTIME_EXISTS', false),

        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        'hot_url' => env('INERTIA_SSR_HOT_URL'),

        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', true),

        'bundle' => null,

        /*
        |--------------------------------------------------------------------------
        | SSR Timeouts
        |--------------------------------------------------------------------------
        |
        | Configure the connection and total timeouts for SSR requests, in seconds.
        | Short timeouts let pages fall back to client-side rendering quickly when
        | the SSR server is slow or unresponsive. Set either option to null to use
        | the HTTP client's global timeout instead.
        |
        */

        'connect_timeout' => ($timeout = env('INERTIA_SSR_CONNECT_TIMEOUT', 2)) === null ? null : (float) $timeout,

        'timeout' => ($timeout = env('INERTIA_SSR_TIMEOUT', 5)) === null ? null : (float) $timeout,

        /*
        |--------------------------------------------------------------------------
        | SSR Backoff
        |--------------------------------------------------------------------------
        |
        | When the SSR server cannot be reached or returns a malformed response,
        | the worker will skip SSR for this many seconds before retrying. This
        | prevents every coroutine from flooding an unavailable server.
        |
        */

        'backoff' => (float) env('INERTIA_SSR_BACKOFF', 5.0),

        /*
        |--------------------------------------------------------------------------
        | SSR Error Handling
        |--------------------------------------------------------------------------
        |
        | When SSR rendering fails, Inertia gracefully falls back to client-side
        | rendering. Set throw_on_error to true to throw an exception instead.
        | This is useful for E2E testing where you want SSR errors to fail loudly.
        |
        | You can also listen for the Hypervel\Inertia\Ssr\SsrRenderFailed event
        | to handle failures in your own way (e.g., logging, error tracking).
        |
        */

        'throw_on_error' => (bool) env('INERTIA_SSR_THROW_ON_ERROR', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Set `ensure_pages_exist` to true if you want to enforce that Inertia page
    | components exist on disk when rendering a page. This is useful for
    | catching missing or misnamed components.
    |
    | The `paths` and `extensions` options define where to look for page
    | components and which file extensions to consider. They are required;
    | `ensure_pages_exist` may be omitted and defaults to false.
    |
    */

    'pages' => [
        'ensure_pages_exist' => false,

        'paths' => [
            resource_path('js/pages'),
        ],

        'extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | When using `assertInertia`, the assertion attempts to locate the
    | component as a file relative to the `pages.paths` AND with any of
    | the `pages.extensions` specified above.
    |
    | You can disable this behavior by setting `ensure_pages_exist`
    | to false. Omission keeps it enabled.
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Expose Shared Prop Keys
    |--------------------------------------------------------------------------
    |
    | When enabled, each page response includes a `sharedProps` metadata key
    | listing the top-level prop keys that were registered via `Inertia::share`.
    | The frontend can use this to carry shared props over during instant visits.
    |
    */

    'expose_shared_prop_keys' => true,

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | Enable `encrypt` to encrypt page data before it is stored in the
    | browser's history state, preventing sensitive information from
    | being accessible after logout. Can also be enabled per-request
    | or via the `inertia.encrypt` middleware. Omission leaves encryption
    | disabled.
    |
    */

    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | DevTools
    |--------------------------------------------------------------------------
    |
    | Records one entry per request to disk so the DevTools Chrome extension may
    | read it back over HTTP. When `enabled` is null, recording is limited to
    | your local environment. Omitted DevTools members use the defaults shown
    | below. See https://inertiajs.com/docs/devtools for the gate and storage
    | options.
    |
    */

    'devtools' => [
        'enabled' => $devtoolsEnabled === null ? null : (bool) $devtoolsEnabled,

        'except' => ['telescope*', 'horizon*', '_inertia/devtools*'],

        'storage' => [
            'path' => storage_path('inertia-devtools'),

            'ttl' => (int) env('INERTIA_DEVTOOLS_TTL_HOURS', 24),

            'prune_interval' => (int) env('INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS', 300),

            'limit' => (int) env('INERTIA_DEVTOOLS_LIMIT', 100),
        ],

        'middleware' => ['web'],

        'gate' => env('INERTIA_DEVTOOLS_GATE'),

        'redact' => [
            'keys' => [
                'password',
                'password_confirmation',
                'current_password',
                'token',
                '_token',
                'access_token',
                'refresh_token',
                'secret',
                'client_secret',
                'api_key',
            ],

            'headers' => [
                'cookie',
                'set-cookie',
                'authorization',
                'proxy-authorization',
                'x-xsrf-token',
                'x-csrf-token',
            ],
        ],
    ],
];
