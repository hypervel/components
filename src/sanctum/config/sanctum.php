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

    'last_used_at' => env('SANCTUM_LAST_USED_AT', true),

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
    | request. You may change the middleware below as required.
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
    | is the maximum time a cached tokenable identity may remain stale.
    |
    */

    'cache' => [
        'enabled' => env('SANCTUM_CACHE_ENABLED', false),
        'store' => env('SANCTUM_CACHE_STORE'), // Uses default store if not set
        'ttl' => (int) env('SANCTUM_CACHE_TTL', 300), // 5 minutes
        'prefix' => env('SANCTUM_CACHE_PREFIX', 'sanctum'),
        // Zero is valid, so preserve malformed values for startup validation.
        'last_used_at_update_interval' => filter_var(
            env('SANCTUM_LAST_USED_UPDATE_INTERVAL', 300),
            FILTER_VALIDATE_INT,
            FILTER_NULL_ON_FAILURE,
        ),
    ],
];
