<?php

declare(strict_types=1);

namespace WorkerStateDependency;

use Hypervel\Contracts\Queue\ShouldQueue;

abstract class BaseReportListener implements ShouldQueue
{
    public ?int $debounceFor = null;
}
