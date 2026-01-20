<?php

declare(strict_types=1);

namespace Hypervel\Database;

interface ConnectionResolverInterface
{
    /**
     * Get a database connection instance.
     *
     * @param \UnitEnum|string|null $name
     */
    public function connection($name = null): ConnectionInterface;

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string;

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(string $name): void;
}
