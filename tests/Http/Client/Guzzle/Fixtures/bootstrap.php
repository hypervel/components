<?php

declare(strict_types=1);

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use Hypervel\Di\Aop\ProxyMethod;
use Hypervel\Di\Bootstrap\GenerateProxies;
use Hypervel\Foundation\Application;
use Hypervel\Http\Client\Guzzle\CoroutineState;
use Hypervel\Http\Exceptions\CoroutineOwnershipException;
use Hypervel\Http\HttpServiceProvider;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Swoole\Coroutine;

require $argv[1];

$mode = $argv[2];
$directory = $argv[3];

if ($mode === 'early') {
    new Promise;
}

$application = new Application;
$provider = new HttpServiceProvider($application);
$provider->register();
(new GenerateProxies)->generate($directory);

if ($mode === 'normal') {
    echo 'parser:' . (class_exists(ParserFactory::class, false) ? 'loaded' : 'unloaded') . "\n";
    echo 'printer:' . (class_exists(Standard::class, false) ? 'loaded' : 'unloaded') . "\n";
}

if ($mode === 'shutdown') {
    Utils::queue()->add(static function (): void {
        echo "outside-shutdown\n";
    });

    exit;
}

if ($mode === 'outside-transfer') {
    $handler = new CurlMultiHandler;
    $promise = $handler(new Request('GET', 'http://127.0.0.1:1/'), ['timeout' => 1]);
    $handlerReference = WeakReference::create($handler);
    $promiseReference = WeakReference::create($promise);
    unset($handler, $promise);

    Coroutine\run(static function () use ($handlerReference, $promiseReference): void {
        gc_collect_cycles();
        if ($handlerReference->get() === null || $promiseReference->get() === null) {
            throw new RuntimeException('An outside transfer was collected in another coroutine.');
        }
    });

    CoroutineState::current()?->cancel();
    gc_collect_cycles();
    if ($handlerReference->get() !== null || $promiseReference->get() !== null) {
        throw new RuntimeException('Canceled outside transfers were retained.');
    }

    echo "outside-transfer-retained-and-released\n";
    exit;
}

$queue = Utils::queue();
$provider->register();
(new GenerateProxies)->generate($directory);
if (Utils::queue() !== $queue) {
    throw new RuntimeException('Repeated boot replaced the task queue.');
}

foreach (['__construct', 'then', 'wait', 'cancel', 'resolve', 'reject'] as $method) {
    if ((new ReflectionMethod(Promise::class, $method))->getAttributes(ProxyMethod::class) === []) {
        throw new RuntimeException("Missing interception: {$method}");
    }
}

$promise = new Promise;
Coroutine\run(static function () use ($promise): void {
    try {
        $promise->resolve('foreign');
    } catch (CoroutineOwnershipException) {
        echo "cold-boot-enforced\n";
        return;
    }

    throw new RuntimeException('Cold boot did not activate ownership.');
});
$promise->resolve('owner');
echo $promise->wait() . "\n";
