<?php

declare(strict_types=1);

namespace Hypervel\Database;

use UnitEnum;

interface ConnectionResolverInterface
{
    /**
     * Get a database connection instance.
     *
     * Unlike Laravel's ConnectionInterface, this returns the Connection base class, which every
     * driver extends, because Eloquent, migrations and the manager rely on it.
     */
    public function connection(UnitEnum|string|null $name = null): Connection;

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): ?string;

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(?string $name): void;
}
