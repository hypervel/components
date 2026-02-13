<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\Queue\Fixtures;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Support\Facades\DB;

class TimeOutNonBatchableJobWithTransaction implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 2;

    public function handle(): void
    {
        DB::transaction(fn () => sleep(20));
    }
}
