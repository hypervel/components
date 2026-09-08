<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\ConnectionPool\BorrowRateTracker;
use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\ConnectionPool\Connection as PoolConnection;
use Hypervel\Contracts\ConnectionPool\UsageTracker;
use Hypervel\Contracts\Container\Container;
use Hypervel\Coordinator\Timer;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisConnection;
use Swoole\Coroutine\CanceledException;
use Throwable;

class RedisPool extends ConnectionPool
{
    protected array $config;

    protected ?Timer $heartbeatTimer = null;

    protected ?int $heartbeatTimerId = null;

    protected bool $heartbeatStarted = false;

    /**
     * Create a new Redis pool instance.
     */
    public function __construct(Container $container, string $name)
    {
        $configService = $container->make(RedisConfig::class);
        $this->config = $configService->connectionConfig($name);
        $poolOptions = $this->config['pool'];

        parent::__construct($container, $name, $poolOptions);

        if ($this->config['timeout'] === null) {
            $this->config['timeout'] = $this->options->connectTimeout;
        }

        $this->heartbeatTimer = new Timer($this->getLogger());
    }

    /**
     * Enable background maintenance after pool initialization succeeds.
     */
    public function start(): void
    {
        parent::start();
        $this->startHeartbeat();
    }

    /**
     * Get the Redis connection configuration.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Create a usage policy for this Redis pool.
     */
    protected function createUsageTracker(): ?UsageTracker
    {
        return new BorrowRateTracker;
    }

    /**
     * Create a new pooled Redis connection.
     */
    protected function createConnection(): PoolConnection
    {
        if ($this->config['cluster']['enabled'] ?? false) {
            return new PhpRedisClusterConnection($this->container, $this, $this->config);
        }

        return new PhpRedisConnection($this->container, $this, $this->config);
    }

    /**
     * Close the Redis pool and clear its shared resources.
     */
    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }

        $this->clearHeartbeat();

        parent::close();
    }

    /**
     * Start the heartbeat timer if configured.
     */
    protected function startHeartbeat(): void
    {
        if ($this->heartbeatStarted || $this->isClosed() || $this->heartbeatTimer === null
            || $this->options->heartbeatInterval === null
        ) {
            return;
        }

        // Timer creation can reenter pool lifecycle methods through startup hooks.
        $this->heartbeatStarted = true;

        try {
            $timerId = $this->heartbeatTimer->tick(
                $this->options->heartbeatInterval,
                function (bool $isClosing): ?string {
                    if ($isClosing || $this->isClosed()) {
                        return Timer::STOP;
                    }

                    $this->heartbeat();

                    return null;
                }
            );
        } catch (Throwable $exception) {
            $this->heartbeatStarted = false;

            throw $exception;
        }

        if (! $this->heartbeatStarted || $this->isClosed()) {
            $this->heartbeatStarted = false;
            $this->heartbeatTimer->clear($timerId);

            return;
        }

        $this->heartbeatTimerId = $timerId;
    }

    /**
     * Clear the heartbeat timer.
     */
    protected function clearHeartbeat(): void
    {
        $timerId = $this->heartbeatTimerId;
        $this->heartbeatTimerId = null;
        $this->heartbeatStarted = false;

        if ($timerId !== null) {
            $this->heartbeatTimer?->clear($timerId);
        }
    }

    /**
     * Run one heartbeat sweep over currently idle connections.
     */
    protected function heartbeat(): void
    {
        $connectionsToInspect = $this->getIdleCount();

        for ($index = 0; $index < $connectionsToInspect; ++$index) {
            /** @var false|RedisConnection $connection */
            $connection = $this->popIdleConnection();

            if ($connection === false) {
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
            $now = hrtime(true) / 1e9;

            $expired = $connection->isLifetimeExpired($now)
                || ($connection->isIdleExpired($now)
                    && $this->getManagedCount() > $this->options->minRetainedConnections);
            $healthy = ! $expired && $connection->heartbeatCheck($this->options->heartbeatTimeout);
        } catch (CanceledException $cancellation) {
            try {
                $this->discardHeartbeatConnection($connection);
            } catch (CanceledException) {
            } catch (Throwable $exception) {
                $this->report($exception);
            }

            throw $cancellation;
        } catch (Throwable $exception) {
            $this->report('Redis heartbeat failed: ' . $exception);
            $healthy = false;
        }

        if ($healthy && ! $this->isClosed()) {
            $this->requeueConnection($connection);
        } else {
            $this->discardHeartbeatConnection($connection);
        }
    }

    /**
     * Discard an idle connection from the pool.
     */
    protected function discardHeartbeatConnection(RedisConnection $connection): void
    {
        $this->destroyConnection($connection);
    }
}
