<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateGlobals;

trait KeepsRequest
{
    public function keepRequest(): void
    {
        global $kept;
        $kept = request();
    }
}
