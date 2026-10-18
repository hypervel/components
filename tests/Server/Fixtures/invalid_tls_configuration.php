<?php

declare(strict_types=1);

use Swoole\Http\Server;

require_once __DIR__ . '/../../../vendor/autoload.php';

$tlsDirectory = __DIR__ . '/../../Integration/HttpServer/Fixtures/Tls';
$server = new Server('127.0.0.1', 0, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);

@$server->set([
    'log_file' => '/dev/null',
    'ssl_cert_file' => $tlsDirectory . '/invalid.crt',
    'ssl_key_file' => $tlsDirectory . '/server.key',
]);

fwrite(STDERR, "Malformed TLS certificate was accepted.\n");
