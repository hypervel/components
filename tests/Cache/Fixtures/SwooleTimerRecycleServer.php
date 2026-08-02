<?php

declare(strict_types=1);

use Hypervel\Cache\Listeners\CreateSwooleTimers;
use Hypervel\Cache\SwooleTimer;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Core\Events\AfterWorkerStart;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require $argv[1];

$port = (int) $argv[2];
$statePath = $argv[3];
$logPath = $argv[4];
$container = new Container;
$container->instance('config', new Repository([
    'cache' => [
        'stores' => [
            'swoole' => [
                'driver' => 'swoole',
                'eviction_interval' => 60_000,
                'interval_refresh_interval' => 60_000,
            ],
        ],
    ],
]));
$timers = new CreateSwooleTimers($container, new SwooleTimer);
$server = new Server('127.0.0.1', $port);
$server->set([
    'worker_num' => 1,
    'max_request' => 1,
    'max_wait_time' => 1,
    'log_file' => $logPath,
]);
$server->on('workerStart', function (Server $server, int $workerId) use ($timers, $statePath): void {
    $timers->handle(new AfterWorkerStart($server, $workerId));
    file_put_contents($statePath, "start\n", FILE_APPEND | LOCK_EX);
});
$server->on('workerExit', function () use ($timers, $statePath): void {
    $timers->stop();
    file_put_contents($statePath, "exit\n", FILE_APPEND | LOCK_EX);
});
$server->on('request', static function (Request $request, Response $response): void {
    $response->end('ok');
});
$server->start();
