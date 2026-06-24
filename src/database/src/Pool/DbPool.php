<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Pool\Frequency;
use Hypervel\Pool\Pool;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;
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

    protected int $heartbeatGeneration = 0;

    /**
     * Shared PDO for in-memory SQLite. All pool slots must share the same PDO
     * instance, otherwise each would get its own empty database.
     */
    protected ?PDO $sharedInMemorySqlitePdo = null;

    public function __construct(Container $container, string $name)
    {
        $configService = $container->make('config');
        $key = sprintf('database.connections.%s', $name);

        if (! $configService->has($key)) {
            throw new InvalidArgumentException(sprintf('Database connection [%s] not configured.', $name));
        }

        // Include the connection name in the config
        $this->config = $configService->get($key);
        $this->config['name'] = $name;

        // Extract pool options
        $poolOptions = Arr::get($this->config, 'pool', []);

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
     * Flush all connections and clear the shared in-memory SQLite PDO.
     */
    public function flushAll(): void
    {
        $this->clearHeartbeat();

        parent::flushAll();
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
                if ($isClosing) {
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
        ++$this->heartbeatGeneration;

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

        for ($i = 0; $i < $connectionsToInspect; ++$i) {
            $connection = $this->channel->pop(0.001);

            if (! $connection instanceof PooledConnection) {
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

            if ($connection->isIdleExpired($now) && $this->currentConnections > $this->option->getMinConnections()) {
                $this->discardHeartbeatConnection($connection);

                return;
            }

            $heartbeatGeneration = $this->heartbeatGeneration;

            if ($connection->ping($this->option->getHeartbeatTimeout())) {
                if ($heartbeatGeneration === $this->heartbeatGeneration) {
                    $this->release($connection);
                } else {
                    $this->discardHeartbeatConnection($connection);
                }

                return;
            }

            $this->discardHeartbeatConnection($connection);
        } catch (Throwable $exception) {
            $this->logHeartbeatError('Database heartbeat failed: ' . $exception);
            $this->discardHeartbeatConnection($connection);
        }
    }

    /**
     * Discard an idle connection from the pool.
     */
    protected function discardHeartbeatConnection(PooledConnection $connection): void
    {
        --$this->currentConnections;

        try {
            if ($connection->hasOpenTransaction()) {
                $this->logHeartbeatError('Database heartbeat found an idle connection with an open transaction.');
            }

            $connection->close();
        } catch (Throwable $exception) {
            $this->logHeartbeatError('Database heartbeat close failed: ' . $exception);
        }
    }

    /**
     * Log a heartbeat error without breaking pool cleanup.
     */
    protected function logHeartbeatError(string $message): void
    {
        try {
            $this->getLogger()?->error($message);
        } catch (Throwable) {
        }
    }

    /**
     * Get the logger instance if available.
     */
    protected function getLogger(): ?LoggerInterface
    {
        if (! $this->container->has(StdoutLoggerInterface::class)) {
            return null;
        }

        return $this->container->make(StdoutLoggerInterface::class);
    }
}
