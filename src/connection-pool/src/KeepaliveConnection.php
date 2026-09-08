<?php

declare(strict_types=1);

namespace Hypervel\ConnectionPool;

use Closure;
use Hypervel\ConnectionPool\Exceptions\InvalidArgumentException;
use Hypervel\ConnectionPool\Exceptions\SocketPopException;
use Hypervel\Contracts\ConnectionPool\Connection as ConnectionContract;
use Hypervel\Contracts\ConnectionPool\ConnectionPool as ConnectionPoolContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Engine\Channel;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Abstract connection that maintains a keepalive heartbeat.
 *
 * Uses a timer to periodically check connection health and
 * automatically closes idle connections.
 *
 * Subclasses must return resources that close their underlying socket when
 * the final reference is released. Failed or canceled cleanup may release
 * a socket without sending the close protocol message.
 */
abstract class KeepaliveConnection implements ConnectionContract
{
    protected Timer $timer;

    protected Channel $channel;

    protected float $lastUseTime = 0.0;

    protected ?int $timerId = null;

    protected bool $connected = false;

    protected string $name = 'keepalive.connection';

    public function __construct(
        protected Container $container,
        protected ConnectionPoolContract $pool
    ) {
        $this->timer = new Timer;
    }

    /**
     * Release the connection back to the pool.
     */
    public function release(): void
    {
        $this->pool->release($this);
    }

    /**
     * Discard the connection from its pool.
     */
    public function discard(): void
    {
        $this->pool->discard($this);
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

        if ($this->isConnected()) {
            try {
                $this->sendClose($connection);
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $message = sprintf('Socket of %s close failed, %s', $this->name, $exception);

                if ($logger = $this->getLogger()) {
                    $logger->error($message);
                } else {
                    error_log($message);
                }
            }

            return $this->isConnected();
        }

        $channel = new Channel(1);
        $channel->push($connection);
        $previousChannel = $this->channel ?? null;
        $this->channel = $channel;
        $this->lastUseTime = hrtime(true) / 1e9;

        try {
            $this->addHeartbeat();
        } catch (Throwable $exception) {
            if ($this->channel === $channel) {
                $this->closeAfterFailure();
            }

            throw $exception;
        } finally {
            // Wake old waiters only after the replacement state is settled.
            $previousChannel?->close();
        }

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

        $channel = $this->channel;
        $connection = $channel->pop($this->pool->getOptions()->waitTimeout);
        if ($connection === false) {
            if ($channel->isCanceled()) {
                throw new CanceledException('The keepalive connection wait was canceled.');
            }

            throw new SocketPopException(sprintf('Socket of %s is exhausted. Cannot establish socket before timeout.', $this->name));
        }

        try {
            $result = $closure($connection);
            if ($refresh && $this->channel === $channel && $this->isConnected()) {
                $this->lastUseTime = hrtime(true) / 1e9;
            }
        } finally {
            if ($this->channel === $channel && $this->isConnected()) {
                $channel->push($connection, 0.001);
            } else {
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
        $channel = $this->channel ?? null;

        try {
            if ($this->isConnected()) {
                $this->call(function ($connection) use ($channel): void {
                    try {
                        if ($this->isConnected()) {
                            $this->sendClose($connection);
                        }
                    } finally {
                        if ($this->channel === $channel) {
                            $this->clear();
                        }
                    }
                }, false);
            }
        } finally {
            if (($this->channel ?? null) === $channel) {
                $this->clear();
            }
        }

        return true;
    }

    /**
     * Check if the connection has timed out.
     */
    public function isTimeout(): bool
    {
        if (! $this->isConnected()) {
            return false;
        }

        $maxIdleTime = $this->pool->getOptions()->maxIdleTime;

        return $maxIdleTime !== null
            && $this->lastUseTime < hrtime(true) / 1e9 - $maxIdleTime
            && $this->channel->getLength() > 0;
    }

    /**
     * Add a heartbeat timer when heartbeat is enabled.
     *
     * For keepalive connections, max_idle_time eviction is driven by this
     * timer, so disabling heartbeat also disables background idle closing.
     */
    protected function addHeartbeat(): void
    {
        $this->connected = true;

        $heartbeatInterval = $this->pool->getOptions()->heartbeatInterval;

        if ($heartbeatInterval === null) {
            return;
        }

        $channel = $this->channel;
        $timerId = $this->timer->tick($heartbeatInterval, function () use ($channel): void {
            try {
                if (! $this->isConnected()) {
                    return;
                }

                if ($this->isTimeout()) {
                    // Close the socket if it has been idle longer than max_idle_time.
                    $this->close();

                    return;
                }

                $this->heartbeat();
            } catch (CanceledException $exception) {
                if ($this->channel === $channel) {
                    $this->closeAfterFailure();
                }

                throw $exception;
            } catch (Throwable $throwable) {
                if ($this->channel === $channel) {
                    $this->closeAfterFailure();
                }
                $message = sprintf('Socket of %s heartbeat failed, %s', $this->name, $throwable);

                if ($logger = $this->getLogger()) {
                    $logger->error($message);
                } else {
                    error_log($message);
                }
            }
        });

        if ($this->channel !== $channel || ! $this->isConnected()) {
            $this->timer->clear($timerId);

            return;
        }

        $this->timerId = $timerId;
    }

    /**
     * Clear the connection state.
     */
    protected function clear(): void
    {
        $this->connected = false;

        if ($this->timerId !== null) {
            $this->timer->clear($this->timerId);
            $this->timerId = null;
        }
    }

    /**
     * Close a failed connection without replacing its primary failure.
     */
    private function closeAfterFailure(): void
    {
        try {
            $this->close();
        } catch (Throwable) {
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
