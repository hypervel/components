<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionInterface;
use Hyperf\DbConnection\ConnectionResolver;

class DatabaseConnectionResolver extends ConnectionResolver
{
    /**
     * Connections for testing environment.
     */
    protected static array $connections = [];

    /**
     * Get a database connection instance.
     */
    public function connection(?string $name = null): ConnectionInterface
    {
        if (is_null($name)) {
            $name = $this->getDefaultConnection();
        }

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
