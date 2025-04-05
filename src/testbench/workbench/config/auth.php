<?php

declare(strict_types=1);

return [
    'defaults' => [
        'guard' => 'session',
        'provider' => 'users',
    ],
    'guards' => [
        'session' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'jwt' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
    ],
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => 'MockedUser',
        ],
    ],
];
