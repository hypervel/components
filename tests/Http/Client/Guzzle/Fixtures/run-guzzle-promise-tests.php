<?php

declare(strict_types=1);

use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils;
use Hypervel\Di\Aop\ProxyMethod;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Http\Client\Guzzle\CoroutineTaskQueue;
use Hypervel\Http\HttpServiceProvider;
use PHPUnit\TextUI\Command;

[$script, $autoload, $upstream, $mode] = $argv;

if (! in_array($mode, ['stock', 'outside', 'coroutine'], true)) {
    throw new InvalidArgumentException('Choose stock, outside, or coroutine execution.');
}

$loader = require $autoload;
$loader->addPsr4('GuzzleHttp\Promise\Tests\\', $upstream . '/tests');
$arguments = [$script, '--no-configuration', '--colors=never', '--do-not-cache-result', '--disallow-test-output'];

if ($mode !== 'stock') {
    // This one assertion requires Guzzle's default concrete queue, before a custom queue is installed.
    $arguments[] = '--filter';
    $arguments[] = '/^(?!.*::testReturnsTrampoline$)/';
}

$arguments[] = $upstream . '/tests';

$run = static function () use ($mode, $arguments): int {
    if ($mode !== 'stock') {
        (new HttpServiceProvider(new Application))->register();
        $directory = sys_get_temp_dir() . '/hypervel-upstream-promises-' . getmypid();
        register_shutdown_function(static function () use ($directory): void {
            (new Filesystem)->deleteDirectory($directory);
        });
        (new GenerateProxies)->generate($directory);

        if (! Utils::queue() instanceof CoroutineTaskQueue) {
            throw new RuntimeException('The production queue integration was not installed.');
        }
        foreach (['__construct', 'then', 'wait', 'cancel', 'resolve', 'reject'] as $method) {
            if ((new ReflectionMethod(Promise::class, $method))->getAttributes(ProxyMethod::class) === []) {
                throw new RuntimeException("Promise::{$method} was not intercepted.");
            }
        }
    }

    return (new Command)->run($arguments, false);
};

$exitCode = 1;
if ($mode === 'coroutine') {
    Swoole\Coroutine\run(static function () use ($run, &$exitCode): void {
        $exitCode = $run();
    });
} else {
    $exitCode = $run();
}

exit($exitCode);
