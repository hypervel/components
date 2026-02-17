<?php

declare(strict_types=1);

/**
 * HTTP test server for engine integration tests.
 *
 * Listens on port 19501 and handles various test endpoints.
 */

use Hypervel\Engine\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

use function Swoole\Coroutine\run;

require_once __DIR__ . '/../../../vendor/autoload.php';

Coroutine::set([
    'hook_flags' => SWOOLE_HOOK_ALL,
]);

$callback = function () {
    $server = new Server('0.0.0.0', 19501);
    $server->handle('/', function (Request $request, Response $response) {
        $response->setHeader('Server', 'Hyperf');
        switch ($request->server['request_uri']) {
            case '/':
                $response->end('Hello World.');
                break;
            case '/header':
                $response->header('X-ID', [uniqid(), $id = uniqid()]);
                $response->end($id);
                break;
            case '/cookies':
                $response->setCookie('X-Server-Id', $id = uniqid());
                $response->setCookie('X-Server-Name', 'Hyperf');
                $response->end($id);
                break;
            case '/timeout':
                $time = $request->get['time'] ?? 1;
                sleep((int) $time);
                $response->end();
                break;
            default:
                $response->setStatusCode(404);
                $response->end();
                break;
        }
    });
    $server->start();
};

run($callback);
