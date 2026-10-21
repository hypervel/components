<?php

declare(strict_types=1);

use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use Hypervel\Foundation\Application;
use Hypervel\Http\Client\Guzzle\CoroutineState;
use Hypervel\Http\HttpServiceProvider;
use Swoole\Coroutine;

require $argv[1];

$mode = $argv[2];

$application = new Application;
$provider = new HttpServiceProvider($application);
$provider->register();

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
    Utils::queue()->run();
    gc_collect_cycles();
    if ($handlerReference->get() !== null || $promiseReference->get() !== null) {
        throw new RuntimeException('Canceled outside transfers were retained.');
    }

    echo "outside-transfer-retained-and-released\n";
    exit;
}
