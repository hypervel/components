<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Closure;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Database\Connection;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\LeasableConnection;
use Hypervel\Database\PdoConnection;
use LogicException;
use PDO;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Keep a caller's logical connection stable while its physical sessions are pooled.
 *
 * PDO connections receive the borrowed handles directly. Drivers implementing
 * LeasableConnection build their own logical connection and ask the lease for
 * the physical connection whenever an operation needs it.
 */
class ConnectionLease implements NonCopyableContext
{
    public readonly Connection $connection;

    protected ?PooledConnection $pooledConnection;

    protected bool $ended = false;

    /** @var null|Closure(): PDO */
    protected readonly ?Closure $pdoResolver;

    /** @var null|Closure(): PDO */
    protected readonly ?Closure $readPdoResolver;

    /**
     * Create a logical connection from its initially borrowed physical session.
     */
    public function __construct(
        protected readonly DatabasePool $pool,
        PooledConnection $pooledConnection,
        ConnectionFactory $factory,
        Connection $physicalConnection,
    ) {
        $this->pooledConnection = $pooledConnection;

        if ($physicalConnection instanceof LeasableConnection) {
            $this->pdoResolver = null;
            $this->readPdoResolver = null;
            $this->connection = $physicalConnection->newLeasedConnection($this->resolvePhysicalConnection(...));
        } else {
            /** @var PdoConnection $physicalConnection */
            $this->pdoResolver = fn (): PDO => $this->resolvePdo();
            $this->readPdoResolver = $physicalConnection->getRawReadPdo() !== null
                ? fn (): PDO => $this->resolvePdo(read: true)
                : null;
            $connection = $factory->makeWithPdoResolvers(
                $physicalConnection->getConfig(),
                $this->pdoResolver,
                $this->readPdoResolver,
            );
            $connection->attachPdoResources($physicalConnection);
            $this->connection = $connection;
        }

        $this->connection->setReconnector($this->reconnect(...));
        $pooledConnection->attachLease($this);
    }

    /**
     * Get the held pooled connection, borrowing one when the session was returned early.
     */
    protected function heldPooledConnection(): PooledConnection
    {
        if ($this->pooledConnection === null) {
            if ($this->ended) {
                throw new LogicException('This database connection is no longer available because the coroutine or task that resolved it has finished or failed to set it up. Resolve the connection where you use it.');
            }

            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $this->pool->borrow();
            $this->pooledConnection = $pooledConnection;
            $pooledConnection->attachLease($this);
        }

        return $this->pooledConnection;
    }

    /**
     * Resolve a physical handle and publish its generation to the logical owner.
     */
    protected function resolvePdo(bool $read = false): PDO
    {
        $pooledConnection = $this->heldPooledConnection();

        try {
            /** @var PdoConnection $physicalConnection */
            $physicalConnection = $pooledConnection->getDriverConnection();
            $pdo = $physicalConnection->resolveRawPdo($read);

            // A PDO-first extension may resolve its constructor argument eagerly.
            if (isset($this->connection)) { // @phpstan-ignore isset.initializedProperty (PDO resolvers may run during construction.)
                /** @var PdoConnection $connection */
                $connection = $this->connection;
                $connection->attachPdoResources($physicalConnection);
                $pooledConnection->dispatchConnectionEstablishedEvent();

                // A listener may have reconnected and replaced the published handle.
                $pdo = $connection->resolveRawPdo($read);
            }

            return $pdo;
        } catch (Throwable $exception) {
            if (isset($this->connection)) { // @phpstan-ignore isset.initializedProperty (PDO resolvers may run during construction.)
                $this->discardAfterFailure($exception);
            }

            throw $exception;
        }
    }

    /**
     * Resolve a driver's physical connection and publish its generation to the logical owner.
     */
    protected function resolvePhysicalConnection(): Connection&LeasableConnection
    {
        $pooledConnection = $this->heldPooledConnection();

        try {
            $pooledConnection->getDriverConnection();
            $pooledConnection->dispatchConnectionEstablishedEvent();

            // A listener may have reconnected and replaced the held connection.
            /** @var Connection&LeasableConnection $physicalConnection */
            $physicalConnection = $pooledConnection->getDriverConnection();

            return $physicalConnection;
        } catch (Throwable $exception) {
            $this->discardAfterFailure($exception);

            throw $exception;
        }
    }

    /**
     * Replace the held physical session and reconnect the logical owner.
     */
    public function reconnect(): Connection
    {
        $this->connection->disconnect();
        $this->pooledConnection?->reconnect();

        if ($this->pdoResolver !== null) {
            /** @var PdoConnection $connection */
            $connection = $this->connection;
            $connection->setPdo($this->pdoResolver)->setReadPdo($this->readPdoResolver);
            $connection->getPdo();
        }

        return $this->connection;
    }

    /**
     * Release the held session when no operation requires it.
     */
    public function releaseIfIdle(): void
    {
        if ($this->pooledConnection === null
            || $this->connection->hasPinnedSession()
            || ($this->connection->hasModifiedRecords() && $this->pool->retainsSessionsAfterWrites())
        ) {
            return;
        }

        $this->pooledConnection->release();
    }

    /**
     * End logical ownership and release the currently held physical session.
     */
    public function release(): void
    {
        $this->ended = true;
        $this->pooledConnection?->release();
    }

    /**
     * End logical ownership and discard the currently held physical session.
     */
    public function discard(): void
    {
        $this->ended = true;
        $this->pooledConnection?->discard();
    }

    /**
     * Determine whether logical ownership has ended.
     */
    public function hasEnded(): bool
    {
        return $this->ended;
    }

    /**
     * Remove references to a session before its slot becomes available again.
     */
    public function detach(): void
    {
        $this->pooledConnection = null;
        $this->connection->detachLeasedResources();

        if ($this->pdoResolver !== null) {
            /** @var PdoConnection $connection */
            $connection = $this->connection;
            $connection->detachPdoResources($this->pdoResolver, $this->readPdoResolver);
        }
    }

    /**
     * Discard a failed acquisition without replacing its exception.
     */
    protected function discardAfterFailure(Throwable $exception): void
    {
        try {
            $this->pooledConnection?->discard();
        } catch (CanceledException $cancellation) {
            if (! $exception instanceof CanceledException) {
                throw $cancellation;
            }
        } catch (Throwable) {
            // Preserve the acquisition or connection-listener failure.
        }
    }
}
