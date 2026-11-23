<?php

declare(strict_types=1);

// Connections for the transaction dispatch fixtures, merged with the framework's own connections.
return [
    'default' => 'transaction-redis',

    'connections' => [
        'transaction-redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false],
        'transaction-redis-default' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90],
        'transaction-outbox' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false, 'outbox' => true],
        'transaction-database' => ['driver' => 'database', 'connection' => 'pgsql', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false],
        'transaction-sqlite' => ['driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false],
    ],
];
