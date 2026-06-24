<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Pool\Pool;
use Hypervel\Redis\Frequency;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Arr;
use Psr\Log\LoggerInterface;
use Throwable;

class RedisPool extends Pool
{
    protected array $config;

    protected ?Timer $heartbeatTimer = null;

    protected ?int $heartbeatTimerId = null;

    protected int $heartbeatGeneration = 0;

    /**
     * Create a new Redis pool instance.
     */
    public function __construct(Container $container, string $name)
    {
        $configService = $container->make(RedisConfig::class);
        $this->config = $configService->connectionConfig($name);
        $poolOptions = Arr::get($this->config, 'pool', []);

        $this->frequency = new Frequency($this);

        parent::__construct($container, $name, $poolOptions);

        $this->heartbeatTimer = new Timer($this->getLogger());
        $this->startHeartbeat();
    }

    /**
     * Destroy the Redis pool.
     */
    public function __destruct()
    {
        $this->clearHeartbeat();
    }

    /**
     * Get the Redis connection configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Create a new pooled Redis connection.
     */
    protected function createConnection(): ConnectionInterface
    {
        if ($this->config['cluster']['enable'] ?? false) {
            return new PhpRedisClusterConnection($this->container, $this, $this->config);
        }

        return new PhpRedisConnection($this->container, $this, $this->config);
    }

    /**
     * Flush all connections from the pool.
     */
    public function flushAll(): void
    {
        $this->clearHeartbeat();

        parent::flushAll();
    }

    /**
     * Start the heartbeat timer if configured.
     */
    protected function startHeartbeat(): void
    {
        if ($this->heartbeatTimer === null || $this->option->getHeartbeat() <= 0) {
            return;
        }

        $this->heartbeatTimerId = $this->heartbeatTimer->tick(
            $this->option->getHeartbeat(),
            function (bool $isClosing): ?string {
                if ($isClosing) {
                    return Timer::STOP;
                }

                $this->heartbeat();

                return null;
            }
        );
    }

    /**
     * Clear the heartbeat timer.
     */
    protected function clearHeartbeat(): void
    {
        ++$this->heartbeatGeneration;

        if ($this->heartbeatTimer === null || $this->heartbeatTimerId === null) {
            return;
        }

        $this->heartbeatTimer->clear($this->heartbeatTimerId);
        $this->heartbeatTimerId = null;
    }

    /**
     * Run one heartbeat sweep over currently idle connections.
     */
    protected function heartbeat(): void
    {
        $connectionsToInspect = $this->getConnectionsInChannel();

        for ($i = 0; $i < $connectionsToInspect; ++$i) {
            $connection = $this->channel->pop(0.001);

            if (! $connection instanceof RedisConnection) {
                break;
            }

            $this->heartbeatConnection($connection);
        }
    }

    /**
     * Heartbeat one idle connection.
     */
    protected function heartbeatConnection(RedisConnection $connection): void
    {
        try {
            $now = microtime(true);

            if ($connection->isLifetimeExpired($now)) {
                $this->discardHeartbeatConnection($connection);

                return;
            }

            if ($connection->isIdleExpired($now) && $this->currentConnections > $this->option->getMinConnections()) {
                $this->discardHeartbeatConnection($connection);

                return;
            }

            $heartbeatGeneration = $this->heartbeatGeneration;

            if ($connection->heartbeatCheck($this->option->getHeartbeatTimeout())) {
                if ($heartbeatGeneration === $this->heartbeatGeneration) {
                    $this->release($connection);
                } else {
                    $this->discardHeartbeatConnection($connection);
                }

                return;
            }

            $this->discardHeartbeatConnection($connection);
        } catch (Throwable $exception) {
            $this->logHeartbeatError('Redis heartbeat failed: ' . $exception);
            $this->discardHeartbeatConnection($connection);
        }
    }

    /**
     * Discard an idle connection from the pool.
     */
    protected function discardHeartbeatConnection(RedisConnection $connection): void
    {
        --$this->currentConnections;

        try {
            $connection->close();
        } catch (Throwable $exception) {
            $this->logHeartbeatError('Redis heartbeat close failed: ' . $exception);
        }
    }

    /**
     * Log a heartbeat error without breaking pool cleanup.
     */
    protected function logHeartbeatError(string $message): void
    {
        try {
            $this->getLogger()?->error($message);
        } catch (Throwable) {
        }
    }

    /**
     * Get the logger instance if available.
     */
    private function getLogger(): ?LoggerInterface
    {
        if (! $this->container->has(StdoutLoggerInterface::class)) {
            return null;
        }

        return $this->container->make(StdoutLoggerInterface::class);
    }
}
