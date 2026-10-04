<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Fixtures;

use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

class LoopbackHttpServer
{
    /**
     * Create a new loopback HTTP server instance.
     */
    private function __construct(
        public readonly int $port,
        private readonly Channel $requests,
    ) {
    }

    /**
     * Start a coroutine HTTP listener on an ephemeral loopback port.
     *
     * Each accepted connection reads one request and receives the next scripted
     * response; the listener closes after the last one or after two idle seconds.
     *
     * @param list<array{status?: int, headers?: array<string, string>, body?: string, delay?: float}> $responses
     */
    public static function start(array $responses = [[]]): self
    {
        $server = new Socket(AF_INET, SOCK_STREAM, 0);

        if (! $server->bind('127.0.0.1', 0) || ! $server->listen()) {
            $server->close();

            throw new RuntimeException('The loopback HTTP server could not bind.');
        }

        $port = $server->getsockname()['port'];
        $requests = new Channel(count($responses));

        Coroutine::create(static function () use ($server, $requests, $responses): void {
            try {
                foreach ($responses as $response) {
                    $client = $server->accept(2);

                    if ($client === false) {
                        return;
                    }

                    try {
                        $requests->push(self::receiveRequest($client));

                        if (($response['delay'] ?? 0.0) > 0.0) {
                            Coroutine::sleep($response['delay']);
                        }

                        $client->sendAll(self::renderResponse($response));
                    } finally {
                        $client->close();
                    }
                }
            } finally {
                $server->close();
            }
        });

        return new self($port, $requests);
    }

    /**
     * Return the next request the server received, or null when none arrived.
     */
    public function request(): ?string
    {
        $request = $this->requests->pop(2);

        return is_string($request) ? $request : null;
    }

    /**
     * Read one request head and its declared body.
     */
    private static function receiveRequest(Socket $client): string
    {
        $request = '';

        while (! str_contains($request, "\r\n\r\n")) {
            $chunk = $client->recv(2);

            if ($chunk === false || $chunk === '') {
                return $request;
            }

            $request .= $chunk;
        }

        [$head, $body] = explode("\r\n\r\n", $request, 2);
        preg_match('/^Content-Length:\s*(\d+)$/mi', $head, $matches);
        $length = isset($matches[1]) ? (int) $matches[1] : 0;

        while (strlen($body) < $length) {
            $chunk = $client->recv(2);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        return $head . "\r\n\r\n" . $body;
    }

    /**
     * Render one scripted response.
     *
     * @param array{status?: int, headers?: array<string, string>, body?: string, delay?: float} $response
     */
    private static function renderResponse(array $response): string
    {
        $body = $response['body'] ?? 'OK';
        $head = 'HTTP/1.1 ' . ($response['status'] ?? 200) . " Loopback\r\n";

        foreach ($response['headers'] ?? [] as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return $head
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $body;
    }
}
