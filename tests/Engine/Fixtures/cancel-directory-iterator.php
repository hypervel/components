<?php

declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;
use Swoole\Event;
use Swoole\Runtime;

require $argv[1];

Runtime::enableCoroutine(SWOOLE_HOOK_FILE);

Coroutine\run(static function (): void {
    try {
        $coroutine = Coroutine::getCid();

        foreach (new DirectoryIterator(__DIR__) as $entry) {
            Event::defer(static function () use ($coroutine): void {
                Coroutine::cancel($coroutine, true);
            });
        }

        exit(1);
    } catch (CanceledException) {
        echo "canceled\n";
    }
});
