<?php

declare(strict_types=1);

return [
    'server' => [
        'enabled' => (bool) env('GRPC_SERVER_ENABLED', false),
        'name' => (string) env('GRPC_SERVER_NAME', 'grpc'),
        'host' => (string) env('GRPC_SERVER_HOST', '0.0.0.0'),
        'port' => (int) env('GRPC_SERVER_PORT', 50051),
        'routes' => base_path('routes/grpc.php'),
        'max_receive_message_size' => (int) env('GRPC_SERVER_MAX_RECEIVE_MESSAGE_SIZE', 4 * 1024 * 1024),
        'max_send_message_size' => (int) env('GRPC_SERVER_MAX_SEND_MESSAGE_SIZE', 4 * 1024 * 1024),
        'max_metadata_size' => (int) env('GRPC_SERVER_MAX_METADATA_SIZE', 8 * 1024),
        'compression' => env('GRPC_SERVER_COMPRESSION'),
        'tls' => [
            'local_cert' => env('GRPC_SERVER_TLS_CERT'),
            'local_pk' => env('GRPC_SERVER_TLS_KEY'),
            'passphrase' => env('GRPC_SERVER_TLS_PASSPHRASE'),
            'verify_peer' => (bool) env('GRPC_SERVER_TLS_VERIFY_PEER', false),
            'allow_self_signed' => (bool) env('GRPC_SERVER_TLS_ALLOW_SELF_SIGNED', false),
            'cafile' => env('GRPC_SERVER_TLS_CLIENT_CA'),
            'ciphers' => env('GRPC_SERVER_TLS_CIPHERS'),
            'crypto_method' => null,
        ],
        'settings' => [],
    ],
];
