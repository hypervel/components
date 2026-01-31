<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Database\FlushableConnectionResolver;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Database connection resolver for the testing environment.
 *
 * Caches connections statically to prevent pool exhaustion (since the testing
 * environment doesn't use defer() to release connections back to the pool).
 * Call resetCachedConnections() at the start of each test to ensure clean
 * state without the test pollution that static caching would otherwise cause.
 */
class DatabaseConnectionResolver extends ConnectionResolver implements FlushableConnectionResolver
{
    /**
     * Connections for testing environment.
     *
     * @var array<string, ConnectionInterface>
     */
    protected static array $connections = [];

    /**
     * Reset all cached connections to clean state.
     *
     * Called at the start of each test to prevent test pollution (query logs,
     * event listeners, transaction state, etc.) from leaking between tests.
     */
    public static function resetCachedConnections(): void
    {
        foreach (static::$connections as $connection) {
            if ($connection instanceof Connection) {
                $connection->resetForPool();
            }
        }
    }

    /**
     * Flush a cached connection.
     *
     * Clears the static cache so the next connection() call creates a fresh
     * connection with current configuration.
     */
    public function flush(string $name): void
    {
        unset(static::$connections[$name]);
    }

    /**
     * Get a database connection instance.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();

        // If the pool is enabled, we should use the default connection resolver.
        $poolEnabled = $this->container
            ->get(ConfigInterface::class)
            ->get("database.connections.{$name}.pool.testing_enabled", false);
        if ($poolEnabled) {
            return parent::connection($name);
        }

        if ($connection = static::$connections[$name] ?? null) {
            return $connection;
        }

        return static::$connections[$name] = $this->factory
            ->getPool($name)
            ->get()
            ->getConnection();
    }
}
