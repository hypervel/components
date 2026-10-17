<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Passport Guard
    |--------------------------------------------------------------------------
    |
    | Here you may specify which authentication guard Passport will use when
    | authenticating users. This value should correspond with one of your
    | guards that is already present in your "auth" configuration file.
    | Set this to null to use the current request's default auth guard.
    |
    */

    'guard' => 'web',

    /*
    |--------------------------------------------------------------------------
    | OAuth Routes
    |--------------------------------------------------------------------------
    |
    | Here you may change the path prefix for Passport's routes and specify
    | additional middleware to apply to every OAuth endpoint. Generated
    | authorization and device URLs will use the configured prefix.
    |
    */

    'path' => 'oauth',

    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Pending Authorizations
    |--------------------------------------------------------------------------
    |
    | Each browser session may hold several authorization requests while users
    | log in or review permissions. Requests expire after the given number
    | of seconds. The oldest request is removed when the limit is reached.
    |
    */

    'authorization' => [
        'lifetime' => 600,
        'max_pending' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption Keys
    |--------------------------------------------------------------------------
    |
    | Passport signs access tokens with an RSA key pair. The "files" store
    | reads local files or the values below. The "database" store keeps
    | encrypted private keys in the database and supports key rotation.
    |
    */

    'key_store' => env('PASSPORT_KEY_STORE', 'files'),

    'private_key' => env('PASSPORT_PRIVATE_KEY'),

    'public_key' => env('PASSPORT_PUBLIC_KEY'),

    'verification_keys' => [],

    /*
    |--------------------------------------------------------------------------
    | Signing Key Rotation
    |--------------------------------------------------------------------------
    |
    | Database keys are published before they begin signing. The activation
    | delay must exceed the refresh interval so workers load a new public
    | key before tokens use it. Both values are expressed in seconds.
    |
    | With rotation enabled, Passport schedules a daily check for keys that
    | have reached the configured age in days. Your application's scheduler
    | must be running. File keys are managed outside this schedule.
    |
    */

    'key_activation_delay' => (int) env('PASSPORT_KEY_ACTIVATION_DELAY', 86400),

    'key_refresh_interval' => (int) env('PASSPORT_KEY_REFRESH_INTERVAL', 60),

    'key_rotation' => [
        'enabled' => (bool) env('PASSPORT_KEY_ROTATION', true),
        'days' => (int) env('PASSPORT_KEY_ROTATION_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server Settings
    |--------------------------------------------------------------------------
    |
    | An issuer adds an "iss" claim to access tokens; null leaves it out.
    | The server cache size limits how many resolved settings sets each
    | worker retains, including their parsed keys and OAuth servers.
    |
    */

    'issuer' => env('PASSPORT_ISSUER'),

    'server_cache_size' => (int) env('PASSPORT_SERVER_CACHE_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Passport Database Connection
    |--------------------------------------------------------------------------
    |
    | By default, Passport's models will utilize your application's default
    | database connection. If you wish to use a different connection you
    | may specify the configured name of the database connection here.
    |
    */

    'connection' => env('PASSPORT_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Token and Client Cache
    |--------------------------------------------------------------------------
    |
    | Caching avoids database lookups when checking access tokens and clients.
    | Use a store shared by every process that changes clients or revokes
    | tokens. Entries expire after the configured number of seconds.
    | A null store uses your application's default cache store.
    |
    */

    'cache' => [
        'enabled' => (bool) env('PASSPORT_CACHE_ENABLED', false),
        'store' => env('PASSPORT_CACHE_STORE'),
        'ttl' => (int) env('PASSPORT_CACHE_TTL', 300),
        'prefix' => env('PASSPORT_CACHE_PREFIX', 'passport'),
    ],
];
