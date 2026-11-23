<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

/**
 * A CSV driver subclass that a creator supplies after the manager cached a plain CSV driver.
 */
class TabbedCsvDriver extends CsvDriver
{
    /**
     * Export the rows separated by tabs.
     */
    public function exportTabbed(): string
    {
        return '';
    }
}
