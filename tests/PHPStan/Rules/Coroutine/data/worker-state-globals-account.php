<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\WorkerStateGlobals;

trait KeepsAccount
{
    public function keepAccount(): void
    {
        global $kept;
        $kept = auth()->user();
    }
}
