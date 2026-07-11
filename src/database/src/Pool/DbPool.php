<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Pool\Frequency;
use Hypervel\Pool\Pool;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Database connection pool.
 *
 * Extends the base Pool to create PooledConnection instances that wrap
 * our Laravel-ported Connection class.
 *
 * For in-memory SQLite, manages a shared PDO so all pool slots see the same
 * data. Non-pooled paths (Capsule, SimpleConnectionResolver) bypass this
 * entirely and get isolated connections as expected.
 */
class DbPool extends Pool
{
    protected array $config;

    protected ?Timer $heartbeatTimer = null;

    protected ?int $heartbeatTimerId = null;

    /**
     * Shared PDO for in-memory SQLite. All pool slots must share the same PDO
     * instance, otherwise each would get its own empty database.
     */
    protected ?PDO $sharedInMemorySqlitePdo = null;

    public function __construct(Container $container, string $name)
    {
        $connectionName = ConnectionName::parse($name);
        $configService = $container->make('config');
        $key = sprintf('database.connections.%s', $connectionName->base);

        if (! $configService->has($key)) {
            throw new InvalidArgumentException(sprintf('Database connection [%s] not configured.', $connectionName->base));
        }

        /** @var array<string, mixed> $config */
        $config = $configService->get($key);

        /** @var ConnectionFactory $factory */
        $factory = $container->make('db.factory');
        $config = $factory->parseConfig($config, $connectionName->base);

        if ($connectionName->isRead() && $factory->hasReadConfig($config)) {
            $config = $factory->configForRead($config);
            $this->ensureNotDerivedInMemorySqlitePool($connectionName, $config);
        }

        $this->config = $config;

        // Extract pool options
        $poolOptions = Arr::except(
            Arr::get($this->config, 'pool', []),
            ['testing_enabled'],
        );

        $this->frequency = new Frequency($this);

        parent::__construct($container, $name, $poolOptions);

        $this->heartbeatTimer = new Timer($this->getLogger());

        // For in-memory SQLite, pre-create a shared PDO so all pool slots
        // see the same database. This must happen after parent::__construct.
        if ($this->isInMemorySqlite()) {
            $this->sharedInMemorySqlitePdo = $this->createSharedInMemorySqlitePdo();
        }

        $this->startHeartbeat();
    }

    /**
     * Destroy the database pool.
     */
    public function __destruct()
    {
        $this->clearHeartbeat();
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
        $factory = $this->container->make('db.factory');
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
     * Ensure a derived read pool does not point at an in-memory SQLite database.
     */
    protected function ensureNotDerivedInMemorySqlitePool(ConnectionName $name, array $config): void
    {
        if (($config['driver'] ?? null) !== 'sqlite') {
            return;
        }

        $database = $config['database'] ?? '';

        if ($database === ':memory:' || str_contains($database, '?mode=memory') || str_contains($database, '&mode=memory')) {
            throw new InvalidArgumentException(
                "Database connection [{$name->requested}] cannot use a derived read pool for in-memory SQLite."
            );
        }
    }

    /**
     * Close the database pool and clear its shared resources.
     */
    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }

        $this->clearHeartbeat();

        parent::close();
        $this->sharedInMemorySqlitePdo = null;
    }

    /**
     * Start the heartbeat timer if configured.
     */
    protected function startHeartbeat(): void
    {
        if ($this->heartbeatTimer === null || $this->option->getHeartbeat() <= 0 || $this->sharedInMemorySqlitePdo !== null) {
            return;
        }

        $this->heartbeatTimerId = $this->heartbeatTimer->tick(
            $this->option->getHeartbeat(),
            function (bool $isClosing): ?string {
                if ($isClosing || $this->isClosed()) {
                    return Timer::STOP;
                }

                $this->heartbeat();

                return null;
            }
        );
    }

    /**
     * Clear the heartbeat timer.
     */
    protected function clearHeartbeat(): void
    {
        if ($this->heartbeatTimer === null || $this->heartbeatTimerId === null) {
            return;
        }

        $this->heartbeatTimer->clear($this->heartbeatTimerId);
        $this->heartbeatTimerId = null;
    }

    /**
     * Run one heartbeat sweep over currently idle connections.
     */
    protected function heartbeat(): void
    {
        $connectionsToInspect = $this->getConnectionsInChannel();

        for ($index = 0; $index < $connectionsToInspect; ++$index) {
            /** @var false|PooledConnection $connection */
            $connection = $this->popIdleConnection();

            if ($connection === false) {
                break;
            }

            $this->heartbeatConnection($connection);
        }
    }

    /**
     * Heartbeat one idle connection.
     */
    protected function heartbeatConnection(PooledConnection $connection): void
    {
        try {
            $now = microtime(true);

            if ($connection->isLifetimeExpired($now)) {
                $this->discardHeartbeatConnection($connection);

                return;
            }

            if ($connection->isIdleExpired($now)
                && $this->getCurrentConnections() > $this->option->getMinConnections()
            ) {
                $this->discardHeartbeatConnection($connection);

                return;
            }

            if ($connection->ping($this->option->getHeartbeatTimeout())) {
                if ($this->isClosed()) {
                    $this->discardHeartbeatConnection($connection);

                    return;
                }

                $this->requeueConnection($connection);

                return;
            }

            $this->discardHeartbeatConnection($connection);
        } catch (Throwable $exception) {
            $this->report('Database heartbeat failed: ' . $exception);
            $this->discardHeartbeatConnection($connection);
        }
    }

    /**
     * Discard an idle connection from the pool.
     */
    protected function discardHeartbeatConnection(PooledConnection $connection): void
    {
        try {
            if ($connection->hasOpenTransaction()) {
                $this->report('Database heartbeat found an idle connection with an open transaction.');
            }
        } catch (Throwable $exception) {
            $this->report('Database heartbeat transaction check failed: ' . $exception);
        }

        $this->destroyConnection($connection);
    }
}
