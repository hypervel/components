<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Pool\Exceptions\ConnectionException;
use InvalidArgumentException;
use Redis;
use RedisCluster;
use Throwable;

/**
 * Redis Cluster connection using phpredis RedisCluster client.
 */
class PhpRedisClusterConnection extends PhpRedisConnection
{
    /**
     * The default node to use from the cluster.
     */
    protected string|array|null $defaultNode = null;

    /**
     * Reconnect to Redis Cluster.
     *
     * @throws ConnectionException
     */
    public function reconnect(): bool
    {
        $this->defaultNode = null;

        $redis = $this->createRedisCluster();

        $this->setOptions($redis);

        // RedisCluster handles auth in its constructor, no separate auth call needed.
        // RedisCluster doesn't support select(), no database selection.

        $this->connection = $redis;
        $this->lastUseTime = microtime(true);

        if (($this->config['event']['enable'] ?? false) && $this->container->bound('events')) {
            $this->eventDispatcher = $this->container->make('events');
        }

        return true;
    }

    /**
     * Determine if the connection is to a Redis Cluster.
     */
    public function isCluster(): bool
    {
        return true;
    }

    /**
     * Determine if the underlying Redis Cluster client is in pipeline/multi mode.
     */
    protected function isQueueingMode(): bool
    {
        return $this->connection->getMode() !== Redis::ATOMIC;
    }

    /**
     * Get the master nodes in the cluster.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    public function masters(): array
    {
        return $this->connection->_masters();
    }

    /**
     * Scan all keys based on options.
     *
     * Overrides the base scan to include a node parameter for RedisCluster,
     * which requires specifying which node to scan.
     *
     * @param mixed $cursor
     * @param array $arguments
     */
    public function scan(&$cursor, ...$arguments): mixed
    {
        if (! $this->shouldTransform) {
            return $this->__call('scan', array_merge([&$cursor], $arguments));
        }

        $options = $this->getScanOptions($arguments);

        $result = $this->connection->scan(
            $cursor,
            $options['node'] ?? $this->defaultNode(),
            $options['match'] ?? '*',
            $options['count'] ?? 10
        );

        if ($result === false) {
            $result = [];
        }

        return $cursor === 0 && empty($result) ? false : [$cursor, $result];
    }

    /**
     * Flush the selected Redis database on all master nodes.
     */
    protected function callFlushdb(mixed ...$arguments): mixed
    {
        $async = strtoupper((string) ($arguments[0] ?? null)) === 'ASYNC';

        foreach ($this->masters() as $master) {
            if ($async) {
                $this->connection->rawCommand($master, 'flushdb', 'async');
            } else {
                $this->connection->flushdb($master); // @phpstan-ignore argument.type (connection is always RedisCluster here)
            }
        }

        return null;
    }

    /**
     * Create a Redis Cluster connection.
     *
     * @throws ConnectionException
     */
    protected function createRedisCluster(): RedisCluster
    {
        try {
            $parameters = [];
            $parameters[] = $this->config['cluster']['name'] ?? null;
            $parameters[] = $this->config['cluster']['seeds'] ?? [];
            $parameters[] = $this->config['timeout'] ?? 0.0;
            $parameters[] = $this->config['cluster']['read_timeout'] ?? 0.0;
            $parameters[] = $this->config['cluster']['persistent'] ?? false;
            $parameters[] = $this->config['password'] ?? null;
            if (! empty($this->config['cluster']['context'])) {
                $parameters[] = $this->config['cluster']['context'];
            }

            $redis = new RedisCluster(...$parameters);
        } catch (Throwable $exception) {
            throw new ConnectionException('Connection reconnect failed ' . $exception->getMessage());
        }

        return $redis;
    }

    /**
     * Return default node to use for cluster.
     *
     * @throws InvalidArgumentException
     */
    private function defaultNode(): string|array
    {
        if ($this->defaultNode === null) {
            $this->defaultNode = $this->connection->_masters()[0]
                ?? throw new InvalidArgumentException('Unable to determine default node. No master nodes found in the cluster.');
        }

        return $this->defaultNode;
    }
}
