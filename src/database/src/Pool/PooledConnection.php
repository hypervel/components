<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface as PoolConnectionInterface;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Pool\Event\ReleaseConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Wraps a database Connection for use with Hyperf's connection pool.
 *
 * This adapter implements Hyperf's pool ConnectionInterface, allowing our
 * Laravel-ported Connection to work with Hyperf's pooling infrastructure.
 */
class PooledConnection implements PoolConnectionInterface
{
    /**
     * Maximum allowed errors before marking connection as stale.
     */
    protected const MAX_ERROR_COUNT = 100;

    protected ?Connection $connection = null;

    protected ConnectionFactory $factory;

    protected LoggerInterface $logger;

    protected float $lastUseTime = 0.0;

    protected float $lastReleaseTime = 0.0;

    protected ?Dispatcher $dispatcher = null;

    public function __construct(
        protected Container $container,
        protected DbPool $pool,
        protected array $config
    ) {
        $this->factory = $container->make(ConnectionFactory::class);
        $this->logger = $container->make(StdoutLoggerInterface::class);

        if ($container->has(Dispatcher::class)) {
            $this->dispatcher = $container->make(Dispatcher::class);
        }

        $this->reconnect();
    }

    /**
     * Get the underlying database connection.
     */
    public function getConnection(): Connection
    {
        try {
            return $this->getActiveConnection();
        } catch (Throwable $exception) {
            $this->logger->warning('Get connection failed, try again. ' . $exception);
            return $this->getActiveConnection();
        }
    }

    /**
     * Get the active connection, reconnecting if necessary.
     */
    public function getActiveConnection(): Connection
    {
        if ($this->check()) {
            return $this->connection;
        }

        if (! $this->reconnect()) {
            throw new RuntimeException('Database connection reconnect failed.');
        }

        return $this->connection;
    }

    /**
     * Reconnect to the database.
     */
    public function reconnect(): bool
    {
        $this->close();

        $sharedPdo = $this->pool->getSharedInMemorySqlitePdo();

        if ($sharedPdo !== null) {
            // In-memory SQLite: use shared PDO so all pool slots see same data
            $this->connection = $this->factory->makeSqliteFromSharedPdo(
                $sharedPdo,
                $this->config,
                $this->config['name'] ?? null
            );
        } else {
            // Normal path: factory creates fresh connection with new PDO
            $this->connection = $this->factory->make($this->config, $this->config['name'] ?? null);
        }

        // Configure event dispatcher for query events
        if ($this->container->has(Dispatcher::class)) {
            $this->connection->setEventDispatcher($this->container->make(Dispatcher::class));
        }

        // Configure transaction manager for after-commit callbacks
        if ($this->container->has('db.transactions')) {
            $this->connection->setTransactionManager($this->container->make('db.transactions'));
        }

        // Set up reconnector for the connection
        $this->connection->setReconnector(function ($connection) {
            $this->logger->warning('Database connection refreshing.');
            $this->refresh($connection);
        });

        // Dispatch connection established event
        if ($this->container->has(Dispatcher::class)) {
            $this->container->make(Dispatcher::class)->dispatch(
                new ConnectionEstablished($this->connection)
            );
        }

        $this->lastUseTime = microtime(true);

        return true;
    }

    /**
     * Check if the connection is still valid.
     */
    public function check(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        $maxIdleTime = $this->pool->getOption()->getMaxIdleTime();
        $now = microtime(true);

        if ($now > $maxIdleTime + $this->lastUseTime) {
            return false;
        }

        $this->lastUseTime = $now;

        return true;
    }

    /**
     * Close the database connection.
     */
    public function close(): bool
    {
        if ($this->connection instanceof Connection) {
            // Only disconnect if NOT using shared in-memory SQLite PDO.
            // Shared PDO is owned by the pool, not individual connections.
            if ($this->pool->getSharedInMemorySqlitePdo() === null) {
                $this->connection->disconnect();
            }
        }

        $this->connection = null;

        return true;
    }

    /**
     * Release the connection back to the pool.
     */
    public function release(): void
    {
        try {
            if ($this->connection instanceof Connection) {
                // Reset all per-request state to prevent leaks between coroutines
                $this->connection->resetForPool();

                // Check error count and mark as stale if too high
                if ($this->connection->getErrorCount() > self::MAX_ERROR_COUNT) {
                    $this->logger->warning('Connection has too many errors, marking as stale.');
                    $this->lastUseTime = 0.0;
                }

                // Roll back any uncommitted transactions (including nested savepoints)
                if ($this->connection->transactionLevel() > 0) {
                    $this->connection->rollBack(0);
                    $this->logger->error('Database transaction was not committed or rolled back before release.');
                }
            }

            $this->lastReleaseTime = microtime(true);

            // Dispatch release event if configured
            $events = $this->pool->getOption()->getEvents();
            if (in_array(ReleaseConnection::class, $events, true)) {
                $this->dispatcher?->dispatch(new ReleaseConnection($this));
            }
        } catch (Throwable $exception) {
            $this->logger->error('Release connection failed: ' . $exception);
            // Mark as stale so it will be recreated
            $this->lastUseTime = 0.0;
        } finally {
            $this->pool->release($this);
        }
    }

    /**
     * Get the last use time.
     */
    public function getLastUseTime(): float
    {
        return $this->lastUseTime;
    }

    /**
     * Get the last release time.
     */
    public function getLastReleaseTime(): float
    {
        return $this->lastReleaseTime;
    }

    /**
     * Refresh the PDO connections.
     */
    protected function refresh(Connection $connection): void
    {
        $sharedPdo = $this->pool->getSharedInMemorySqlitePdo();

        if ($sharedPdo !== null) {
            // For shared in-memory SQLite, rebind to the same PDO.
            // Creating a fresh PDO would give us a new empty database.
            $connection->setPdo($sharedPdo);
            $connection->setReadPdo($sharedPdo);
        } else {
            // Normal refresh path for other drivers
            $fresh = $this->factory->make($this->config, $this->config['name'] ?? null);

            $connection->disconnect();
            $connection->setPdo($fresh->getPdo());
            $connection->setReadPdo($fresh->getReadPdo());

            $this->logger->warning('Database connection refreshed.');
        }

        // Dispatch connection established event (fetching from container to respect fakes)
        if ($this->container->has(Dispatcher::class)) {
            $this->container->make(Dispatcher::class)->dispatch(
                new ConnectionEstablished($connection)
            );
        }
    }
}
