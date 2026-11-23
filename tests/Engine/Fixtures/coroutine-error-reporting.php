<?php

declare(strict_types=1);

use Hypervel\Engine\Coroutine;

use function Swoole\Coroutine\run;

require $argv[1];

run(static function (): void {
    $levels = [];
    $coroutine = Coroutine::create(static function () use (&$levels): void {
        $levels[] = error_reporting();
        Coroutine::yield();
        $levels[] = error_reporting();
    });

    Coroutine::resumeById($coroutine->getId());

    echo json_encode($levels, JSON_THROW_ON_ERROR);
});
