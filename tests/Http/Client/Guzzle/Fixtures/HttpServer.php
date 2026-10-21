<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Client\Guzzle\Fixtures;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

class HttpServer
{
    protected Server $server;

    protected int $coroutine;

    /**
     * Start a loopback server on an automatically assigned port.
     */
    public function __construct(?callable $callback = null)
    {
        $this->server = new Server('127.0.0.1', 0, false, false);
        $this->server->handle('/', $callback ?? static function (Request $request, Response $response): void {
            $response->end('ok');
        });
        $this->coroutine = Coroutine::create(fn (): bool => $this->server->start());
    }

    /**
     * Get the server's base URL.
     */
    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->server->port . '/';
    }

    /**
     * Close the listener and wait for its coroutine to finish.
     */
    public function close(): void
    {
        $this->server->shutdown();
        Coroutine::join([$this->coroutine], 1);
    }
}
