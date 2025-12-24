<?php

declare(strict_types=1);

/**
 * Base cache config - tests deeply nested structures with various types.
 */
return [
    'default' => 'redis',
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ],
        'swoole' => [
            'driver' => 'swoole',
            'table' => 'default',
            'memory_limit_buffer' => 0.05,
            'eviction_policy' => 'lru',
            'eviction_proportion' => 0.05,
            'eviction_interval' => 10000,
        ],
    ],
    'swoole_tables' => [
        'default' => [
            'rows' => 1024,
            'bytes' => 10240,
            'conflict_proportion' => 0.2,
        ],
    ],
    'prefix' => 'app_cache_',
];
