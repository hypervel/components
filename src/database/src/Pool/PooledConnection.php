<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface as PoolConnectionInterface;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Pool\Events\ReleaseConnection;
use Hypervel\Pool\PoolOption;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\go;

/**
 * Wraps a database Connection for use with Hypervel's connection pool.
 *
 * This adapter implements Hypervel's pool ConnectionInterface, allowing our
 * Laravel-ported Connection to work with Hypervel's pooling infrastructure.
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

    protected float $createdAt = 0.0;

    protected float $lifetimeExpiresAt = 0.0;

    protected bool $availableForReuse = false;

    protected bool $invalid = false;

    protected ?Dispatcher $dispatcher = null;

    public function __construct(
        protected Container $container,
        protected DbPool $pool,
        protected array $config
    ) {
        $this->factory = $container->make('db.factory');
        $this->logger = $container->make(StdoutLoggerInterface::class);

        if ($container->bound('events')) {
            $this->dispatcher = $container->make('events');
        }

        $this->reconnect();
    }

    /**
     * Get the underlying database connection.
     */
    public function getConnection(): Connection
    {
        return $this->getActiveConnection();
    }

    /**
     * Get the active connection, reconnecting if necessary.
     */
    public function getActiveConnection(): Connection
    {
        if ($this->check()) {
            $this->availableForReuse = false;

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
        if ($this->container->bound('events')) {
            $this->connection->setEventDispatcher($this->container->make('events'));
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

        // Fetch dispatcher from container (not $this->dispatcher) so Event::fake() works.
        // Reconnection can be triggered after fake swaps the container binding.
        if ($this->container->bound('events')) {
            $this->container->make('events')->dispatch(
                new ConnectionEstablished($this->connection)
            );
        }

        $now = hrtime(true) / 1e9;
        $this->lastUseTime = $now;
        $this->stampGeneration($now);
        $this->availableForReuse = false;
        $this->markValid();

        return true;
    }

    /**
     * Check if the connection is still valid.
     */
    public function check(): bool
    {
        if ($this->invalid) {
            return false;
        }

        if ($this->connection === null) {
            return false;
        }

        $now = hrtime(true) / 1e9;

        if ($this->availableForReuse) {
            // Time-based recycling is a reuse rule; it must not replace a connection
            // while the borrowed wrapper may still hold transaction state.
            if ($this->isLifetimeExpired($now)) {
                return false;
            }

            $maxIdleTime = $this->pool->getOption()->getMaxIdleTime();

            if ($now > $maxIdleTime + max($this->lastReleaseTime, $this->lastUseTime)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if this connection has been idle long enough to be evicted.
     */
    public function isIdleExpired(?float $now = null): bool
    {
        if ($this->lastReleaseTime === 0.0) {
            return false;
        }

        return ($now ?? hrtime(true) / 1e9) > $this->pool->getOption()->getMaxIdleTime() + $this->lastReleaseTime;
    }

    /**
     * Ping already-open PDO connections.
     */
    public function ping(float $timeout): bool
    {
        if ($this->invalid || ! $this->connection instanceof Connection) {
            return false;
        }

        // Known session configuration is memoized by physical PDO across clean
        // releases. Pool maintenance must remain session-state-neutral.
        $pdos = $this->getOpenPdos();

        if ($pdos === []) {
            return true;
        }

        $result = new Channel(1);

        try {
            $started = go(static function () use ($pdos, $result): void {
                try {
                    $result->push(self::pingPdos($pdos), 0.0);
                } catch (CanceledException) {
                }
            });
        } catch (CoroutineCreateException) {
            return false;
        }

        if ($result->pop($timeout) !== true) {
            Coroutine::cancelById($started, throwException: true);

            return false;
        }

        $this->lastUseTime = hrtime(true) / 1e9;

        return true;
    }

    /**
     * Close the database connection.
     */
    public function close(): bool
    {
        if ($this->connection instanceof Connection) {
            // This drops only the wrapper's reference. The pool retains a shared
            // in-memory SQLite PDO, while wrapper-owned transactions still roll back.
            $this->connection->disconnect();
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
                $errorCount = $this->connection->getErrorCount();

                // Reset wrapper state before another coroutine borrows it.
                $this->connection->resetForPool();

                // Check error count and mark as stale if too high
                if ($errorCount > self::MAX_ERROR_COUNT) {
                    $this->logger->warning('Connection has too many errors, marking as stale.');
                    $this->markInvalid();
                }

                // Roll back any uncommitted transactions (including nested savepoints)
                if ($this->connection->transactionLevel() > 0) {
                    $this->connection->rollBack(0);
                    $this->logger->error('Database transaction was not committed or rolled back before release.');
                }
            }

            $this->lastReleaseTime = hrtime(true) / 1e9;

            // Dispatch release event if configured
            $events = $this->pool->getOption()->getEvents();
            if (in_array(ReleaseConnection::class, $events, true)) {
                $this->dispatcher?->dispatch(new ReleaseConnection($this));
            }
        } catch (Throwable $exception) {
            $this->logger->error('Release connection failed: ' . $exception);
            // Mark as stale so it will be recreated
            $this->markInvalid();
        } finally {
            if ($this->connection?->hasUnknownSessionState()) {
                $this->logger->warning('Database session state is unknown, marking connection as stale.');
                $this->markInvalid();
            }

            $this->availableForReuse = true;
            $this->pool->release($this);
        }
    }

    /**
     * Discard the connection from its pool.
     */
    public function discard(): void
    {
        $this->pool->discard($this);
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
     * Get the connection generation creation time.
     */
    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    /**
     * Determine if this connection generation has reached its maximum lifetime.
     */
    public function isLifetimeExpired(?float $now = null): bool
    {
        if ($this->lifetimeExpiresAt <= 0) {
            return false;
        }

        return ($now ?? hrtime(true) / 1e9) >= $this->lifetimeExpiresAt;
    }

    /**
     * Determine if the underlying connection has an open transaction.
     */
    public function hasOpenTransaction(): bool
    {
        return $this->connection instanceof Connection
            && $this->connection->transactionLevel() > 0;
    }

    /**
     * Mark the connection as invalid.
     */
    protected function markInvalid(): void
    {
        $this->invalid = true;
    }

    /**
     * Mark the connection as valid.
     */
    protected function markValid(): void
    {
        $this->invalid = false;
    }

    /**
     * Get already-open PDO instances.
     *
     * @return PDO[]
     */
    protected function getOpenPdos(): array
    {
        if (! $this->connection instanceof Connection) {
            return [];
        }

        $writePdo = $this->connection->getRawPdo();
        $readPdo = $this->connection->getRawReadPdo();
        $pdos = [];

        if ($writePdo instanceof PDO) {
            $pdos[] = $writePdo;
        }

        if ($readPdo instanceof PDO && $readPdo !== $writePdo) {
            $pdos[] = $readPdo;
        }

        return $pdos;
    }

    /**
     * Ping PDO instances.
     *
     * @param PDO[] $pdos
     */
    protected static function pingPdos(array $pdos): bool
    {
        try {
            foreach ($pdos as $pdo) {
                $statement = $pdo->query('SELECT 1');

                if ($statement === false) {
                    return false;
                }

                $statement->closeCursor();
            }

            return true;
        } catch (Throwable $exception) {
            if ($exception instanceof CanceledException) {
                throw $exception;
            }

            return false;
        }
    }

    /**
     * Stamp the current connection generation.
     */
    private function stampGeneration(float $now): void
    {
        $this->createdAt = $now;
        $this->lifetimeExpiresAt = PoolOption::jitteredLifetimeDeadline(
            $now,
            $this->pool->getOption()->getMaxLifetime()
        );
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
            try {
                $fresh = $this->factory->make($this->config, $this->config['name'] ?? null);
                $writePdo = $fresh->getPdo();
                $readPdo = $fresh->getReadPdo();

                // Keep the current generation intact until both replacement handles
                // are ready so a failed refresh cannot leave a partial connection.
                $connection->disconnect();
                $connection->setPdo($writePdo);
                $connection->setReadPdo($readPdo);
            } catch (Throwable $exception) {
                $this->markInvalid();

                throw $exception;
            }

            $this->logger->warning('Database connection refreshed.');
        }

        // Fetch dispatcher from container (not $this->dispatcher) so Event::fake() works.
        // Reconnection can be triggered after fake swaps the container binding.
        if ($this->container->bound('events')) {
            $this->container->make('events')->dispatch(
                new ConnectionEstablished($connection)
            );
        }

        $this->stampGeneration(hrtime(true) / 1e9);
    }
}
