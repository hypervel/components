<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;
use InvalidArgumentException;
use UnitEnum;

/**
 * A manager whose driver() override declares the driver it selects, as SocialiteManager does.
 */
class SelectingManager extends Manager
{
    /**
     * Get a driver instance.
     */
    public function driver(UnitEnum|string|null $driver = null): JsonDriver
    {
        return new JsonDriver;
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        throw new InvalidArgumentException('No driver was specified.');
    }
}
