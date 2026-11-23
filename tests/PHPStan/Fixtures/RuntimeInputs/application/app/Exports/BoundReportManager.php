<?php

declare(strict_types=1);

namespace RuntimeInputs\Exports;

/**
 * Bound in place of the report manager. Its driver creator takes its return type from the interface.
 */
class BoundReportManager extends ReportManager implements CreatesReportDrivers
{
    public function createReportDriver(): object
    {
        return new JsonDriver;
    }
}
