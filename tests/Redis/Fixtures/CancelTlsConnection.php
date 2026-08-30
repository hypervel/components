<?php

declare(strict_types=1);

use Hypervel\Container\Container;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Mockery as m;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;

use function Swoole\Coroutine\run;

require $argv[1];

Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

run(function (): void {
    $server = new RespServer;
    $handshakeStarted = new Channel(1);
    $releasePeer = new Channel(1);
    $connectionCoroutineId = new Channel(1);
    $cancellation = null;
    $failure = null;

    $server->start(static function ($client) use ($handshakeStarted, $releasePeer): void {
        $clientHello = fread($client, 1);

        if ($clientHello === false || $clientHello === '') {
            throw new RuntimeException('The TLS client did not start its handshake.');
        }

        $handshakeStarted->push(true);
        $releasePeer->pop();
    });

    [$host, $port] = $server->hostAndPort();

    Coroutine::create(static function () use (
        $connectionCoroutineId,
        $host,
        $port,
        &$cancellation,
        &$failure,
    ): void {
        $connectionCoroutineId->push(Coroutine::getCid());

        try {
            new PhpRedisConnection(
                new Container,
                m::mock(PoolInterface::class),
                [
                    'url' => null,
                    'scheme' => 'tls',
                    'host' => $host,
                    'port' => $port,
                    'username' => null,
                    'password' => null,
                    'database' => 0,
                    'name' => null,
                    'timeout' => 30.0,
                    'read_timeout' => 30.0,
                    'context' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                    ],
                    'options' => [],
                    'prefix' => null,
                    'events' => false,
                    'max_retries' => 0,
                    'backoff_algorithm' => 'decorrelated_jitter',
                    'backoff_base' => 100,
                    'backoff_cap' => 1000,
                    'sentinel' => ['enabled' => false],
                    'pool' => [
                        'min_connections' => 1,
                        'max_connections' => 1,
                        'connect_timeout' => 30.0,
                        'wait_timeout' => 3.0,
                        'heartbeat' => -1.0,
                        'heartbeat_timeout' => 1.0,
                        'max_idle_time' => 60.0,
                        'max_lifetime' => -1.0,
                    ],
                ],
            );

            $failure = new RuntimeException('The TLS connection completed before cancellation.');
        } catch (CanceledException $exception) {
            $cancellation = $exception;
        } catch (Throwable $exception) {
            $failure = $exception;
        }
    });

    $coroutineId = $connectionCoroutineId->pop(2.0);

    try {
        if (! is_int($coroutineId)) {
            throw new RuntimeException('The connecting coroutine did not publish its ID.');
        }

        if ($handshakeStarted->pop(2.0) !== true) {
            throw new RuntimeException('The TLS handshake did not start.');
        }

        if (! Coroutine::cancel($coroutineId, true)) {
            throw new RuntimeException('The connecting coroutine could not be canceled.');
        }

        if (Coroutine::exists($coroutineId)) {
            throw new RuntimeException('The connecting coroutine remained active after cancellation.');
        }

        if ($failure !== null) {
            throw new RuntimeException('The TLS connection failed without exact cancellation.', previous: $failure);
        }

        if (! $cancellation instanceof CanceledException) {
            throw new RuntimeException('The TLS connection did not surface exact cancellation.');
        }
    } finally {
        $releasePeer->push(true);
        $server->wait();
    }
});

fwrite(STDOUT, "canceled\n");
