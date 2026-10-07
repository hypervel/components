<?php

declare(strict_types=1);

namespace Hypervel\Database\Pool;

use Closure;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\PdoConnection;
use PDO;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Keep a caller's logical connection stable while its physical sessions are pooled.
 */
class ConnectionLease implements NonCopyableContext
{
    public readonly PdoConnection $connection;

    protected ?PooledConnection $pooledConnection;

    /** @var Closure(): PDO */
    protected readonly Closure $pdoResolver;

    /** @var null|Closure(): PDO */
    protected readonly ?Closure $readPdoResolver;

    /**
     * Create a logical connection from its initially borrowed physical session.
     */
    public function __construct(
        protected readonly DatabasePool $pool,
        PooledConnection $pooledConnection,
        ConnectionFactory $factory,
        PdoConnection $physicalConnection,
    ) {
        $this->pooledConnection = $pooledConnection;
        $this->pdoResolver = fn (): PDO => $this->resolvePdo();
        $this->readPdoResolver = $physicalConnection->getRawReadPdo() !== null
            ? fn (): PDO => $this->resolvePdo(read: true)
            : null;
        $this->connection = $factory->makeWithPdoResolvers(
            $physicalConnection->getConfig(),
            $this->pdoResolver,
            $this->readPdoResolver,
        );
        $this->connection->attachPdoResources($physicalConnection);
        $this->connection->setReconnector($this->reconnect(...));
        $pooledConnection->attachLease($this);
    }

    /**
     * Resolve a physical handle and publish its generation to the logical owner.
     */
    protected function resolvePdo(bool $read = false): PDO
    {
        if ($this->pooledConnection === null) {
            /** @var PooledConnection $pooledConnection */
            $pooledConnection = $this->pool->borrow();
            $this->pooledConnection = $pooledConnection;
            $pooledConnection->attachLease($this);
        }

        $pooledConnection = $this->pooledConnection;

        try {
            /** @var PdoConnection $physicalConnection */
            $physicalConnection = $pooledConnection->getDriverConnection();
            $pdo = $physicalConnection->resolveRawPdo($read);

            // A PDO-first extension may resolve its constructor argument eagerly.
            if (isset($this->connection)) { // @phpstan-ignore isset.initializedProperty (PDO resolvers may run during construction.)
                $this->connection->attachPdoResources($physicalConnection);
                $pooledConnection->dispatchConnectionEstablishedEvent();
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
     * Replace the held physical session and reconnect the logical owner.
     */
    public function reconnect(): PdoConnection
    {
        $this->connection->disconnect();

        if ($this->pooledConnection !== null) {
            $this->pooledConnection->reconnect();
            $this->pooledConnection->attachLease($this);
        }

        $this->connection->setPdo($this->pdoResolver)->setReadPdo($this->readPdoResolver);
        $this->connection->getPdo();

        return $this->connection;
    }

    /**
     * Release the held session when no operation requires it.
     */
    public function releaseIfIdle(): void
    {
        if (! $this->connection->hasPinnedSession()) {
            $this->release();
        }
    }

    /**
     * Settle the currently held physical session.
     */
    public function release(): void
    {
        $this->pooledConnection?->release();
    }

    /**
     * Discard the currently held physical session.
     */
    public function discard(): void
    {
        $this->pooledConnection?->discard();
    }

    /**
     * Remove references to a session before its slot becomes available again.
     */
    public function detach(): void
    {
        $this->pooledConnection = null;
        $this->connection->detachPdoResources($this->pdoResolver, $this->readPdoResolver);
    }

    /**
     * Discard a failed acquisition without replacing its exception.
     */
    protected function discardAfterFailure(Throwable $exception): void
    {
        try {
            $this->discard();
        } catch (CanceledException $cancellation) {
            if (! $exception instanceof CanceledException) {
                throw $cancellation;
            }
        } catch (Throwable) {
            // Preserve the acquisition or connection-listener failure.
        }
    }
}
