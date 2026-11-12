<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Managers;

use Hypervel\Support\Manager;
use UnitEnum;

/**
 * A manager whose driver is its template type.
 *
 * @template T of object
 */
class GenericManager extends Manager
{
    /**
     * Get a driver instance.
     *
     * @return T
     */
    public function driver(UnitEnum|string|null $driver = null): mixed
    {
        return parent::driver($driver);
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return 'default';
    }
}
