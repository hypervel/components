<?php

declare(strict_types=1);

/**
 * Override app config - tests scalar type preservation.
 *
 * Changes:
 * - Override strings, bools
 * - Add new key
 * - Append to providers list
 */
return [
    'name' => 'OverrideApp',
    'env' => 'local',
    'debug' => true,
    'key' => 'base64:testkey123',
    'new_setting' => 'new_value',
    'providers' => [
        'App\Providers\RouteServiceProvider',
    ],
];
