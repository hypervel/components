<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

$concurrency = 8;
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Swoole\Coroutine\run(static function () use ($concurrency): void {
    $listener = new Socket(AF_INET, SOCK_STREAM, 0);
    $listener->bind('127.0.0.1', 0);
    $listener->listen();
    $url = 'http://127.0.0.1:' . $listener->getsockname()['port'];
    $payload = str_repeat('x', 16384);
    $samples = [];

    try {
        for ($sample = 0; $sample < 3; ++$sample) {
            for ($batch = 0; $batch < 2; ++$batch) {
                $arrived = new Channel($concurrency);
                $done = new Channel($concurrency);

                Coroutine::create(static function () use ($listener, $arrived, $concurrency): void {
                    for ($index = 0; $index < $concurrency; ++$index) {
                        $client = $listener->accept(2);
                        $request = '';
                        while (! str_contains($request, "\r\n\r\n")) {
                            $chunk = $client->recv(2);
                            if ($chunk === false || $chunk === '') {
                                throw new RuntimeException('Request ended before its headers.');
                            }
                            $request .= $chunk;
                        }
                        $arrived->push($client);
                    }
                });

                for ($index = 0; $index < $concurrency; ++$index) {
                    Coroutine::create(static function () use ($url, $done): void {
                        $context = stream_context_create(['http' => ['timeout' => 2, 'header' => 'Connection: close']]);
                        $stream = fopen($url, 'r', false, $context);
                        if ($stream === false) {
                            throw new RuntimeException('HTTP open failed.');
                        }
                        fclose($stream);
                        $done->push(true);
                    });
                }

                $clients = [];
                for ($index = 0; $index < $concurrency; ++$index) {
                    $client = $arrived->pop(2);
                    if (! $client instanceof Socket) {
                        throw new RuntimeException('Not all requests reached the barrier.');
                    }
                    $clients[] = $client;
                }
                // Every fopen has cleared the global and is waiting for headers.
                foreach ($clients as $client) {
                    $client->sendAll("HTTP/1.1 200 OK\r\nX-Payload: {$payload}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
                    $client->close();
                }
                for ($index = 0; $index < $concurrency; ++$index) {
                    if ($done->pop(2) !== true) {
                        throw new RuntimeException('A client did not finish.');
                    }
                }
                unset($clients, $client, $arrived, $done);
            }
            Coroutine::sleep(0.001);
            http_clear_last_response_headers();
            gc_collect_cycles();
            $samples[] = memory_get_usage();
        }
    } finally {
        $listener->close();
    }

    echo json_encode($samples, JSON_THROW_ON_ERROR);
});
