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
$active = 0;
$server->on('workerStart', static function (Server $server): void {
    echo 'READY ' . $server->ports[0]->port . "\n";
});
$server->on('connect', static function (Server $server, int $connection) use (&$connections, &$accepted): void {
    $connections[$connection] = ++$accepted;
});
$server->on('close', static function (Server $server, int $connection) use (&$connections): void {
    unset($connections[$connection]);
});
$server->on('request', static function (Request $request, Response $response) use (&$connections, &$active): void {
    ++$active;

    try {
        $chunks = (int) $request->get['chunks'];
        $bytes = (int) $request->get['bytes'];
        $pace = (int) $request->get['pace_us'];
        $delay = (int) ($request->get['delay_us'] ?? 0);

        if ($delay > 0) {
            Coroutine::sleep($delay / 1e6);
        }

        $response->header('Content-Type', 'text/event-stream');
        $response->header('X-Benchmark-Connection', (string) $connections[$request->fd]);
        $response->header('X-Benchmark-Active', (string) $active);
        $chunk = 'data: ' . str_repeat('x', $bytes - 8) . "\n\n";

        for ($index = 0; $index < $chunks; ++$index) {
            if (! $response->write($chunk)) {
                return;
            }

            if ($pace > 0 && $index + 1 < $chunks) {
                Coroutine::sleep($pace / 1e6);
            }
        }

        $response->end();
    } finally {
        --$active;
    }
});
$server->start();
