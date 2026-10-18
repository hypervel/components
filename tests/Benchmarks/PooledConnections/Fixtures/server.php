<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

$server = new Server('127.0.0.1', 0, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
$server->set([
    'worker_num' => 1,
    'enable_coroutine' => true,
    'ssl_cert_file' => $argv[1],
    'ssl_key_file' => $argv[2],
    'log_level' => SWOOLE_LOG_WARNING,
]);
$connections = [];
$accepted = 0;
$server->on('workerStart', static function (Server $server): void {
    echo 'READY ' . $server->ports[0]->port . "\n";
});
$server->on('connect', static function (Server $server, int $connection) use (&$connections, &$accepted): void {
    $connections[$connection] = ++$accepted;
});
$server->on('close', static function (Server $server, int $connection) use (&$connections): void {
    unset($connections[$connection]);
});
$server->on('request', static function (Request $request, Response $response) use (&$connections): void {
    Coroutine::sleep(0.02);
    $response->header('X-Benchmark-Connection', (string) $connections[$request->fd]);
    if (str_contains($request->header['x-amz-target'] ?? '', 'SendMessage')) {
        $response->header('Content-Type', 'application/x-amz-json-1.0');
        $response->end('{"MessageId":"benchmark-message"}');
    } else {
        $response->header('Content-Type', 'text/plain');
        $response->end('contents');
    }
});
$server->start();
