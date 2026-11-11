<?php

declare(strict_types=1);

namespace RuntimeInputs\Exports;

interface CreatesReportDrivers
{
    /**
     * Create the report driver.
     *
     * @return JsonDriver
     */
    public function createReportDriver(): object;
}
