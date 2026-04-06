<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\Exceptions\ConnectionException;
use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use Psr\Log\LogLevel;
use Redis;
use RedisException;
use Throwable;

/**
 * Standard phpredis connection for standalone Redis and Sentinel.
 */
class PhpRedisConnection extends RedisConnection
{
    /**
     * Create a new PhpRedis connection instance.
     *
     * @param array<string, mixed> $config
     */
    public function __construct(Container $container, PoolInterface $pool, array $config)
    {
        parent::__construct($container, $pool, $config);

        $this->reconnect();
    }

    /**
     * Reconnect to Redis.
     *
     * @throws RedisException
     * @throws ConnectionException
     */
    public function reconnect(): bool
    {
        $sentinel = $this->config['sentinel']['enable'] ?? false;

        $redis = $sentinel
            ? $this->createRedisSentinel()
            : $this->createRedis($this->config);

        $this->setOptions($redis);

        $auth = $this->config['password'] ?? null;
        if (isset($auth) && $auth !== '') {
            $username = $this->config['username'] ?? null;
            $redis->auth($username ? [$username, $auth] : $auth);
        }

        $database = $this->database ?? (int) ($this->config['database'] ?? 0);
        if ($database > 0) {
            $redis->select($database);
        }

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
        return false;
    }

    /**
     * Determine if the underlying Redis client is in pipeline/multi mode.
     */
    protected function isQueueingMode(): bool
    {
        return $this->connection instanceof Redis && $this->connection->getMode() !== Redis::ATOMIC;
    }

    /**
     * Flush the selected Redis database.
     */
    protected function callFlushdb(mixed ...$arguments): mixed
    {
        if (strtoupper((string) ($arguments[0] ?? null)) === 'ASYNC') {
            return $this->connection->flushdb(true);
        }

        return $this->connection->flushdb();
    }

    /**
     * Create a redis connection.
     *
     * @param array<string, mixed> $config
     * @throws ConnectionException
     * @throws RedisException
     */
    protected function createRedis(array $config): Redis
    {
        $parameters = [
            $config['host'],
            (int) $config['port'],
            $config['timeout'] ?? 0.0,
            $config['reserved'] ?? null,
            $config['retry_interval'] ?? 0,
            $config['read_timeout'] ?? 0.0,
        ];

        if (! empty($config['context'])) {
            $parameters[] = $config['context'];
        }

        $redis = new Redis;
        if (! $redis->connect(...$parameters)) {
            throw new ConnectionException('Connection reconnect failed.');
        }

        return $redis;
    }

    /**
     * Create a redis sentinel connection.
     *
     * @throws ConnectionException
     */
    protected function createRedisSentinel(): Redis
    {
        try {
            $nodes = $this->config['sentinel']['nodes'] ?? [];
            $timeout = $this->config['timeout'] ?? 0;
            $persistent = $this->config['sentinel']['persistent'] ?? null;
            $retryInterval = $this->config['retry_interval'] ?? 0;
            $readTimeout = $this->config['sentinel']['read_timeout'] ?? 0;
            $masterName = $this->config['sentinel']['master_name'] ?? '';
            $auth = $this->config['sentinel']['auth'] ?? null;

            shuffle($nodes);

            $host = null;
            $port = null;
            foreach ($nodes as $node) {
                try {
                    $resolved = parse_url($node);
                    if (! isset($resolved['host'], $resolved['port'])) {
                        $this->log(sprintf('The redis sentinel node [%s] is invalid.', $node), LogLevel::ERROR);
                        continue;
                    }

                    $options = [
                        'host' => $resolved['host'],
                        'port' => (int) $resolved['port'],
                        'connectTimeout' => $timeout,
                        'persistent' => $persistent,
                        'retryInterval' => $retryInterval,
                        'readTimeout' => $readTimeout,
                        ...($auth ? ['auth' => $auth] : []),
                    ];

                    $sentinel = $this->container->make(RedisSentinelFactory::class)->create($options);
                    $masterInfo = $sentinel->getMasterAddrByName($masterName);
                    if (is_array($masterInfo) && count($masterInfo) >= 2) {
                        [$host, $port] = $masterInfo;
                        break;
                    }
                } catch (Throwable $exception) {
                    $this->log('Redis sentinel connection failed, caused by ' . $exception->getMessage());
                    continue;
                }
            }

            if ($host === null && $port === null) {
                throw new InvalidRedisConnectionException('Connect sentinel redis server failed.');
            }

            $redis = $this->createRedis([
                'host' => $host,
                'port' => $port,
                'timeout' => $timeout,
                'retry_interval' => $retryInterval,
                'read_timeout' => $readTimeout,
            ]);
        } catch (Throwable $exception) {
            throw new ConnectionException('Connection reconnect failed ' . $exception->getMessage());
        }

        return $redis;
    }
}
