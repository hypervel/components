<?php

declare(strict_types=1);

/**
 * Override database config - tests scalar override in nested structures.
 *
 * Changes:
 * - Overrides pgsql host and adds pool config
 * - Adds sqlite connection
 * - Adds queue redis connection
 */
return [
    'connections' => [
        'pgsql' => [
            'host' => 'db.example.com',
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
            ],
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],
    'redis' => [
        'queue' => [
            'host' => 'redis-queue.example.com',
            'port' => 6379,
            'db' => 1,
        ],
    ],
];
