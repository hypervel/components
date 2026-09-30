<?php

declare(strict_types=1);

use Hypervel\Database\Connectors\MySqlConnector;
use Hypervel\Database\Connectors\PostgresConnector;
use Swoole\Coroutine;
use Swoole\Runtime;

use function Swoole\Coroutine\run;

require $argv[1];

[$driver, $operation, $hooked] = array_slice($argv, 2);

Runtime::enableCoroutine($hooked === 'yes' ? SWOOLE_HOOK_ALL : 0);

$execute = static function () use ($driver, $operation): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $connector = $driver === 'pgsql' ? new PostgresConnector : new MySqlConnector;
    $config = [
        'host' => '127.0.0.1',
        'port' => $port,
        'database' => 'testing',
        'username' => 'testing',
        'password' => 'testing',
        'connect_timeout' => $operation === 'timeout' ? 1 : 30,
        'modes' => [],
    ];
    $failure = null;
    $started = hrtime(true);
    $connect = static function () use ($connector, $config, &$failure): void {
        try {
            $connector->connect($config);
        } catch (Throwable $exception) {
            $failure = $exception;
        }
    };
    $client = null;

    try {
        if ($operation === 'cancel') {
            $coroutineId = Coroutine::create($connect);
            $client = stream_socket_accept($server, 1);

            if ($client === false) {
                throw new RuntimeException('The connection attempt did not reach the local listener.');
            }

            if (! Coroutine::cancel($coroutineId, true) || Coroutine::exists($coroutineId)) {
                throw new RuntimeException('The connection attempt did not stop after cancellation.');
            }
        } else {
            $connect();
        }

        echo json_encode([
            'exception' => $failure ? $failure::class : null,
            'message' => $failure?->getMessage(),
            'elapsed' => (hrtime(true) - $started) / 1e9,
        ], JSON_THROW_ON_ERROR);
    } finally {
        if (is_resource($client)) {
            fclose($client);
        }

        fclose($server);
    }
};

if ($hooked === 'yes') {
    run($execute);
} else {
    $execute();
}
