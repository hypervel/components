<?php

declare(strict_types=1);

namespace WorkerStateDependency;

class Usage
{
    public function report(): ReportService
    {
        return app(ReportService::class);
    }
}
