<?php

declare(strict_types=1);

return [
    'default' => 'array',
    'prefix' => 'package-prefix',
    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'file' => [
            'driver' => 'file',
            'path' => '/tmp/cache',
        ],
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
        ],
    ],
];
