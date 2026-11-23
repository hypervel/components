<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;
use RuntimeException;

/**
 * A manager whose getCreatorMethod() override chooses its creator methods.
 */
class RenamingManager extends Manager
{
    /**
     * Get the name of the manager's creator method for the given driver.
     *
     * Analysis never calls an override, so it forwards nothing for this manager.
     */
    protected function getCreatorMethod(string $driver): ?string
    {
        throw new RuntimeException('Analysis must not call an overriding getCreatorMethod().');
    }

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
