<?php

declare(strict_types=1);

namespace WorkerStateDependency;

use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Support\Facades\DB;
use Throwable;

class ImportJob implements ShouldQueue
{
    public function handle(): void
    {
        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            throw new ImportFailed($exception);
        }
    }
}
