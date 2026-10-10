<?php

declare(strict_types=1);

use Hypervel\Container\Container;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\HttpServer\ResponseBridge;
use Hypervel\Server\Event;
use Hypervel\Server\Server;
use Hypervel\Server\ServerConfig;
use Psr\Log\NullLogger;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Server as NativeServer;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (posix_setsid() === -1) {
    throw new RuntimeException('Unable to isolate the fixture process group.');
}

$server = new class(new Container, new NullLogger, new Dispatcher) extends Server {
    protected function defaultCallbacks(): array
    {
        return [];
    }
};
$provider = $argv[1];
$proxyAuthorizations = [];

$server->init(new ServerConfig([
    'mode' => $argv[2] === 'base' ? SWOOLE_BASE : SWOOLE_PROCESS,
    'settings' => [
        'worker_num' => 1,
        'reactor_num' => 1,
        'enable_coroutine' => true,
        'open_http2_protocol' => true,
        'hook_flags' => SWOOLE_HOOK_ALL,
        'log_level' => SWOOLE_LOG_WARNING,
    ],
    'servers' => [
        [
            'name' => 'http',
            'host' => '127.0.0.1',
            'port' => 0,
            'callbacks' => [
                Event::ON_WORKER_START => static function (NativeServer $server): void {
                    fwrite(STDOUT, 'READY ' . $server->ports[0]->port . "\n");
                },
                Event::ON_REQUEST => static function (Request $request, Response $native) use ($provider, &$proxyAuthorizations): void {
                    if ($request->server['request_method'] === 'CONNECT') {
                        $proxyAuthorizations[$request->fd] = $request->header['proxy-authorization'] ?? null;
                        $native->end();

                        return;
                    }

                    if ($request->server['request_uri'] === '/health') {
                        $native->end('healthy');

                        return;
                    }

                    if ($request->server['request_uri'] === '/identity') {
                        $native->cookie('session', 'previous-request');
                        $native->end(json_encode([
                            'connection' => $request->fd,
                            'authorization' => $request->header['authorization'] ?? null,
                            'proxy_authorization' => $proxyAuthorizations[$request->fd] ?? null,
                            'cookie' => $request->header['cookie'] ?? null,
                        ], JSON_THROW_ON_ERROR));

                        return;
                    }

                    $response = (new IterableStreamedResponse((static function () use ($provider): iterable {
                        $upstream = null;

                        try {
                            $upstream = (new Factory)->withOptions(['stream' => true])->get('http://' . $provider);
                            fwrite(STDOUT, "UPSTREAM_HEADERS\n");

                            yield from $upstream->lines();
                        } finally {
                            $upstream?->close();
                            fwrite(STDOUT, "STOPPED\n");
                        }
                    })()))->cancelOnDisconnect();

                    ResponseBridge::send($response, $native);
                },
                Event::ON_CLOSE => static function (NativeServer $server, int $connection) use (&$proxyAuthorizations): void {
                    unset($proxyAuthorizations[$connection]);
                    fwrite(STDOUT, "APPLICATION_CLOSE\n");
                },
            ],
        ],
    ],
]));
$server->start();
