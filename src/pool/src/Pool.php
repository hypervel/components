<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Hyperf\Contract\StdoutLoggerInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Contracts\Pool\FrequencyInterface;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Contracts\Pool\PoolOptionInterface;
use RuntimeException;
use Throwable;

/**
 * Abstract base class for connection pools.
 *
 * Manages a pool of reusable connections using a Swoole channel for
 * coroutine-safe storage and retrieval.
 */
abstract class Pool implements PoolInterface
{
    protected Channel $channel;

    protected PoolOptionInterface $option;

    protected int $currentConnections = 0;

    protected FrequencyInterface|LowFrequencyInterface|null $frequency = null;

    public function __construct(
        protected Container $container,
        array $config = []
    ) {
        $this->initOption($config);

        $this->channel = new Channel($this->option->getMaxConnections());
    }

    /**
     * Get a connection from the pool.
     */
    public function get(): ConnectionInterface
    {
        $connection = $this->getConnection();

        try {
            if ($this->frequency instanceof FrequencyInterface) {
                $this->frequency->hit();
            }

            if ($this->frequency instanceof LowFrequencyInterface) {
                if ($this->frequency->isLowFrequency()) {
                    $this->flush();
                }
            }
        } catch (Throwable $exception) {
            $this->getLogger()?->error((string) $exception);
        }

        return $connection;
    }

    /**
     * Release a connection back to the pool.
     */
    public function release(ConnectionInterface $connection): void
    {
        $this->channel->push($connection);
    }

    /**
     * Flush excess connections down to the minimum pool size.
     */
    public function flush(): void
    {
        $num = $this->getConnectionsInChannel();

        if ($num > 0) {
            while ($this->currentConnections > $this->option->getMinConnections() && $conn = $this->channel->pop(0.001)) {
                try {
                    $conn->close();
                } catch (Throwable $exception) {
                    $this->getLogger()?->error((string) $exception);
                } finally {
                    --$this->currentConnections;
                    --$num;
                }

                if ($num <= 0) {
                    // Ignore connections queued during flushing.
                    break;
                }
            }
        }
    }

    /**
     * Flush a single connection from the pool.
     */
    public function flushOne(bool $force = false): void
    {
        $num = $this->getConnectionsInChannel();
        if ($num > 0 && $conn = $this->channel->pop(0.001)) {
            if ($force || ! $conn->check()) {
                try {
                    $conn->close();
                } catch (Throwable $exception) {
                    $this->getLogger()?->error((string) $exception);
                } finally {
                    --$this->currentConnections;
                }
            } else {
                $this->release($conn);
            }
        }
    }

    /**
     * Flush all connections from the pool.
     */
    public function flushAll(): void
    {
        while ($this->getConnectionsInChannel() > 0) {
            $this->flushOne(true);
        }
    }

    /**
     * Get the current number of connections managed by the pool.
     */
    public function getCurrentConnections(): int
    {
        return $this->currentConnections;
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
        $this->option = new PoolOption(
            minConnections: $options['min_connections'] ?? 1,
            maxConnections: $options['max_connections'] ?? 10,
            connectTimeout: $options['connect_timeout'] ?? 10.0,
            waitTimeout: $options['wait_timeout'] ?? 3.0,
            heartbeat: $options['heartbeat'] ?? -1,
            maxIdleTime: $options['max_idle_time'] ?? 60.0,
            events: $options['events'] ?? [],
        );
    }

    /**
     * Create a new connection for the pool.
     */
    abstract protected function createConnection(): ConnectionInterface;

    /**
     * Get a connection from the pool or create a new one.
     */
    private function getConnection(): ConnectionInterface
    {
        $num = $this->getConnectionsInChannel();

        try {
            if ($num === 0 && $this->currentConnections < $this->option->getMaxConnections()) {
                ++$this->currentConnections;
                return $this->createConnection();
            }
        } catch (Throwable $throwable) {
            --$this->currentConnections;
            throw $throwable;
        }

        $connection = $this->channel->pop($this->option->getWaitTimeout());
        if (! $connection instanceof ConnectionInterface) {
            throw new RuntimeException('Connection pool exhausted. Cannot establish new connection before wait_timeout.');
        }

        return $connection;
    }

    /**
     * Get the logger instance if available.
     */
    private function getLogger(): ?StdoutLoggerInterface
    {
        if (! $this->container->has(StdoutLoggerInterface::class)) {
            return null;
        }

        return $this->container->make(StdoutLoggerInterface::class);
    }
}
