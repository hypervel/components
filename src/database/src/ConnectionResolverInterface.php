<?php

declare(strict_types=1);

namespace Hypervel\Database;

use UnitEnum;

interface ConnectionResolverInterface
{
    /**
     * Get a database connection instance.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface;

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string;

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(string $name): void;
}
