<?php

declare(strict_types=1);

return [
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL', Hypervel\Foundation\Auth\User::class),
        ],
    ],
];
