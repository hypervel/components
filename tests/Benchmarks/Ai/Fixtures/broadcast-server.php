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
$records = [];
$active = 0;
$peak = 0;
$delay = 0;
$connections = [];
$accepted = 0;
$acceptedAtReset = 0;
$server->on('workerStart', static function (Server $server): void {
    echo 'READY ' . $server->ports[0]->port . "\n";
});
$server->on('connect', static function (Server $server, int $connection) use (&$connections, &$accepted): void {
    $connections[$connection] = ++$accepted;
});
$server->on('close', static function (Server $server, int $connection) use (&$connections): void {
    unset($connections[$connection]);
});
$server->on('request', static function (Request $request, Response $response) use (&$records, &$active, &$peak, &$delay, &$connections, &$accepted, &$acceptedAtReset): void {
    $response->header('Content-Type', 'application/json');

    if ($request->server['request_uri'] === '/reset') {
        if ($active !== 0) {
            $response->status(409);
            $response->end('{"error":"Transfers remain active."}');
            return;
        }

        $records = [];
        $peak = 0;
        $acceptedAtReset = $accepted;
        $delay = (int) ($request->get['delay_us'] ?? 0);
        $response->end('{}');
        return;
    }

    if ($request->server['request_uri'] === '/stats') {
        $response->end(json_encode(['records' => $records, 'active' => $active, 'peak' => $peak, 'accepted_at_reset' => $acceptedAtReset], JSON_THROW_ON_ERROR));
        return;
    }

    if ($request->server['request_uri'] !== '/apps/benchmark/events') {
        $response->status(404);
        $response->end('{}');
        return;
    }

    ++$active;
    $peak = max($peak, $active);

    try {
        $received = hrtime(true);
        $body = $request->rawContent();
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $event = json_decode($payload['data'], true, flags: JSON_THROW_ON_ERROR);

        if ($delay > 0) {
            Coroutine::sleep($delay / 1e6);
        }

        $records[] = ['id' => $event['id'], 'type' => $payload['name'], 'channel' => $payload['channel'], 'connection' => $connections[$request->fd],
            'received_ns' => $received, 'accepted_ns' => hrtime(true), 'body_bytes' => strlen($body),
            'delta' => $event['delta'] ?? null];
        $response->end('{}');
    } finally {
        --$active;
    }
});
$server->start();
