<?php

declare(strict_types=1);

use Hypervel\Cache\SwooleStore;
use Hypervel\Support\Str;

use function Hyperf\Support\env;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache connection that gets used while
    | using this caching library. This connection is used when another is
    | not explicitly specified when executing a given caching function.
    |
    */

    'default' => env('CACHE_DRIVER', 'array'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "file", "redis", "swoole", "stack", "null"
    |
    */

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver' => 'file',
            'path' => BASE_PATH . '/storage/cache/data',
            'lock_path' => BASE_PATH . '/storage/cache/data',
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'tag_mode' => env('REDIS_CACHE_TAG_MODE', 'all'), // Redis 8.0+ and PhpRedis 6.3.0+ required for 'any'
            'lock_connection' => 'default',
        ],

        'swoole' => [
            'driver' => 'swoole',
            'table' => 'default',
            'memory_limit_buffer' => 0.05,
            'eviction_policy' => SwooleStore::EVICTION_POLICY_LRU,
            'eviction_proportion' => 0.05,
            'eviction_interval' => 10000, // milliseconds
        ],

        'stack' => [
            'driver' => 'stack',
            'stores' => [
                'swoole' => [
                    'ttl' => 3, // seconds
                ],
                'redis',
            ],
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION', env('DB_CONNECTION', 'default')),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE', 'cache_locks'),
            'lock_lottery' => [2, 100],
            'lock_timeout' => 86400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Swoole Tables Configuration
    |--------------------------------------------------------------------------
    */

    'swoole_tables' => [
        'default' => [
            'rows' => 1024,
            'bytes' => 10240,
            'conflict_proportion' => 0.2,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the database or Redis cache stores, there might be other
    | applications using the same cache. For that reason, you may prefix
    | every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'hypervel')) . '-cache-'),
];
