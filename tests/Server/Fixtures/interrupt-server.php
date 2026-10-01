<?php

declare(strict_types=1);

use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\OnManagerStart;
use Hypervel\Core\Events\OnStart;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Coroutine\SignalRegistry;
use Hypervel\Server\Listeners\ShutdownOnInterruptListener;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Lead a new process group so the test can deliver SIGINT the way a terminal does.
if (posix_setsid() === -1) {
    fwrite(STDERR, 'Unable to start a new session.' . PHP_EOL);
    exit(1);
}

[, $mode, $workerNum, $coroutine] = $argv;

$server = new Server('127.0.0.1', 0, $mode === 'base' ? SWOOLE_BASE : SWOOLE_PROCESS);
$server->set([
    'enable_coroutine' => $coroutine === 'coroutine',
    'log_level' => SWOOLE_LOG_WARNING,
    'worker_num' => (int) $workerNum,
]);
$signals = new SignalRegistry;
$listener = new ShutdownOnInterruptListener($signals);
$application = new stdClass;

// Mirror Server::defaultCallbacks(), which only dispatches OnStart in PROCESS mode.
if ($mode === 'process') {
    $server->on('start', static function (Server $server) use ($listener): void {
        $listener->handle(new OnStart($server));
        fwrite(STDOUT, 'master started' . PHP_EOL);
    });
}

$server->on('managerStart', static function (Server $server) use ($listener): void {
    $listener->handle(new OnManagerStart($server));
    fwrite(STDOUT, 'manager started ' . posix_getpid() . PHP_EOL);
});
$server->on('workerStart', static function (Server $server, int $workerId) use ($listener, $signals, $application): void {
    // Configured signal handlers share the worker's registry and register during BeforeWorkerStart,
    // before the server's AfterWorkerStart listeners.
    $signals->register($application, SIGUSR2, static function (): void {
        fwrite(STDOUT, 'application signal received' . PHP_EOL);
    });
    $signals->register($application, SIGINT, static function (): void {
        fwrite(STDOUT, 'application interrupt received' . PHP_EOL);
    });

    $listener->handle(new AfterWorkerStart($server, $workerId));

    fwrite(STDOUT, 'worker started ' . posix_getpid() . PHP_EOL);
});
$server->on('workerExit', static function (Server $server, int $workerId) use ($listener, $signals, $application): void {
    $listener->handle(new OnWorkerExit($server, $workerId));
    $signals->unregister($application);
});
$server->on('request', static fn (Request $request, Response $response): bool => $response->end());
$server->on('shutdown', static function (): void {
    fwrite(STDOUT, 'server shutdown' . PHP_EOL);
});

$server->start();
