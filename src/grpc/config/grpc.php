<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | gRPC Server
    |--------------------------------------------------------------------------
    |
    | These options configure the gRPC server used to accept incoming calls.
    | The server will only start when the enabled option is true. When it is
    | disabled, the package may still make calls to other gRPC servers.
    |
    */

    'server' => [
        'enabled' => (bool) env('GRPC_SERVER_ENABLED', false),

        /*
        |--------------------------------------------------------------------------
        | Server Name
        |--------------------------------------------------------------------------
        |
        | This name identifies the gRPC server within your Hypervel
        | application. It must be different from every other configured
        | server name.
        |
        */

        'name' => (string) env('GRPC_SERVER_NAME', 'grpc'),

        /*
        |--------------------------------------------------------------------------
        | Server Address
        |--------------------------------------------------------------------------
        |
        | These options control the network address where the gRPC server
        | will listen for incoming calls.
        |
        */

        'host' => (string) env('GRPC_SERVER_HOST', '0.0.0.0'),
        'port' => (int) env('GRPC_SERVER_PORT', 50051),

        /*
        |--------------------------------------------------------------------------
        | gRPC Routes
        |--------------------------------------------------------------------------
        |
        | This file contains the gRPC services and methods that are available
        | to clients. It will be loaded when the gRPC server starts.
        |
        */

        'routes' => base_path('routes/grpc.php'),

        /*
        |--------------------------------------------------------------------------
        | Message Size Limits
        |--------------------------------------------------------------------------
        |
        | These values determine the maximum size, in bytes, of each message
        | the server may receive or send. Calls that exceed either limit will
        | fail with a ResourceExhausted status.
        |
        */

        'max_receive_message_size' => (int) env('GRPC_SERVER_MAX_RECEIVE_MESSAGE_SIZE', 4 * 1024 * 1024),
        'max_send_message_size' => (int) env('GRPC_SERVER_MAX_SEND_MESSAGE_SIZE', 4 * 1024 * 1024),

        /*
        |--------------------------------------------------------------------------
        | Metadata Size Limit
        |--------------------------------------------------------------------------
        |
        | This value determines the maximum size, in bytes, of each complete
        | metadata block, including the fields required by the gRPC protocol.
        |
        */

        'max_metadata_size' => (int) env('GRPC_SERVER_MAX_METADATA_SIZE', 8 * 1024),

        /*
        |--------------------------------------------------------------------------
        | Response Compression
        |--------------------------------------------------------------------------
        |
        | Set this option to "gzip" to compress response messages. Leave it
        | null, or use "identity", to disable compression. Compression is
        | only used when supported by the client.
        |
        */

        'compression' => env('GRPC_SERVER_COMPRESSION'),

        /*
        |--------------------------------------------------------------------------
        | TLS Options
        |--------------------------------------------------------------------------
        |
        | To accept secure connections directly, provide both a certificate
        | and its private key. To require clients to present a certificate,
        | enable peer verification and provide the certificate authority file
        | used to verify them.
        |
        */

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

        /*
        |--------------------------------------------------------------------------
        | Swoole Server Settings
        |--------------------------------------------------------------------------
        |
        | Here you may provide additional settings for the Swoole server.
        | Settings managed by the gRPC package, including HTTP/2, message
        | limits, response compression, and TLS, may not be changed here.
        |
        */

        'settings' => [],
    ],
];
