<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Fixtures;

use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Coroutine\WaitGroup;

class KeepAliveHttpServer
{
    /**
     * The connections the server accepted.
     */
    private int $accepted = 0;

    /**
     * Whether the server is stopping.
     */
    private bool $stopped = false;

    /**
     * The open client connections, by object id.
     *
     * @var array<int, Socket>
     */
    private array $clients = [];

    /**
     * Create a new keep-alive HTTP server instance.
     */
    private function __construct(
        public readonly int $port,
        private readonly Socket $server,
        private readonly WaitGroup $running,
    ) {
    }

    /**
     * Start a coroutine HTTP listener on an ephemeral loopback port that keeps connections open between requests.
     *
     * Every request without a body is answered with "OK"; a connection stays
     * open until the client closes it, it stays idle for two seconds or the
     * server stops.
     */
    public static function start(): self
    {
        $socket = new Socket(AF_INET, SOCK_STREAM, 0);

        if (! $socket->bind('127.0.0.1', 0) || ! $socket->listen()) {
            $socket->close();

            throw new RuntimeException('The keep-alive HTTP server could not bind.');
        }

        $server = new self($socket->getsockname()['port'], $socket, new WaitGroup);
        $server->running->add();

        Coroutine::create(static function () use ($server): void {
            try {
                while (! $server->stopped) {
                    $client = $server->server->accept(1);

                    if ($client !== false) {
                        ++$server->accepted;
                        $server->serve($client);
                    }
                }
            } finally {
                $server->server->close();
                $server->running->done();
            }
        });

        return $server;
    }

    /**
     * Return the number of connections the server accepted.
     */
    public function accepted(): int
    {
        return $this->accepted;
    }

    /**
     * Stop the server, ending its waits for connections and requests, and wait for its coroutines to finish.
     */
    public function stop(): void
    {
        $this->stopped = true;
        $this->server->cancel();

        foreach ($this->clients as $client) {
            $client->cancel();
        }

        if (! $this->running->wait(5)) {
            throw new RuntimeException('The keep-alive HTTP server did not stop.');
        }
    }

    /**
     * Answer the requests of one connection in a coroutine of its own, until the client closes it, it stays idle or the server stops.
     */
    private function serve(Socket $client): void
    {
        $id = spl_object_id($client);
        $this->clients[$id] = $client;
        $this->running->add();

        Coroutine::create(function () use ($client, $id): void {
            try {
                $buffer = '';

                while (! $this->stopped) {
                    while (! str_contains($buffer, "\r\n\r\n")) {
                        $chunk = $client->recv(65536, 2);

                        if ($chunk === false || $chunk === '') {
                            return;
                        }

                        $buffer .= $chunk;
                    }

                    $buffer = explode("\r\n\r\n", $buffer, 2)[1];
                    $client->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK");
                }
            } finally {
                unset($this->clients[$id]);
                $client->close();
                $this->running->done();
            }
        });
    }
}
