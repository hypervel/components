<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Contracts\Pool\FrequencyInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Contracts\Pool\PoolOptionInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Manage reusable connections with explicit ownership and terminal teardown.
 */
abstract class Pool implements PoolInterface
{
    protected Channel $channel;

    protected PoolOptionInterface $option;

    /** @var array<int, true> */
    protected array $managedConnections = [];

    /** @var array<int, true> */
    protected array $borrowedConnections = [];

    protected int $creating = 0;

    protected bool $closed = false;

    protected FrequencyInterface|LowFrequencyInterface|null $frequency = null;

    /**
     * Create a connection pool.
     */
    public function __construct(
        protected Container $container,
        protected string $name,
        array $config = []
    ) {
        $this->initOption($config);

        $this->channel = new Channel($this->option->getMaxConnections());
    }

    /**
     * Get the pool name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get a connection from the pool.
     */
    public function get(): ConnectionInterface
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot borrow from a closed connection pool.');
        }

        $deadline = $this->deadline($this->option->getWaitTimeout());
        $connection = $this->getConnection($deadline);
        $this->borrowedConnections[spl_object_id($connection)] = true;

        try {
            if ($this->frequency instanceof FrequencyInterface) {
                $this->frequency->hit();
            }

            if ($this->frequency instanceof LowFrequencyInterface
                && $this->frequency->isLowFrequency()
            ) {
                $this->flush();
            }
        } catch (Throwable $exception) {
            $this->report($exception);
        }

        return $connection;
    }

    /**
     * Release a connection back to the pool.
     */
    public function release(ConnectionInterface $connection): void
    {
        $connectionId = $this->assertBorrowed($connection);
        unset($this->borrowedConnections[$connectionId]);

        if ($this->closed) {
            $this->destroyConnection($connection);

            return;
        }

        $this->requeueConnection($connection);
    }

    /**
     * Close idle connections in excess of the minimum pool size.
     */
    public function flush(): void
    {
        $connectionsToInspect = $this->getConnectionsInChannel();

        while ($connectionsToInspect-- > 0
            && count($this->managedConnections) > $this->option->getMinConnections()
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
        $this->channel->close();

        while ($connection = $this->popIdleConnection()) {
            $this->destroyConnection($connection);
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
    public function getCurrentConnections(): int
    {
        return count($this->managedConnections);
    }

    /**
     * Get the pool configuration options.
     */
    public function getOption(): PoolOptionInterface
    {
        return $this->option;
    }

    /**
     * Get the number of connections currently available in the pool.
     */
    public function getConnectionsInChannel(): int
    {
        return $this->channel->length();
    }

    /**
     * Initialize pool options from configuration.
     */
    protected function initOption(array $options = []): void
    {
        $knownOptions = [
            'min_connections',
            'max_connections',
            'connect_timeout',
            'wait_timeout',
            'heartbeat',
            'heartbeat_timeout',
            'max_idle_time',
            'max_lifetime',
            'events',
        ];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);

        if ($unknownOptions !== []) {
            throw new InvalidArgumentException(
                'Unknown connection pool option(s) [' . implode(', ', $unknownOptions) . ']. Known options are ['
                . implode(', ', $knownOptions) . '].',
            );
        }

        $this->option = new PoolOption(
            minConnections: $options['min_connections'] ?? 1,
            maxConnections: $options['max_connections'] ?? 10,
            connectTimeout: $options['connect_timeout'] ?? 10.0,
            waitTimeout: $options['wait_timeout'] ?? 3.0,
            heartbeat: $options['heartbeat'] ?? -1,
            heartbeatTimeout: $options['heartbeat_timeout'] ?? 1.0,
            maxIdleTime: $options['max_idle_time'] ?? 60.0,
            maxLifetime: $options['max_lifetime'] ?? -1.0,
            events: $options['events'] ?? [],
        );
    }

    /**
     * Create a new connection for the pool.
     *
     * @phpstan-impure Connection factories may yield, allowing another
     *                  coroutine to change this pool's lifecycle state.
     */
    abstract protected function createConnection(): ConnectionInterface;

    /**
     * Pop and validate one idle connection.
     */
    protected function popIdleConnection(): ConnectionInterface|false
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
    protected function requeueConnection(ConnectionInterface $connection): void
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
    protected function destroyConnection(ConnectionInterface $connection): void
    {
        $connectionId = spl_object_id($connection);

        if (! isset($this->managedConnections[$connectionId])) {
            throw new RuntimeException('Cannot destroy a connection this pool does not manage.');
        }

        try {
            $connection->close();
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
    private function getConnection(int $deadline): ConnectionInterface
    {
        while (true) {
            if ($this->closed) {
                throw new RuntimeException('Cannot borrow from a closed connection pool.');
            }

            if ($connection = $this->popIdleConnection()) {
                return $connection;
            }

            if (count($this->managedConnections) + $this->creating < $this->option->getMaxConnections()) {
                ++$this->creating;

                try {
                    $connection = $this->createConnection();
                } catch (Throwable $exception) {
                    --$this->creating;
                    $this->channel->signal();

                    throw $exception;
                }

                --$this->creating;
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

                    throw new RuntimeException('Cannot borrow from a closed connection pool.');
                }

                return $connection;
            }

            if (! $this->waitForStateChange($deadline)) {
                throw new RuntimeException(
                    'Connection pool exhausted. Cannot establish new connection before wait_timeout.'
                );
            }
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
     * Assert that a connection is currently borrowed from this pool.
     */
    private function assertBorrowed(ConnectionInterface $connection): int
    {
        $connectionId = spl_object_id($connection);

        if (! isset($this->managedConnections[$connectionId])) {
            throw new RuntimeException('Cannot release a connection this pool does not manage.');
        }

        if (! isset($this->borrowedConnections[$connectionId])) {
            throw new RuntimeException('Cannot release a connection that is not checked out (double release?).');
        }

        return $connectionId;
    }
}
