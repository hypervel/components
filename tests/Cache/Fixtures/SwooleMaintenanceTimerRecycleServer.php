<?php

declare(strict_types=1);

use Hypervel\Cache\CacheManager;
use Hypervel\Cache\Listeners\CreateSwooleTable;
use Hypervel\Cache\Listeners\RegisterSwooleMaintenanceTimers;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Coordinator\Timer;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require $argv[1];

$port = (int) $argv[2];
$statePath = $argv[3];
$logPath = $argv[4];
$container = new Container;
$config = new Repository([
    'cache' => [
        'stores' => [
            'swoole' => [
                'driver' => 'swoole',
                'table' => 'default',
                'eviction_interval' => 60_000,
                'interval_refresh_interval' => 60_000,
            ],
        ],
        'swoole_tables' => [
            'default' => [
                'rows' => 64,
                'bytes' => 1024,
                'conflict_proportion' => 0.2,
            ],
        ],
    ],
]);
$container->instance(ContainerContract::class, $container);
$container->instance('config', $config);
$container->instance('cache', new CacheManager($container));

// Replacement workers must inherit one shared table instead of allocating a
// worker-local table on every recycle.
(new CreateSwooleTable($container, $config))->handle(new BeforeServerStart('http'));

$timers = new RegisterSwooleMaintenanceTimers($container, new Timer, $config);
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
$server->on('workerExit', function () use ($statePath): void {
    CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
    file_put_contents($statePath, "exit\n", FILE_APPEND | LOCK_EX);
});
$server->on('request', static function (Request $request, Response $response): void {
    $response->end('ok');
});
$server->start();
