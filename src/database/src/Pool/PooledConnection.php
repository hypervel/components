<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Closure;
use Hypervel\ConnectionPool\Events\ConnectionReleasing;
use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\Coroutine as FrameworkCoroutine;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\PdoConnection;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineCreateException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Wraps a database Connection for use with Hypervel's connection pool.
 *
 * This adapter implements Hypervel's pool connection contract, allowing our
 * Laravel-ported Connection to work with Hypervel's pooling infrastructure.
 */
class PooledConnection implements PoolConnection
{
    /**
     * Maximum allowed errors before marking connection as stale.
     */
    protected const int MAX_ERROR_COUNT = 100;

    protected ?Connection $connection = null;

    protected ?ConnectionLease $lease = null;

    protected bool $driverConnectionConfigured = false;

    protected ConnectionFactory $factory;

    protected LoggerInterface $logger;

    protected float $lastUseTime = 0.0;

    protected float $lastReleaseTime = 0.0;

    protected float $createdAt = 0.0;

    protected ?float $lifetimeExpiresAt = null;

    protected bool $availableForReuse = false;

    protected bool $invalid = false;

    protected bool $connectionEstablishedEventPending = false;

    /**
     * Create a new pooled connection instance.
     */
    public function __construct(
        protected Container $container,
        protected DatabasePool $pool,
        protected array $config
    ) {
        $this->factory = $container->make('db.factory');
        $this->logger = $container->make(StdoutLoggerInterface::class);

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
        if ($this->lease !== null) {
            return $this->lease->connection;
        }

        $connection = $this->getDriverConnection();

        if (! $this->driverConnectionConfigured) {
            $this->configureConnection($connection);
            $connection->setReconnector($this->refresh(...));
            $this->driverConnectionConfigured = true;
        }

        return $connection;
    }

    /**
     * Get the internal driver resource holder for the current generation.
     *
     * @internal
     */
    public function getDriverConnection(): Connection
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
     * Create a caller-owned logical connection for this borrowed slot.
     *
     * @internal
     */
    public function newLease(): ConnectionLease
    {
        /** @var PdoConnection $connection */
        $connection = $this->getDriverConnection();
        $lease = new ConnectionLease($this->pool, $this, $this->factory, $connection);
        $this->configureConnection($lease->connection);

        return $lease;
    }

    /**
     * Attach a logical owner without retaining its state between borrowers.
     *
     * @internal
     */
    public function attachLease(ConnectionLease $lease): void
    {
        $this->lease = $lease;
        $this->connection?->unsetEventDispatcher();
        $this->connection?->unsetTransactionManager();
        $this->driverConnectionConfigured = false;
        $lease->connection->setResourceReleaser($this->forgetDriverConnection(...));
    }

    /**
     * Forget physical resources already disconnected by their logical owner.
     */
    protected function forgetDriverConnection(): void
    {
        $this->pool->forgetDateFormat();

        /** @var null|PdoConnection $connection */
        $connection = $this->connection;

        // The connection's grammar retains it until cyclic garbage collection.
        $connection?->setPdo(null)->setReadPdo(null);
        $this->connection = null;
        $this->connectionEstablishedEventPending = false;
        $this->markInvalid();
    }

    /**
     * Reconnect to the database.
     */
    public function reconnect(): bool
    {
        $this->closeDriverConnection();

        $sharedPdo = $this->pool->getSharedInMemorySqlitePdo();
        $this->connection = $this->makeConnection();

        if (! $this->connection->isReusable()) {
            $this->markInvalid();

            if ($sharedPdo !== null) {
                throw new RuntimeException(
                    'The shared in-memory SQLite database session is unknown and its sole connection cannot be replaced without discarding the database.'
                );
            }

            throw new RuntimeException('Database connection is not reusable after reconnecting.');
        }

        if (! $this->pool->usesSessionLeases()) {
            $this->configureConnection($this->connection);
            $this->connection->setReconnector($this->refresh(...));
            $this->driverConnectionConfigured = true;
        }

        $now = hrtime(true) / 1e9;
        $this->lastUseTime = $now;
        $this->stampGeneration($now);
        $this->availableForReuse = false;
        $this->markValid();
        $this->connectionEstablishedEventPending = true;

        return true;
    }

    /**
     * Configure services used by the caller-visible connection.
     */
    protected function configureConnection(Connection $connection): void
    {
        if ($this->container->bound('events')) {
            $connection->setEventDispatcher($this->container->make('events'));
        }

        if ($this->container->has('db.transactions')) {
            $connection->setTransactionManager($this->container->make('db.transactions'));
        }
    }

    /**
     * Notify listeners after the resolver has registered the connection.
     *
     * @internal
     */
    public function dispatchConnectionEstablishedEvent(): void
    {
        if (! $this->connectionEstablishedEventPending) {
            return;
        }

        // Consume before dispatch so this generation is notified only once.
        $this->connectionEstablishedEventPending = false;

        // Resolve from the container so Event::fake() also applies to reconnects.
        if ($this->container->bound('events')) {
            /** @var Dispatcher $events */
            $events = $this->container->make('events');

            if ($events->hasListeners(ConnectionEstablished::class)) {
                $connection = $this->lease->connection ?? $this->connection;
                $connection->withPinnedSession(
                    static fn () => $events->dispatch(new ConnectionEstablished($connection))
                );
            }
        }
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

            $maxIdleTime = $this->pool->getOptions()->maxIdleTime;

            if ($maxIdleTime !== null && $now > $maxIdleTime + max($this->lastReleaseTime, $this->lastUseTime)) {
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

        $maxIdleTime = $this->pool->getOptions()->maxIdleTime;

        return $maxIdleTime !== null && ($now ?? hrtime(true) / 1e9) > $maxIdleTime + $this->lastReleaseTime;
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
        try {
            if ($this->lease !== null) {
                $this->lease->connection->disconnect();
            } else {
                $this->closeDriverConnection();
            }
        } finally {
            $this->lease?->detach();
            $this->lease = null;
            $this->connection = null;
            $this->connectionEstablishedEventPending = false;
        }

        return true;
    }

    /**
     * Close the internal resource holder without settling its borrowed slot.
     */
    protected function closeDriverConnection(): void
    {
        $this->connectionEstablishedEventPending = false;
        $this->driverConnectionConfigured = false;

        if ($this->connection instanceof Connection) {
            // The pool's recorded date format may have come from this connection's grammar.
            $this->pool->forgetDateFormat();

            try {
                $this->connection->disconnect();
            } finally {
                // The pool retains a shared in-memory SQLite PDO, while the wrapper
                // must forget its connection even when transaction cleanup fails.
                $this->connection = null;
            }
        }
    }

    /**
     * Clean up the owning connection and notify listeners before detachment.
     */
    protected function prepareForRelease(): void
    {
        $connection = $this->lease->connection ?? $this->connection;

        if ($connection !== null) {
            $errorCount = $connection->getErrorCount();

            if ($this->lease === null) {
                $connection->resetForPool();
            }

            if ($errorCount > self::MAX_ERROR_COUNT) {
                $this->logger->warning('Connection has too many errors, marking as stale.');
                $this->markInvalid();
            }

            if ($connection->transactionLevel() > 0) {
                $connection->rollBack(0);
                $this->logger->error('Database transaction was not committed or rolled back before release.');
            }
        }

        $this->lastReleaseTime = hrtime(true) / 1e9;
        $events = $this->pool->getOptions()->events;

        if (in_array(ConnectionReleasing::class, $events, true)
            && $this->container->bound('events')
        ) {
            // Event::fake() can replace the dispatcher after this connection was created.
            /** @var Dispatcher $dispatcher */
            $dispatcher = $this->container->make('events');

            if ($dispatcher->hasListeners(ConnectionReleasing::class)) {
                $dispatcher->dispatch(new ConnectionReleasing($this));
            }
        }
    }

    /**
     * Record the physical holder's grammar format without borrowing a session.
     *
     * A fresh logical lease is built from the same factory configuration as
     * the holder, so owner-specific grammar changes must not reach the pool.
     * Without leases the holder is the caller-visible connection itself.
     *
     * @internal
     */
    public function recordDateFormat(): void
    {
        if ($this->connection instanceof Connection) {
            $this->pool->recordDateFormat($this->connection);
        }
    }

    /**
     * Release the connection back to the pool.
     */
    public function release(): void
    {
        $cancellationFailure = null;
        $ordinaryFailure = null;

        try {
            if ($this->lease !== null) {
                $this->lease->connection->withPinnedSession($this->prepareForRelease(...));
            } else {
                $this->prepareForRelease();
            }

            // Recorded after the listeners, which may still change a shared connection's grammar.
            $this->recordDateFormat();
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
        } finally {
            $this->lease?->detach();
            $this->lease = null;
        }

        $discard = false;

        try {
            // Callbacks may leave a raw transaction outside the framework counters.
            if ($this->connection?->hasPhysicalTransaction()) {
                $discard = true;
                $this->logger->error('Database transaction was not committed or rolled back before release.');
            }
        } catch (CanceledException $transactionCancellation) {
            $discard = true;
            $cancellationFailure ??= $transactionCancellation;
        } catch (Throwable $exception) {
            $discard = true;
            $ordinaryFailure ??= $exception;
        }

        try {
            if (! $discard
                && $cancellationFailure === null
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

        $this->availableForReuse = ! $discard;

        try {
            if ($discard) {
                $this->pool->discard($this);
            } else {
                $this->pool->release($this);
            }
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
        if ($this->lifetimeExpiresAt === null) {
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
        $this->lifetimeExpiresAt = $this->pool->getOptions()->jitteredLifetimeDeadline($now);
    }

    /**
     * Create driver resources for a new physical connection generation.
     */
    protected function makeConnection(): Connection
    {
        $sharedPdo = $this->pool->getSharedInMemorySqlitePdo();

        if ($sharedPdo !== null) {
            // Creating a fresh PDO would discard the shared in-memory database.
            return $this->factory->makeSqliteFromSharedPdo(
                $sharedPdo,
                $this->config,
                $this->config['name'] ?? null
            );
        }

        $config = $this->config;
        $name = $config['name'] ?? null;

        if (($config[Connection::READ_WRITE_TYPE_CONFIG_KEY] ?? null) === ConnectionName::READ
            && $this->factory->hasReadConfig($config)
            && $this->factory->getExtension($config, $name) === null
        ) {
            $config = $this->factory->configForRead($config);
        }

        return $this->factory->make($config, $name);
    }

    /**
     * Refresh the database connection resources.
     */
    protected function refresh(Connection $connection): void
    {
        try {
            $connection->refreshFrom($this->makeConnection());
        } catch (Throwable $exception) {
            $this->markInvalid();

            throw $exception;
        }

        // The resolver already owns a refreshed connection, so notify immediately.
        $this->connectionEstablishedEventPending = true;
        $this->dispatchConnectionEstablishedEvent();

        $this->stampGeneration(hrtime(true) / 1e9);
    }
}
