<?php

declare(strict_types=1);

use GuzzleHttp\TransportSharing;

return [
    'connection' => [
        'name' => 'saloon',
        'options' => [
            'connect_timeout' => 10,
            'timeout' => 30,
            'transport_sharing' => TransportSharing::HANDLER_PREFER,
        ],
    ],

    'cache' => [
        'store' => null,
    ],

    'rate_limiter' => [
        'store' => null,
    ],

    'fixtures' => [
        'path' => base_path('tests/Fixtures/Saloon'),
        'throw_on_missing' => false,
    ],

    'integrations_path' => app_path('Http/Integrations'),
    'integrations_namespace' => null,
];
