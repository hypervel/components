<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

$server = new Server('127.0.0.1', 0, SWOOLE_BASE);
$server->set(['worker_num' => 1, 'enable_coroutine' => true, 'log_level' => SWOOLE_LOG_WARNING]);
$server->on('workerStart', static function (Server $server): void {
    echo 'READY ' . $server->ports[0]->port . "\n";
});
$server->on('request', static function (Request $request, Response $response): void {
    $delay = (int) ($request->get['delay_us'] ?? 0);
    if ($delay > 0) {
        Coroutine::sleep($delay / 1e6);
    }

    $response->header('Content-Type', 'application/json');
    $response->end('{"result":"accepted"}');
});
$server->start();
