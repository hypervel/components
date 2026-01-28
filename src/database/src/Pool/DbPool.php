<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ConnectionInterface;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Pool\Frequency;
use Hypervel\Pool\Pool;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use PDO;
use Psr\Container\ContainerInterface;

/**
 * Database connection pool.
 *
 * Extends the base Pool to create PooledConnection instances that wrap
 * our Laravel-ported Connection class.
 *
 * For in-memory SQLite databases, manages a shared PDO instance so all
 * pool slots see the same data (otherwise each slot would get an empty database).
 */
class DbPool extends Pool
{
    protected array $config;

    /**
     * Shared PDO for in-memory SQLite databases.
     *
     * When using connection pooling with in-memory SQLite (:memory:), all pool
     * slots must share the same PDO instance. Otherwise, each pooled connection
     * would get its own empty in-memory database, causing "table not found" errors.
     *
     * This only applies to in-memory SQLite, not file-backed SQLite databases.
     */
    protected ?PDO $sharedInMemorySqlitePdo = null;

    public function __construct(
        ContainerInterface $container,
        protected string $name
    ) {
        $configService = $container->get(ConfigInterface::class);
        $key = sprintf('databases.%s', $this->name);

        if (! $configService->has($key)) {
            throw new InvalidArgumentException(sprintf('Database connection [%s] not configured.', $this->name));
        }

        // Include the connection name in the config
        $this->config = $configService->get($key);
        $this->config['name'] = $name;

        // Extract pool options
        $poolOptions = Arr::get($this->config, 'pool', []);

        $this->frequency = new Frequency($this);

        parent::__construct($container, $poolOptions);

        // For in-memory SQLite, pre-create a shared PDO so all pool slots
        // see the same database. This must happen after parent::__construct.
        if ($this->isInMemorySqlite()) {
            $this->sharedInMemorySqlitePdo = $this->createSharedInMemorySqlitePdo();
        }
    }

    /**
     * Get the pool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get the shared PDO for in-memory SQLite, or null for other drivers/configurations.
     */
    public function getSharedInMemorySqlitePdo(): ?PDO
    {
        return $this->sharedInMemorySqlitePdo;
    }

    /**
     * Create a new pooled connection.
     */
    protected function createConnection(): ConnectionInterface
    {
        return new PooledConnection($this->container, $this, $this->config);
    }

    /**
     * Create the shared PDO for in-memory SQLite via the factory.
     *
     * Uses the normal factory pipeline to get all config parsing, driver
     * extensions, and connection setup. We then extract the PDO and let
     * the Connection object be garbage collected.
     */
    protected function createSharedInMemorySqlitePdo(): PDO
    {
        $factory = $this->container->get(ConnectionFactory::class);
        $connection = $factory->make($this->config, $this->name);

        return $connection->getPdo();
    }

    /**
     * Check if this pool is for an in-memory SQLite database.
     */
    protected function isInMemorySqlite(): bool
    {
        if (($this->config['driver'] ?? '') !== 'sqlite') {
            return false;
        }

        $database = $this->config['database'] ?? '';

        return $database === ':memory:'
            || str_contains($database, '?mode=memory')
            || str_contains($database, '&mode=memory');
    }

    /**
     * Flush all connections and clear the shared in-memory SQLite PDO.
     */
    public function flushAll(): void
    {
        parent::flushAll();
        $this->sharedInMemorySqlitePdo = null;
    }
}
