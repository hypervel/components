<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful_domains' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        env('APP_URL') ? ',' . parse_url(env('APP_URL'), PHP_URL_HOST) : ''
    ))),

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    | Set to null to rely only on each token's expires_at value.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Last Used Timestamp
    |--------------------------------------------------------------------------
    |
    | When enabled, Sanctum records the time a personal access token last
    | completed authentication successfully.
    |
    */

    'last_used_at' => (bool) env('SANCTUM_LAST_USED_AT', true),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware below as required. Set an entry
    | to null to omit that middleware from the stateful request pipeline.
    |
    */

    'middleware' => [
        'authenticate_session' => \Hypervel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => \Hypervel\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => \Hypervel\Foundation\Http\Middleware\PreventRequestForgery::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Caching
    |--------------------------------------------------------------------------
    |
    | When enabled, Sanctum will cache token and tokenable lookups to improve
    | performance. The last_used_at timestamp will be updated at the specified
    | interval instead of on every request to reduce database writes. The TTL
    | is the maximum time a cached tokenable identity may remain stale. A
    | null store uses the default cache store. The update interval accepts
    | zero to write the last-used timestamp after every authentication.
    |
    */

    'cache' => [
        'enabled' => (bool) env('SANCTUM_CACHE_ENABLED', false),
        'store' => env('SANCTUM_CACHE_STORE'),
        'ttl' => (int) env('SANCTUM_CACHE_TTL', 300),
        'prefix' => env('SANCTUM_CACHE_PREFIX', 'sanctum'),
        'last_used_at_update_interval' => filter_var(
            env('SANCTUM_LAST_USED_UPDATE_INTERVAL', 300),
            FILTER_VALIDATE_INT,
            FILTER_NULL_ON_FAILURE,
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Disable route registration when the application provides its own CSRF
    | cookie endpoint. The prefix applies to Sanctum's built-in route.
    |
    */

    'routes' => true,

    'prefix' => 'sanctum',
];
