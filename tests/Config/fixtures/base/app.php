<?php

declare(strict_types=1);

/**
 * Base app config - tests various scalar types.
 */
return [
    'name' => 'BaseApp',
    'env' => 'production',
    'debug' => false,
    'url' => 'http://localhost',
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'cipher' => 'AES-256-CBC',
    'key' => null,
    'maintenance' => [
        'driver' => 'file',
        'store' => 'redis',
    ],
    'providers' => [
        'App\Providers\AppServiceProvider',
        'App\Providers\EventServiceProvider',
    ],
];
