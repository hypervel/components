<?php

declare(strict_types=1);

namespace WorkerStateDependency;

class ReportListener extends BaseReportListener
{
    public static bool $debounced = false;

    public function handle(): void
    {
    }

    public function debounceId(): string
    {
        self::$debounced = true;

        return 'reports';
    }
}
