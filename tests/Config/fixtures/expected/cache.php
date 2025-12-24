<?php

declare(strict_types=1);

/**
 * Expected merged cache config.
 *
 * Verifies:
 * - Floats remain floats (memory_limit_buffer = 0.05)
 * - Integers remain integers (eviction_interval = 5000)
 * - Booleans remain booleans (serialize = false)
 * - Deeply nested values merge correctly
 * - New stores are added
 * - Existing values are overridden
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
            'memory_limit_buffer' => 0.05,  // Float preserved
            'eviction_policy' => 'lru',
            'eviction_proportion' => 0.05,  // Float preserved
            'eviction_interval' => 5000,    // Overridden from 10000
        ],
        'database' => [  // Added from override
            'driver' => 'database',
            'connection' => 'pgsql',
            'table' => 'cache',
            'lock_connection' => 'pgsql',
            'lock_table' => 'cache_locks',
            'lock_lottery' => [2, 100],
            'lock_timeout' => 86400,
        ],
    ],
    'swoole_tables' => [
        'default' => [
            'rows' => 2048,                 // Overridden from 1024
            'bytes' => 10240,
            'conflict_proportion' => 0.2,   // Float preserved
        ],
    ],
    'prefix' => 'app_cache_',
];
