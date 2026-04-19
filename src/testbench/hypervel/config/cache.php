<?php

declare(strict_types=1);

return [
    'default' => env('CACHE_STORE', 'array'),

    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'lock_connection' => 'default',
            'tag_mode' => 'all',
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'cache_prefix:'),
];
