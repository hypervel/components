<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" for your
    | application. You may change this value as required, but it's a
    | perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
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
    | Guards that send password reset links declare their broker with
    | the "passwords" key, referencing an entry in the passwords array.
    | Sanctum guards declare the session guards they trust for first-party
    | SPA requests with the "session_guards" key; set it to an empty array
    | for bearer-token-only APIs. Guards may also override the password
    | confirmation window with a "password_timeout" key.
    |
    | Supported by default: "session". Install hypervel/sanctum to use
    | the "sanctum" guard, and hypervel/jwt to use the "jwt" guard
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
            'passwords' => 'users',
        ],
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
            'session_guards' => ['web'],
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
            | Supported stores: 'redis', 'database', 'file', 'storage',
            | 'swoole', and stacks containing only supported stores. Array,
            | worker-array, null, session, and failover stores are rejected.
            | Stack layers are validated recursively.
            |
            | Cross-node behavior:
            |   - 'redis' / 'database': fully shared — invalidation is global.
            |   - 'storage': shared only when its configured disk is shared.
            |   - 'file' / 'swoole' used as the only store: node-local, with no
            |     cross-node invalidation (single-instance deployments only).
            |   - 'stack' with a node-local upper tier (e.g. [swoole, redis]):
            |     eventually consistent — the shared lower tier clears
            |     globally, but each node's L1 serves its stale entry until
            |     the L1 TTL expires. This is the microcaching trade-off.
            |
            | A short-lived node-local L1 over a shared L2 can reduce shared
            | cache traffic, with bounded L1 staleness as the trade-off. Cache
            | configuration is read during process startup and must not change
            | while a worker is serving requests.
            |
            | Cache tags (optional):
            |   Set 'tags' to an array of tag names (e.g. ['auth_users'])
            |   to add those cache tags to every cached user. This is useful
            |   for bulk cache invalidation using Cache::store(...)->tags([...])->flush().
            |   Requires a store with any-mode tag support (e.g. a Redis
            |   store with tag_mode=any, or a stack over any-mode taggable layers).
            |
            */
            'cache' => [
                'enabled' => env('AUTH_USERS_CACHE_ENABLED', false),
                'store' => env('AUTH_USERS_CACHE_STORE'),
                'ttl' => (int) env('AUTH_USERS_CACHE_TTL', 300),
                'prefix' => env('AUTH_USERS_CACHE_PREFIX', 'auth_users'),
                'tags' => null,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Verification
    |--------------------------------------------------------------------------
    |
    | This value defines how many minutes an email verification link remains
    | valid before the user must request another verification email.
    |
    */

    'verification' => [
        'expire' => (int) env('AUTH_VERIFICATION_EXPIRE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Hypervel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    | Guards reference these brokers via their "passwords" key.
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
    | Individual guards may override this with a "password_timeout" key in
    | their guard configuration.
    |
    */

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),

    /*
    |--------------------------------------------------------------------------
    | Authentication Timebox Duration
    |--------------------------------------------------------------------------
    |
    | This value defines how many microseconds timeboxed authentication
    | operations should take before returning their result.
    |
    */

    'timebox_duration' => (int) env('AUTH_TIMEBOX_DURATION', 200000),
];
