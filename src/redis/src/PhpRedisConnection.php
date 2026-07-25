<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Pool\PoolInterface;
use Hypervel\Pool\Exceptions\ConnectionException;
use Hypervel\Support\Str;
use InvalidArgumentException;
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
        if ($auth !== null && $auth !== '') {
            $username = $this->config['username'] ?? null;
            $redis->auth(
                $username !== null && $username !== '' && is_string($auth)
                    ? [$username, $auth]
                    : $auth
            );
        }

        $database = $this->database ?? (int) ($this->config['database'] ?? 0);
        if ($database > 0) {
            $redis->select($database);
        }

        $name = $this->config['name'] ?? null;
        if ($name !== null && $name !== '') {
            $redis->client('SETNAME', $name);
        }

        $this->connection = $redis;
        $this->markReconnected();

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
            $this->formatHost($config),
            (int) $config['port'],
            $config['timeout'] ?? 0.0,
            $config['reserved'] ?? null,
            $config['retry_interval'] ?? 0,
            $config['read_timeout'] ?? 0.0,
        ];

        if (! empty($config['context'])) {
            $parameters[] = $this->normalizeContext($config['context']);
        }

        $redis = new Redis;
        if (! $redis->connect(...$parameters)) {
            throw new ConnectionException('Connection reconnect failed.');
        }

        return $redis;
    }

    /**
     * Format the host using the scheme if available.
     *
     * @param array<string, mixed> $config
     */
    protected function formatHost(array $config): string
    {
        $host = $config['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('Redis host must be a non-empty string.');
        }

        $hostScheme = parse_url($host, PHP_URL_SCHEME);

        if (isset($config['scheme'])) {
            if (is_string($hostScheme)) {
                if (strcasecmp($hostScheme, $config['scheme']) !== 0) {
                    throw new InvalidArgumentException(
                        'The scheme configured in the Redis host option must match the scheme option.'
                    );
                }

                return $host;
            }

            return Str::start($host, "{$config['scheme']}://");
        }

        return $host;
    }

    /**
     * Normalize the SSL context for a standalone Redis connection.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function normalizeContext(array $context): array
    {
        if (isset($context['stream'])) {
            return $context;
        }

        if (isset($context['ssl']) && is_array($context['ssl'])) {
            return ['stream' => $context['ssl']];
        }

        return ['stream' => $context];
    }

    /**
     * Create a redis sentinel connection.
     *
     * @throws ConnectionException
     */
    protected function createRedisSentinel(): Redis
    {
        try {
            [$host, $port] = $this->container
                ->make(RedisSentinelFactory::class)
                ->resolveMaster($this->config);

            $redis = $this->createRedis([
                'scheme' => $this->config['scheme'] ?? null,
                'host' => $host,
                'port' => $port,
                'timeout' => $this->config['timeout'] ?? 0,
                'reserved' => $this->config['reserved'] ?? null,
                'retry_interval' => $this->config['retry_interval'] ?? 0,
                'read_timeout' => $this->config['sentinel']['read_timeout'] ?? 0,
                'context' => $this->config['context'] ?? [],
            ]);
        } catch (Throwable $exception) {
            throw new ConnectionException('Connection reconnect failed ' . $exception->getMessage());
        }

        return $redis;
    }
}
