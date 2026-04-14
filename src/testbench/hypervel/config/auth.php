<?php

declare(strict_types=1);

return [
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => Hypervel\Foundation\Auth\User::class,
        ],
    ],
];
