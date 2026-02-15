<?php

declare(strict_types=1);

namespace Hypervel\Engine\Http;

use Hypervel\HttpMessage\Server\Request;
use Hypervel\Contracts\Engine\Http\ServerInterface;
use Hypervel\Engine\Coroutine;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\Http\Server as HttpServer;
use Throwable;

class Server implements ServerInterface
{
    public string $host;

    public int $port;

    /**
     * @var callable
     */
    protected $handler;

    protected HttpServer $server;

    /**
     * Create a new server instance.
     */
    public function __construct(protected LoggerInterface $logger)
    {
    }

    /**
     * Bind the server to a host and port.
     */
    public function bind(string $name, int $port = 0): static
    {
        $this->host = $name;
        $this->port = $port;

        $this->server = new HttpServer($name, $port, reuse_port: true);
        return $this;
    }

    /**
     * Set the request handler.
     */
    public function handle(callable $callable): static
    {
        $this->handler = $callable;
        return $this;
    }

    /**
     * Start the server.
     */
    public function start(): void
    {
        $this->server->handle('/', function ($request, $response) {
            Coroutine::create(function () use ($request, $response) {
                try {
                    $handler = $this->handler;

                    $handler(Request::loadFromSwooleRequest($request), $response);
                } catch (Throwable $exception) {
                    $this->logger->critical((string) $exception);
                }
            });
        });

        $this->server->start();
    }

    /**
     * Close the server.
     */
    public function close(): bool
    {
        $this->server->shutdown();

        return true;
    }
}
