<?php

declare(strict_types=1);

use Hypervel\Support\Str;

use function Hyperf\Support\env;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    */

    'default' => env('CACHE_DRIVER', 'array'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    */

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION', env('DB_CONNECTION', 'sqlite')),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE', 'cache_locks'),
            'lock_lottery' => [2, 100],
            'lock_timeout' => 86400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    */

    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'hypervel'), '_') . '_cache'),
];
