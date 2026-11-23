<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;
use Hypervel\Support\Traits\Macroable;

/**
 * A manager whose Macroable __call() replaces forwarding to its driver, as ChannelManager's does.
 */
class MacroableExportManager extends Manager
{
    use Macroable;

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
