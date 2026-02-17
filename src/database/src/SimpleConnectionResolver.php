<?php

declare(strict_types=1);

namespace Hypervel\Database;

use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * A simple, non-pooled connection resolver for testing and Capsule environments.
 *
 * Unlike ConnectionResolver which uses connection pooling for Swoole coroutines,
 * this resolver manages connections directly in memory without pooling. It's
 * intended for:
 * - Unit/integration tests using in-memory SQLite
 * - Capsule standalone database usage
 * - Environments where connection pooling is not needed
 *
 * For production Swoole applications, use ConnectionResolver instead.
 */
class SimpleConnectionResolver implements ConnectionResolverInterface
{
    /**
     * The default connection name.
     */
    protected string $default = 'default';

    public function __construct(
        protected DatabaseManager $manager
    ) {
    }

    /**
     * Get a database connection instance.
     *
     * Delegates to DatabaseManager::resolveConnectionDirectly() which manages
     * connections in a simple array without pooling.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface
    {
        return $this->manager->resolveConnectionDirectly(
            enum_value($name) ?? $this->getDefaultConnection()
        );
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        return $this->default;
    }

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(string $name): void
    {
        $this->default = $name;
    }
}
