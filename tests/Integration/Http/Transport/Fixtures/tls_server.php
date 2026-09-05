<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require_once __DIR__ . '/../../../../../vendor/autoload.php';

$tlsDirectory = __DIR__ . '/Tls';
$server = new Server(
    '0.0.0.0',
    19532,
    SWOOLE_PROCESS,
    SWOOLE_SOCK_TCP | SWOOLE_SSL,
);

$server->set([
    'worker_num' => 1,
    'open_http2_protocol' => true,
    'ssl_cert_file' => $tlsDirectory . '/server.crt',
    'ssl_key_file' => $tlsDirectory . '/server.key',
    'ssl_verify_peer' => true,
    // Swoole 6.2 requires this setting to enforce client certificates server-side.
    'ssl_client_cert_file' => $tlsDirectory . '/ca.crt',
]);

$server->on('request', function (Request $request, Response $response): void {
    $response->header('Content-Type', 'application/json');
    $response->end(json_encode([
        'protocol' => $request->server['server_protocol'],
        'method' => $request->server['request_method'],
        'body' => $request->rawContent(),
    ], JSON_THROW_ON_ERROR));
});

$server->start();
