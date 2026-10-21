<?php

declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require_once __DIR__ . '/../../../../vendor/autoload.php';

$mode = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$tlsDirectory = __DIR__ . '/Tls';
$server = new Server('127.0.0.1', $port, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$settings = [
    'log_file' => '/dev/null',
    'worker_num' => 1,
];

if ($mode === 'mtls') {
    $settings += [
        'ssl_cert_file' => $tlsDirectory . '/server.crt',
        'ssl_key_file' => $tlsDirectory . '/server.key',
        'ssl_verify_peer' => true,
        'ssl_cafile' => $tlsDirectory . '/ca.crt',
    ];
} elseif ($mode === 'sni') {
    $settings['ssl_sni_certs'] = [
        'localhost' => [
            'ssl_cert_file' => $tlsDirectory . '/server.crt',
            'ssl_key_file' => $tlsDirectory . '/server.key',
        ],
    ];
} else {
    fwrite(STDERR, "Unknown TLS test mode.\n");
    exit(1);
}

if (! $server->set($settings)) {
    exit(1);
}

$server->on('request', static function (Request $request, Response $response): void {
    $response->end('secure');
});
$server->start();
