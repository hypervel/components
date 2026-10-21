<?php

declare(strict_types=1);

use Hypervel\Coroutine\Coroutine;
use Hypervel\Mcp\Server\Transport\StdioTransport;

use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

run(static function () use ($argv): void {
    $transport = new StdioTransport;

    if (($argv[1] ?? null) === 'send') {
        $transport->send('{"test": "message"}');

        return;
    }

    $transport->onReceive(static function (string $message) use ($transport): void {
        $transport->send(rtrim($message, "\r\n"));
    });

    Coroutine::create(static function () use ($transport): void {
        $transport->run();
    });

    // The transport must yield while reading before this coroutine can announce readiness.
    $transport->send('ready');
});
