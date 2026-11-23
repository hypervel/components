<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;

/**
 * A manager whose createDriver() override declares the driver it creates, as ImageManager does.
 */
class WrappingManager extends Manager
{
    /**
     * Create a new driver instance.
     */
    protected function createDriver(string $driver): JsonDriver
    {
        return new JsonDriver;
    }

    /**
     * Create the CSV driver, which the createDriver() override replaces.
     */
    protected function createCsvDriver(): CsvDriver
    {
        return new CsvDriver;
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return 'csv';
    }
}
