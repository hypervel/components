<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;

/**
 * A manager whose callCustomCreator() override declares what it makes of a registered creator's
 * result, as SessionManager does.
 */
class WrappedCreatorManager extends Manager
{
    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(string $driver): JsonDriver
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
