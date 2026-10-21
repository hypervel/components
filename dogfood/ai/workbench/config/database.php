<?php

declare(strict_types=1);

use function Hypervel\Testbench\workbench_path;

return [
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            // Keep demo conversations outside Testbench's disposable skeleton.
            'database' => env('DB_DATABASE', workbench_path('database/database.sqlite')),
            'foreign_key_constraints' => true,
        ],
    ],
];
