<?php

declare(strict_types=1);

/**
 * Override cache config - tests deep merge with type preservation.
 *
 * Changes:
 * - Overrides swoole eviction_interval
 * - Adds database store
 * - Modifies swoole_tables rows
 */
return [
    'stores' => [
        'swoole' => [
            'eviction_interval' => 5000,  // Override int
        ],
        'database' => [
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
            'rows' => 2048,  // Override int
        ],
    ],
];
