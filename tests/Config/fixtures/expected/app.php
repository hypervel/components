<?php

declare(strict_types=1);

/**
 * Expected merged app config.
 *
 * Verifies:
 * - Strings are overridden correctly (name, env, key)
 * - Booleans are overridden correctly (debug: false -> true)
 * - Null is overridden with value (key: null -> 'base64:...')
 * - New keys are added (new_setting)
 * - Providers list is combined (not replaced!)
 */
return [
    'name' => 'OverrideApp',          // Overridden
    'env' => 'local',                  // Overridden
    'debug' => true,                   // Overridden from false
    'url' => 'http://localhost',       // Preserved
    'timezone' => 'UTC',               // Preserved
    'locale' => 'en',                  // Preserved
    'fallback_locale' => 'en',         // Preserved
    'cipher' => 'AES-256-CBC',         // Preserved
    'key' => 'base64:testkey123',      // Overridden from null
    'maintenance' => [
        'driver' => 'file',
        'store' => 'redis',
    ],
    'providers' => [
        // From base
        'App\Providers\AppServiceProvider',
        'App\Providers\EventServiceProvider',
        // From override (appended)
        'App\Providers\RouteServiceProvider',
    ],
    'new_setting' => 'new_value',      // Added from override
];
