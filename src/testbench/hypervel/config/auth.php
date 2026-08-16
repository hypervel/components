<?php

declare(strict_types=1);

return [
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => Hypervel\Foundation\Auth\User::class,
            'cache' => [
                'enabled' => (bool) env('AUTH_USER_CACHE_ENABLED', false),
                'store' => env('AUTH_USER_CACHE_STORE'),
                'ttl' => (int) env('AUTH_USER_CACHE_TTL', 300),
                'prefix' => env('AUTH_USER_CACHE_PREFIX', 'auth_user'),
                'tags' => null,
            ],
        ],
    ],
];
