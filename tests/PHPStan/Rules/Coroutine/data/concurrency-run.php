<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data;

use Hypervel\Support\Facades\Concurrency;
use Hypervel\Support\Facades\DB;

class ConcurrencyRun
{
    public function runsInTransaction(): void
    {
        DB::transaction(function (): void {
            Concurrency::run([fn () => 1]);
        });
    }
}
