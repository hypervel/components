<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;
use RuntimeException;

/**
 * A custom manager that forwards calls to its default CSV driver without declaring a mixin.
 *
 * @method int count()
 */
class ExportManager extends Manager
{
    /**
     * Create the CSV driver.
     *
     * Analysis types the driver from this declaration without building it.
     */
    protected function createCsvDriver(): CsvDriver
    {
        throw new RuntimeException('Analysis must not build drivers.');
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return 'csv';
    }
}
