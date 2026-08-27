<?php

declare(strict_types=1);

use GuzzleHttp\TransportSharing;

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP Connection
    |--------------------------------------------------------------------------
    |
    | Saloon sends requests through this named, worker-lifetime HTTP
    | connection. Its options are an open transport preset and may be
    | adjusted or removed independently.
    |
    */

    'connection' => [
        'name' => 'saloon',
        'options' => [
            'connect_timeout' => 10,
            'timeout' => 30,
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Stores
    |--------------------------------------------------------------------------
    |
    | Set either store to null to use the corresponding framework default.
    | Individual connectors and requests may select another configured store.
    |
    */

    'cache' => [
        'store' => null,
    ],

    'rate_limiter' => [
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fixtures
    |--------------------------------------------------------------------------
    |
    | Missing fixture settings use the values shown below. When
    | "throw_on_missing" is false, Saloon records a real response for a
    | missing fixture. Enable it for replay-only test runs such as CI.
    |
    */

    'fixtures' => [
        'path' => base_path('tests/Fixtures/Saloon'),
        'throw_on_missing' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generated Integrations
    |--------------------------------------------------------------------------
    |
    | The path and namespace are independent. A null namespace derives
    | "Http\\Integrations" beneath the application's root namespace.
    |
    */

    'integrations_path' => app_path('Http/Integrations'),
    'integrations_namespace' => null,
];
