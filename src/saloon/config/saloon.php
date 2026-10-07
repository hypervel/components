<?php

declare(strict_types=1);

use GuzzleHttp\TransportSharing;

return [
    /*
    |--------------------------------------------------------------------------
    | HTTP Connection
    |--------------------------------------------------------------------------
    |
    | Saloon sends requests through this named HTTP client connection unless
    | a connector selects another one. Its options are the defaults for
    | every request sent through it, and you may change or remove them.
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
    | A null namespace follows the path beneath the application directory,
    | so "app/Http/Integrations" becomes "App\Http\Integrations". Set the
    | namespace when the path is outside the application directory.
    |
    */

    'integrations_path' => app_path('Http/Integrations'),
    'integrations_namespace' => null,
];
