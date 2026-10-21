<?php

declare(strict_types=1);

use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\run;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

run(static function (): void {
    Coroutine::create(static function (): void {
        fwrite(STDOUT, fgets(STDIN));
    });

    // The reader must yield so this coroutine can report readiness before input completes.
    fwrite(STDOUT, "ready\n");
});
