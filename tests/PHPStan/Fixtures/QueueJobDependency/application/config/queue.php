<?php

declare(strict_types=1);

return [
    'default' => 'before-commit',

    'connections' => [
        'before-commit' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false],
        'database-mysql' => ['driver' => 'database', 'connection' => 'mysql', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false],
    ],
];
