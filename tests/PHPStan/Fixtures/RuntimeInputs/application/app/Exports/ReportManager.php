<?php

declare(strict_types=1);

namespace RuntimeInputs\Exports;

use Hypervel\Support\Manager;

class ReportManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return 'report';
    }
}
