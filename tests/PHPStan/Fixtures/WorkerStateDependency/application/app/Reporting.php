<?php

declare(strict_types=1);

namespace WorkerStateDependency;

class Reporting
{
    public function send(): void
    {
        ReportFacade::send();
    }
}
