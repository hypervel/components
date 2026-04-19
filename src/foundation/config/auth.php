<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported by default: "session". Install hypervel/sanctum to use
    | the "sanctum" guard, and hypervel/jwt to use the "jwt" guard
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
        'jwt' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', App\Models\User::class), // @phpstan-ignore class.notFound

            /*
            |------------------------------------------------------------------
            | User Lookup Cache (opt-in, Eloquent provider only)
            |------------------------------------------------------------------
            |
            | Caches retrieveById() lookups across requests. Disabled by
            | default. Credential and token lookups are never cached
            | (security).
            |
            | Supported stores: 'redis', 'database', 'file', 'swoole', 'stack'.
            | Any other driver ('array', 'null', 'session', 'failover') is
            | rejected.
            |
            | Cross-node behaviour:
            |   - 'redis' / 'database': fully shared — invalidation is global.
            |   - 'file' / 'swoole': node-local, no cross-node invalidation
            |     (single-instance deployments only).
            |   - 'stack' with a node-local upper tier (e.g. [swoole, redis]):
            |     eventually consistent — the shared lower tier clears
            |     globally, but each node's L1 serves its stale entry until
            |     the L1 TTL expires. This is the microcaching trade-off.
            |
            | High-scale: the recommended topology is a 'stack' cache with
            | 'swoole' as L1 (3–5s) and 'redis' as L2 — the microcaching
            | pattern eliminates the majority of Redis round-trips for
            | authed requests at high concurrency. See the auth caching
            | documentation for the full explanation.
            |
            | Caveat: only the outer store is validated. A stack with an
            | unsupported inner tier (e.g. [array, redis]) won't be caught.
            |
            */
            'cache' => [
                'enabled' => env('AUTH_USERS_CACHE_ENABLED', false),
                'store' => env('AUTH_USERS_CACHE_STORE'),
                'ttl' => env('AUTH_USERS_CACHE_TTL', 300),
                'prefix' => env('AUTH_USERS_CACHE_PREFIX', 'auth_users'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Hypervel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
