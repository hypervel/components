<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

$server = new Server('127.0.0.1', 0, SWOOLE_BASE);
$server->set([
    'worker_num' => 1,
    'enable_coroutine' => true,
    'log_level' => SWOOLE_LOG_WARNING,
]);
$active = 0;
$server->on('workerStart', static function (Server $server): void {
    echo 'READY ' . $server->ports[0]->port . "\n";
});
$server->on('request', static function (Request $request, Response $response) use (&$active): void {
    $response->header('Content-Type', 'application/json');

    if ($request->server['request_uri'] === '/active') {
        $response->end(json_encode(['active' => $active], JSON_THROW_ON_ERROR));

        return;
    }

    $message = json_decode($request->rawContent(), true, flags: JSON_THROW_ON_ERROR);

    if ($message['method'] === 'server/discover') {
        $response->end(json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => [
            'resultType' => 'complete',
            'supportedVersions' => [$message['params']['_meta']['io.modelcontextprotocol/protocolVersion']],
            'capabilities' => ['tools' => new stdClass],
            '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'Local benchmark', 'version' => '1.0']],
        ]], JSON_THROW_ON_ERROR));

        return;
    }

    ++$active;
    $overlap = $active;

    try {
        Coroutine::sleep($message['params']['arguments']['delay_ms'] / 1000);
        $response->end(json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => $message['params']['name']]],
            '_meta' => ['active' => $overlap],
        ]], JSON_THROW_ON_ERROR));
    } finally {
        --$active;
    }
});
$server->start();
