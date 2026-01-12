<?php

declare(strict_types=1);

/**
 * Expected merged database config.
 *
 * Verifies:
 * - pgsql.host overridden from 'localhost' to 'db.example.com'
 * - pgsql.driver remains 'pgsql' (NOT an array - this was the original bug)
 * - pgsql.pool added from override
 * - mysql preserved from base
 * - sqlite added from override
 * - redis.default preserved, redis.queue added
 */
return [
    'default' => 'pgsql',
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => 'db.example.com',  // Overridden
            'port' => 5432,
            'database' => 'app',
            'username' => 'postgres',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'schema' => 'public',
            'sslmode' => 'prefer',
            'pool' => [  // Added from override
                'min_connections' => 1,
                'max_connections' => 10,
                'connect_timeout' => 10.0,
                'wait_timeout' => 3.0,
            ],
        ],
        'mysql' => [
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => 'app',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
        ],
        'sqlite' => [  // Added from override
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ],
    'migrations' => 'migrations',
    'redis' => [
        'default' => [
            'host' => 'localhost',
            'port' => 6379,
            'db' => 0,
        ],
        'queue' => [  // Added from override
            'host' => 'redis-queue.example.com',
            'port' => 6379,
            'db' => 1,
        ],
    ],
];
