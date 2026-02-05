<?php

declare(strict_types=1);

/**
 * WebSocket test server for engine integration tests.
 *
 * Listens on port 9503 and handles WebSocket connections.
 */

use Hypervel\Engine\Coroutine;
use Hypervel\Engine\WebSocket\WebSocket;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;

use function Swoole\Coroutine\run;

require_once __DIR__ . '/../../../vendor/autoload.php';

Coroutine::set([
    'hook_flags' => SWOOLE_HOOK_ALL,
]);

run(function () {
    $server = new Server('0.0.0.0', 9503, false);
    $server->set(['open_websocket_ping_frame' => true]);

    $server->handle('/', function (Request $request, Response $connection) {
        $socket = new WebSocket($connection, $request);
        $socket->on(WebSocket::ON_CLOSE, static function (Response $connection, int $fd) {
            var_dump('closed: ' . $fd);
            $connection->close();
        });
        $socket->on(WebSocket::ON_MESSAGE, static function (Response $connection, Frame $frame) {
            $connection->push('received: ' . $frame->data);
        });
        $socket->start();
    });
    $server->start();
});
