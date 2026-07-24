<?php

declare(strict_types=1);

namespace Hypervel\Redis\Pool;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Pool\Frequency;
use Hypervel\Pool\Pool;
use Hypervel\Redis\PhpRedisClusterConnection;
use Hypervel\Redis\PhpRedisConnection;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Arr;
use Throwable;

class RedisPool extends Pool
{
    protected array $config;

    protected ?Timer $heartbeatTimer = null;

    protected ?int $heartbeatTimerId = null;

    /**
     * Create a new Redis pool instance.
     */
    public function __construct(Container $container, string $name)
    {
        $configService = $container->make(RedisConfig::class);
        $this->config = $configService->connectionConfig($name);
        $poolOptions = Arr::get($this->config, 'pool', []);

        $this->frequency = new Frequency;

        parent::__construct($container, $name, $poolOptions);

        if (! array_key_exists('timeout', $this->config)) {
            $this->config['timeout'] = $this->option->getConnectTimeout();
        }

        $this->heartbeatTimer = new Timer($this->getLogger());
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
        if ($this->heartbeatTimer === null || $this->option->getHeartbeat() <= 0) {
            return;
        }

        $this->heartbeatTimerId = $this->heartbeatTimer->tick(
            $this->option->getHeartbeat(),
            function (bool $isClosing): ?string {
                if ($isClosing || $this->isClosed()) {
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

            if ($connection->isLifetimeExpired($now)) {
                $this->discardHeartbeatConnection($connection);

                return;
            }

            if ($connection->isIdleExpired($now)
                && $this->getCurrentConnections() > $this->option->getMinConnections()
            ) {
                $this->discardHeartbeatConnection($connection);

                return;
            }

            if ($connection->heartbeatCheck($this->option->getHeartbeatTimeout())) {
                if ($this->isClosed()) {
                    $this->discardHeartbeatConnection($connection);

                    return;
                }

                $this->requeueConnection($connection);

                return;
            }

            $this->discardHeartbeatConnection($connection);
        } catch (Throwable $exception) {
            $this->report('Redis heartbeat failed: ' . $exception);
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
