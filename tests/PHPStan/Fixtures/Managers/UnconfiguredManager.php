<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;

/**
 * A manager whose default driver is missing from the configuration.
 */
class UnconfiguredManager extends Manager
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
        return $this->config->string('exports.default');
    }
}
