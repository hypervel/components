<?php

declare(strict_types=1);

namespace RuntimeInputs;

use RuntimeInputs\Exports\ReportManager;

class ReportUsage
{
    public function report(ReportManager $reports): int
    {
        return $reports->export();
    }
}
