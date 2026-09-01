<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface as PoolConnectionInterface;
use Hypervel\Coroutine\Coroutine as FrameworkCoroutine;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Hypervel\Pool\Events\ReleaseConnection;
use Hypervel\Pool\PoolOption;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

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
    protected const int MAX_ERROR_COUNT = 100;

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

    /**
     * Create a new pooled connection instance.
     */
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
            // Normal path: factory creates a fresh connection with new driver resources.
            $this->connection = $this->factory->make($this->config, $this->config['name'] ?? null);
        }

        if (! $this->connection->isReusable()) {
            $this->markInvalid();

            if ($sharedPdo !== null) {
                throw new RuntimeException(
                    'The shared in-memory SQLite database session is unknown and its sole connection cannot be replaced without discarding the database.'
                );
            }

            throw new RuntimeException('Database connection is not reusable after reconnecting.');
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
     * Ping the underlying database connection.
     */
    public function ping(float $timeout): bool
    {
        if ($this->invalid || ! $this->connection instanceof Connection) {
            return false;
        }

        $result = new Channel(1);
        $connection = $this->connection;
        $started = null;
        $callable = static function () use ($connection, $result): void {
            try {
                $healthy = $connection->ping();
            } catch (CanceledException) {
                return;
            } catch (Throwable) {
                $healthy = false;
            }

            $result->push($healthy, 0.0);
        };
        $wrapper = static function (Closure $run) use (&$started): void {
            $started = Coroutine::id();
            $run();
        };

        try {
            FrameworkCoroutine::createOwned($callable, $wrapper);
        } catch (CanceledException $exception) {
            $this->cancelHeartbeatCoroutine($started);

            throw $exception;
        } catch (CoroutineCreateException) {
            return false;
        }

        try {
            $healthy = $result->pop($timeout);
        } catch (CanceledException $exception) {
            $this->cancelHeartbeatCoroutine($started);

            throw $exception;
        }

        if ($healthy === false && $result->isCanceled()) {
            $exception = new CanceledException('Waiting for a database heartbeat was canceled.');
            $this->cancelHeartbeatCoroutine($started);

            throw $exception;
        }

        if ($healthy !== true) {
            $this->cancelHeartbeatCoroutine($started);

            return false;
        }

        $this->lastUseTime = hrtime(true) / 1e9;

        return true;
    }

    /**
     * Cancel a live heartbeat coroutine.
     */
    private function cancelHeartbeatCoroutine(?int $coroutineId): void
    {
        if (is_int($coroutineId) && Coroutine::exists($coroutineId)) {
            Coroutine::cancelById($coroutineId, throwException: true);
        }
    }

    /**
     * Close the database connection.
     */
    public function close(): bool
    {
        if ($this->connection instanceof Connection) {
            try {
                $this->connection->disconnect();
            } finally {
                // The pool retains a shared in-memory SQLite PDO, while the wrapper
                // must forget its connection even when transaction cleanup fails.
                $this->connection = null;
            }
        }

        return true;
    }

    /**
     * Release the connection back to the pool.
     */
    public function release(): void
    {
        $cancellationFailure = null;
        $ordinaryFailure = null;

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
        } catch (CanceledException $cancellation) {
            $cancellationFailure = $cancellation;
            $this->markInvalid();
        } catch (Throwable $exception) {
            $this->markInvalid();

            try {
                $this->logger->error('Release connection failed: ' . $exception);
            } catch (CanceledException $loggingCancellation) {
                $cancellationFailure = $loggingCancellation;
            } catch (Throwable $loggingException) {
                $ordinaryFailure = $loggingException;
            }
        }

        try {
            if ($cancellationFailure === null
                && $this->connection !== null
                && ! $this->connection->isReusable()
            ) {
                $this->markInvalid();
                $this->logger->warning('Database connection is not reusable, marking it as stale.');
            }
        } catch (CanceledException $stateCancellation) {
            $cancellationFailure = $stateCancellation;
        } catch (Throwable $exception) {
            $ordinaryFailure ??= $exception;
        }

        $this->availableForReuse = true;

        try {
            $this->pool->release($this);
        } catch (CanceledException $releaseCancellation) {
            $cancellationFailure ??= $releaseCancellation;
        } catch (Throwable $exception) {
            $ordinaryFailure ??= $exception;
        }

        if ($cancellationFailure !== null) {
            throw $cancellationFailure;
        }

        if ($ordinaryFailure !== null) {
            throw $ordinaryFailure;
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
     * Refresh the database connection resources.
     */
    protected function refresh(Connection $connection): void
    {
        $sharedPdo = $this->pool->getSharedInMemorySqlitePdo();

        try {
            if ($sharedPdo !== null) {
                // For shared in-memory SQLite, rebind to the same PDO.
                // Creating a fresh PDO would give us a new empty database.
                $fresh = $this->factory->makeSqliteFromSharedPdo(
                    $sharedPdo,
                    $this->config,
                    $this->config['name'] ?? null
                );
            } else {
                $fresh = $this->factory->make($this->config, $this->config['name'] ?? null);
            }

            $connection->refreshFrom($fresh);
        } catch (Throwable $exception) {
            $this->markInvalid();

            throw $exception;
        }

        if ($sharedPdo === null) {
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
