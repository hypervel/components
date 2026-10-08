<?php

declare(strict_types=1);

use Hypervel\Http\Client\ConnectionException;
use Hypervel\Http\Client\Factory;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

require __DIR__ . '/../../../vendor/autoload.php';

Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
Swoole\Coroutine\run(static function (): void {
    $listener = new Socket(AF_INET, SOCK_STREAM, 0);
    $listener->bind('127.0.0.1', 0);
    $listener->listen();
    $url = 'http://127.0.0.1:' . $listener->getsockname()['port'];
    $arrived = new Channel(1);
    $release = new Channel(1);
    $finished = new Channel(2);
    $result = new Channel(1);
    $factory = (new Factory)->registerConnection('shared');

    Coroutine::create(static function () use ($listener, $arrived, $release, $finished): void {
        $client = $listener->accept(2);
        try {
            $request = '';
            while (! str_contains($request, "\r\n\r\n")) {
                $chunk = $client->recv(2);
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Request ended before its headers.');
                }
                $request .= $chunk;
            }
            $arrived->push(true);
            if ($release->pop(2) !== true) {
                throw new RuntimeException('The first response was not released.');
            }
            $client->sendAll("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK");
        } finally {
            $client->close();
            $finished->push(true);
        }
    });

    Coroutine::create(static function () use ($factory, $url, $result): void {
        try {
            $response = $factory->connection('shared')->timeout(0)->withOptions([
                'stream' => true, 'stream_context' => [], 'read_timeout' => 2, 'proxy' => '',
            ])->get($url);
            try {
                $result->push($response->body());
            } finally {
                $response->close();
            }
        } catch (Throwable $exception) {
            $result->push($exception->getMessage());
        }
    });

    if ($arrived->pop(2) !== true) {
        throw new RuntimeException('The first request did not reach the origin.');
    }
    Coroutine::create(static function () use ($listener, $finished): void {
        $client = $listener->accept(2);
        try {
            // Do not send headers. The second client's own timeout releases us.
            while (($data = $client->recv(2)) !== '' && $data !== false);
        } finally {
            $client->close();
            $finished->push(true);
        }
    });

    try {
        $factory->connection('shared')->timeout(0.02)->withOptions([
            'stream' => true, 'stream_context' => [], 'read_timeout' => 2, 'proxy' => '',
        ])->get($url);
        throw new RuntimeException('Expected request B to time out.');
    } catch (ConnectionException) {
        // Native timeout rounding can return just before the monotonic deadline.
        // Let a full B timeout interval pass before releasing A's headers.
        Coroutine::sleep(0.03);
        $release->push(true);
    }

    echo json_encode($result->pop(2), JSON_THROW_ON_ERROR);
    if ($finished->pop(2) !== true || $finished->pop(2) !== true) {
        throw new RuntimeException('The origin requests did not finish.');
    }
    $listener->close();
});
