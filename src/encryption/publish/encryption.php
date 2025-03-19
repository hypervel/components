<?php

declare(strict_types=1);

use function Hyperf\Support\env;

/*
 * This file is for backward compatibility for Hyperf framework only.
 * If you're using Hypervel, you should set keys in `config/app.php`.
 */
return [
    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],
];
