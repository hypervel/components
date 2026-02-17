<?php

declare(strict_types=1);

namespace Hypervel\Pool;

use Closure;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Engine\Channel;
use Hypervel\Pool\Exception\InvalidArgumentException;
use Hypervel\Pool\Exception\SocketPopException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Abstract connection that maintains a keepalive heartbeat.
 *
 * Uses a timer to periodically check connection health and
 * automatically closes idle connections.
 */
abstract class KeepaliveConnection implements ConnectionInterface
{
    protected Timer $timer;

    protected Channel $channel;

    protected float $lastUseTime = 0.0;

    protected ?int $timerId = null;

    protected bool $connected = false;

    protected string $name = 'keepalive.connection';

    public function __construct(
        protected Container $container,
        protected Pool $pool
    ) {
        $this->timer = new Timer();
    }

    public function __destruct()
    {
        $this->clear();
    }

    /**
     * Release the connection back to the pool.
     */
    public function release(): void
    {
        $this->pool->release($this);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function getConnection(): mixed
    {
        throw new InvalidArgumentException('Please use call instead of getConnection.');
    }

    /**
     * Check if the connection is valid.
     */
    public function check(): bool
    {
        return $this->isConnected();
    }

    /**
     * Reconnect to the server.
     */
    public function reconnect(): bool
    {
        $this->close();

        $connection = $this->getActiveConnection();

        $channel = new Channel(1);
        $channel->push($connection);
        $this->channel = $channel;
        $this->lastUseTime = microtime(true);

        $this->addHeartbeat();

        return true;
    }

    /**
     * Execute a closure with the connection.
     *
     * @param bool $refresh Whether to refresh the last use time
     */
    public function call(Closure $closure, bool $refresh = true): mixed
    {
        if (! $this->isConnected()) {
            $this->reconnect();
        }

        $connection = $this->channel->pop($this->pool->getOption()->getWaitTimeout());
        if ($connection === false) {
            throw new SocketPopException(sprintf('Socket of %s is exhausted. Cannot establish socket before timeout.', $this->name));
        }

        try {
            $result = $closure($connection);
            if ($refresh) {
                $this->lastUseTime = microtime(true);
            }
        } finally {
            if ($this->isConnected()) {
                $this->channel->push($connection, 0.001);
            } else {
                // Unset and drop the connection.
                unset($connection);
            }
        }

        return $result;
    }

    /**
     * Check if currently connected.
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Close the connection.
     */
    public function close(): bool
    {
        if ($this->isConnected()) {
            $this->call(function ($connection) {
                try {
                    if ($this->isConnected()) {
                        $this->sendClose($connection);
                    }
                } finally {
                    $this->clear();
                }
            }, false);
        }

        return true;
    }

    /**
     * Check if the connection has timed out.
     */
    public function isTimeout(): bool
    {
        return $this->lastUseTime < microtime(true) - $this->pool->getOption()->getMaxIdleTime()
            && $this->channel->getLength() > 0;
    }

    /**
     * Add a heartbeat timer.
     */
    protected function addHeartbeat(): void
    {
        $this->connected = true;
        $this->timerId = $this->timer->tick($this->getHeartbeatSeconds(), function () {
            try {
                if (! $this->isConnected()) {
                    return;
                }

                if ($this->isTimeout()) {
                    // The socket does not use in double of heartbeat.
                    $this->close();

                    return;
                }

                $this->heartbeat();
            } catch (Throwable $throwable) {
                $this->clear();
                if ($logger = $this->getLogger()) {
                    $message = sprintf('Socket of %s heartbeat failed, %s', $this->name, $throwable);
                    $logger->error($message);
                }
            }
        });
    }

    /**
     * Get the heartbeat interval in seconds.
     */
    protected function getHeartbeatSeconds(): int
    {
        $heartbeat = $this->pool->getOption()->getHeartbeat();

        if ($heartbeat > 0) {
            return intval($heartbeat);
        }

        return 10;
    }

    /**
     * Clear the connection state.
     */
    protected function clear(): void
    {
        $this->connected = false;

        if ($this->timerId) {
            $this->timer->clear($this->timerId);
            $this->timerId = null;
        }
    }

    /**
     * Get the logger instance.
     */
    protected function getLogger(): ?LoggerInterface
    {
        if ($this->container->has(StdoutLoggerInterface::class)) {
            return $this->container->make(StdoutLoggerInterface::class);
        }

        return null;
    }

    /**
     * Send a heartbeat to keep the connection alive.
     */
    protected function heartbeat(): void
    {
    }

    /**
     * Send a close protocol message.
     */
    protected function sendClose(mixed $connection): void
    {
    }

    /**
     * Connect and return the active connection.
     */
    abstract protected function getActiveConnection(): mixed;
}
