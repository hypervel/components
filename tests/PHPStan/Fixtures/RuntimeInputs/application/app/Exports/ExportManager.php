<?php

declare(strict_types=1);

namespace RuntimeInputs\Exports;

use Hypervel\Support\Manager;

class ExportManager extends Manager
{
    /**
     * Create the CSV driver.
     */
    protected function createCsvDriver(): CsvDriver
    {
        return new CsvDriver;
    }

    /**
     * Create the JSON driver.
     */
    protected function createJsonDriver(): JsonDriver
    {
        return new JsonDriver;
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return 'csv';
    }
}
