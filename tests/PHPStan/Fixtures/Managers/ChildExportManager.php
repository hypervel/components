<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

/**
 * The bound subclass of BoundExportManager, which selects a driver only it can create.
 */
class ChildExportManager extends BoundExportManager
{
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
        return 'json';
    }
}
