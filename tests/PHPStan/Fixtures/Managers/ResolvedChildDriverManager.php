<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;

/**
 * A manager whose CSV driver was created before a creator for a CSV driver subclass was registered.
 */
class ResolvedChildDriverManager extends Manager
{
    /**
     * Create the CSV driver.
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
