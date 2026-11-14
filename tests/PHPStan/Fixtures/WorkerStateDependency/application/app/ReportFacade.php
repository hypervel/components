<?php

declare(strict_types=1);

namespace WorkerStateDependency;

use Hypervel\Support\Facades\Facade;

class ReportFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SupportedReporter::class;
    }
}
