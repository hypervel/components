<?php

declare(strict_types=1);

namespace Hypervel\ConnectionPool;

use Hypervel\ConnectionPool\Exceptions\PoolClosedException;
use Hypervel\ConnectionPool\Exceptions\PoolExhaustedException;
use Hypervel\Contracts\ConnectionPool\Connection as ConnectionContract;
use Hypervel\Contracts\ConnectionPool\ConnectionPool as ConnectionPoolContract;
use Hypervel\Contracts\ConnectionPool\UsageTracker;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coroutine\PoolChannel;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Manage reusable connections with explicit ownership and terminal teardown.
 */
abstract class ConnectionPool implements ConnectionPoolContract
{
    /** @var PoolChannel<ConnectionContract> */
    protected PoolChannel $channel;

    protected PoolOptions $options;

    /** @var array<int, true> */
    protected array $managedConnections = [];

    /** @var array<int, true> */
    protected array $borrowedConnections = [];

    protected int $creatingCount = 0;

    protected bool $closed = false;

    protected ?UsageTracker $usageTracker = null;

    protected bool $usageTrackerInitialized = false;

    protected ?IdleConnectionMonitor $idleMonitor = null;

    /**
     * Create a connection pool.
     */
    public function __construct(
        protected Container $container,
        protected string $name,
        array $config = []
    ) {
        $this->options = PoolOptions::fromArray($config);
        $this->channel = new PoolChannel($this->options->maxConnections);
    }

    /**
     * Get the pool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Enable background maintenance after pool initialization succeeds.
     */
    public function start(): void
    {
        if ($this->closed || $this->options->idleCheckInterval === null) {
            return;
        }

        $this->idleMonitor ??= new IdleConnectionMonitor($this, $this->options->idleCheckInterval);

        if ($this->getManagedCount() > 0) {
            $this->idleMonitor->start();
        }
    }

    /**
     * Borrow a connection from the pool.
     */
    public function borrow(): ConnectionContract
    {
        if ($this->closed) {
            throw new PoolClosedException('Cannot borrow from a closed connection pool.');
        }

        $deadline = $this->deadline($this->options->waitTimeout);
        $connection = $this->getConnection($deadline);
        $this->borrowedConnections[spl_object_id($connection)] = true;

        try {
            if (! $this->usageTrackerInitialized) {
                $this->usageTracker = $this->createUsageTracker();
                $this->usageTrackerInitialized = true;
            }

            $this->usageTracker?->recordBorrow();

            if ($this->usageTracker?->shouldTrimExcessIdle()) {
                $this->trimExcessIdle();
            }

            $this->idleMonitor?->start();
        } catch (CanceledException $cancellation) {
            try {
                $this->discard($connection);
            } catch (CanceledException) {
            } catch (Throwable $exception) {
                $this->report($exception);
            }

            throw $cancellation;
        } catch (Throwable $exception) {
            $this->report($exception);
        }

        return $connection;
    }

    /**
     * Release a connection back to the pool.
     */
    public function release(ConnectionContract $connection): void
    {
        $connectionId = $this->ensureBorrowed($connection, 'release');
        unset($this->borrowedConnections[$connectionId]);

        if ($this->closed) {
            $this->destroyConnection($connection);

            return;
        }

        $this->requeueConnection($connection);
    }

    /**
     * Discard a borrowed connection from the pool.
     */
    public function discard(ConnectionContract $connection): void
    {
        $this->ensureBorrowed($connection, 'discard');
        $this->destroyConnection($connection);
    }

    /**
     * Close excess idle connections without checking their age.
     *
     * Stop when the managed count reaches the retained minimum or no idle connections remain.
     */
    public function trimExcessIdle(): void
    {
        $connectionsToInspect = $this->getIdleCount();

        while ($connectionsToInspect-- > 0
            && count($this->managedConnections) > $this->options->minRetainedConnections
            && $connection = $this->popIdleConnection()
        ) {
            $this->destroyConnection($connection);
        }
    }

    /**
     * Check one idle connection and discard it when unhealthy.
     */
    public function checkIdleConnection(): void
    {
        $connection = $this->popIdleConnection();

        if ($connection === false) {
            return;
        }

        try {
            $healthy = $connection->check();
        } catch (CanceledException $cancellation) {
            try {
                $this->destroyConnection($connection);
            } catch (CanceledException) {
            } catch (Throwable $exception) {
                $this->report($exception);
            }

            throw $cancellation;
        } catch (Throwable $exception) {
            $this->report($exception);
            $healthy = false;
        }

        if ($healthy && ! $this->closed) {
            $this->requeueConnection($connection);

            return;
        }

        $this->destroyConnection($connection);
    }

    /**
     * Close the pool and destroy every idle connection.
     *
     * Idempotent. Connections borrowed when closure begins are destroyed on
     * release, and factories completing after closure destroy their orphan.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $cancellation = null;

        try {
            $this->idleMonitor?->stop();
        } catch (CanceledException $exception) {
            $cancellation = $exception;
        } catch (Throwable $exception) {
            $this->report($exception);
        }

        $this->channel->close();

        while ($connection = $this->popIdleConnection()) {
            try {
                $this->destroyConnection($connection);
            } catch (CanceledException $exception) {
                $cancellation ??= $exception;
            }
        }

        if ($cancellation !== null) {
            throw $cancellation;
        }
    }

    /**
     * Determine if the pool is closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Get the current number of connections managed by the pool.
     */
    public function getManagedCount(): int
    {
        return count($this->managedConnections);
    }

    /**
     * Return the number of connections borrowed by callers.
     */
    public function getBorrowedCount(): int
    {
        return count($this->borrowedConnections);
    }

    /**
     * Get the pool configuration options.
     */
    public function getOptions(): PoolOptions
    {
        return $this->options;
    }

    /**
     * Get the number of connections currently available in the pool.
     */
    public function getIdleCount(): int
    {
        return $this->channel->length();
    }

    /**
     * Get the number of coroutines waiting for a connection.
     */
    public function getWaitingCount(): int
    {
        return $this->channel->waiters();
    }

    /**
     * Return the pool's current resource counts and closed state.
     *
     * @return array{managed: int, borrowed: int, idle: int, waiting: int, closed: bool}
     */
    public function getStats(): array
    {
        return [
            'managed' => $this->getManagedCount(),
            'borrowed' => $this->getBorrowedCount(),
            'idle' => $this->getIdleCount(),
            'waiting' => $this->getWaitingCount(),
            'closed' => $this->isClosed(),
        ];
    }

    /**
     * Create this pool's usage policy after subclass initialization has completed.
     */
    protected function createUsageTracker(): ?UsageTracker
    {
        return null;
    }

    /**
     * Create a new connection for the pool.
     *
     * @phpstan-impure Connection factories may yield, allowing another
     *                  coroutine to change this pool's lifecycle state.
     */
    abstract protected function createConnection(): ConnectionContract;

    /**
     * Pop and validate one idle connection.
     */
    protected function popIdleConnection(): ConnectionContract|false
    {
        $connection = $this->channel->pop();

        if ($connection === false) {
            return false;
        }

        $connectionId = spl_object_id($connection);

        if (! isset($this->managedConnections[$connectionId])) {
            throw new RuntimeException('The connection pool channel contained a connection this pool does not manage.');
        }

        if (isset($this->borrowedConnections[$connectionId])) {
            throw new RuntimeException('The connection pool channel contained a connection that is still checked out.');
        }

        return $connection;
    }

    /**
     * Return an idle connection without changing its activity timestamps.
     */
    protected function requeueConnection(ConnectionContract $connection): void
    {
        $connectionId = spl_object_id($connection);

        if (! isset($this->managedConnections[$connectionId])) {
            throw new RuntimeException('Cannot requeue a connection this pool does not manage.');
        }

        if (isset($this->borrowedConnections[$connectionId])) {
            throw new RuntimeException('Cannot requeue a connection that is still checked out.');
        }

        $this->channel->push($connection);
    }

    /**
     * Destroy a managed connection and release its capacity.
     */
    protected function destroyConnection(ConnectionContract $connection): void
    {
        $connectionId = $this->ensureManaged($connection);

        try {
            $connection->close();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->report($exception);
        } finally {
            unset(
                $this->managedConnections[$connectionId],
                $this->borrowedConnections[$connectionId]
            );
            $this->channel->signal();
        }
    }

    /**
     * Report a pool maintenance or cleanup failure without throwing.
     */
    protected function report(Throwable|string $error): void
    {
        try {
            $this->getLogger()?->error((string) $error);
        } catch (Throwable) {
        }
    }

    /**
     * Get the logger instance if available.
     */
    protected function getLogger(): ?StdoutLoggerInterface
    {
        if (! $this->container->has(StdoutLoggerInterface::class)) {
            return null;
        }

        return $this->container->make(StdoutLoggerInterface::class);
    }

    /**
     * Get or create a connection before the checkout deadline.
     */
    private function getConnection(int $deadline): ConnectionContract
    {
        $timedOut = false;

        while (true) {
            if ($this->closed) {
                throw new PoolClosedException('Cannot borrow from a closed connection pool.');
            }

            if ($connection = $this->popIdleConnection()) {
                return $connection;
            }

            if (count($this->managedConnections) + $this->creatingCount < $this->options->maxConnections) {
                ++$this->creatingCount;

                try {
                    $connection = $this->createConnection();
                } catch (Throwable $exception) {
                    --$this->creatingCount;
                    $this->channel->signal();

                    throw $exception;
                }

                --$this->creatingCount;
                $connectionId = spl_object_id($connection);

                if (isset($this->managedConnections[$connectionId])) {
                    $this->channel->signal();

                    throw new RuntimeException(
                        'The connection pool factory returned a connection this pool already manages. '
                        . 'Factories must construct fresh connection instances.'
                    );
                }

                $this->managedConnections[$connectionId] = true;

                if ($this->closed) {
                    $this->destroyConnection($connection);

                    throw new PoolClosedException('Cannot borrow from a closed connection pool.');
                }

                return $connection;
            }

            if ($timedOut) {
                throw new PoolExhaustedException(
                    'Connection pool exhausted. Cannot establish new connection before wait_timeout.'
                );
            }

            $timedOut = ! $this->waitForStateChange($deadline);
        }
    }

    /**
     * Wait until pool state changes or the checkout deadline expires.
     */
    private function waitForStateChange(int $deadline): bool
    {
        $remaining = $deadline - hrtime(true);

        if ($remaining <= 0) {
            return false;
        }

        return $this->channel->wait($remaining / 1e9);
    }

    /**
     * Convert seconds to nanoseconds without overflowing integer arithmetic.
     */
    protected function nanoseconds(float $seconds): int
    {
        return $seconds >= PHP_INT_MAX / 1e9
            ? PHP_INT_MAX
            : (int) ($seconds * 1e9);
    }

    /**
     * Build a monotonic deadline without overflowing at long durations or uptimes.
     */
    protected function deadline(float $seconds): int
    {
        $now = hrtime(true);
        $duration = $this->nanoseconds($seconds);

        return $duration > PHP_INT_MAX - $now
            ? PHP_INT_MAX
            : $now + $duration;
    }

    /**
     * Ensure that a connection belongs to this pool before destroying it.
     */
    protected function ensureManaged(ConnectionContract $connection): int
    {
        $connectionId = spl_object_id($connection);

        if (! isset($this->managedConnections[$connectionId])) {
            throw new RuntimeException('Cannot destroy a connection this pool does not manage.');
        }

        return $connectionId;
    }

    /**
     * Ensure that a connection is currently borrowed from this pool.
     */
    private function ensureBorrowed(ConnectionContract $connection, string $operation): int
    {
        $connectionId = spl_object_id($connection);

        if (! isset($this->managedConnections[$connectionId])) {
            throw new RuntimeException(sprintf(
                'Cannot %s a connection this pool does not manage.',
                $operation,
            ));
        }

        if (! isset($this->borrowedConnections[$connectionId])) {
            throw new RuntimeException(sprintf(
                'Cannot %s a connection that is not checked out.',
                $operation,
            ));
        }

        return $connectionId;
    }
}
