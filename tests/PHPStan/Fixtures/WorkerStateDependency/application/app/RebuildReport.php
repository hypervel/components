<?php

declare(strict_types=1);

namespace WorkerStateDependency;

use Hypervel\Contracts\Queue\ShouldQueue;

class RebuildReport implements ShouldQueue
{
    public function handle(): void
    {
        Registry::map([]);
    }
}
