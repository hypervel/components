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
        try {
            $database = ! $this->invalid
                && $this->connection instanceof Redis
                && $this->connection->isConnected()
                ? $this->connection->getDBNum()
                : ($this->database ?? $this->config['database']);

            $sentinel = $this->config['sentinel']['enabled'] ?? false;

            $redis = $sentinel
                ? $this->createRedisSentinel()
                : $this->createRedis($this->config);

            $this->setOptions($redis);

            $auth = $this->config['password'];
            if ($auth !== null && $auth !== '') {
                $username = $this->config['username'];
                $redis->auth(
                    $username !== null && $username !== '' && is_string($auth)
                        ? [$username, $auth]
                        : $auth
                );
            }

            if ($database > 0 && $redis->select($database) !== true) {
                throw new ConnectionException(
                    "Failed to select Redis database [{$database}] on connection [{$this->getName()}]."
                );
            }

            $name = $this->config['name'];
            if ($name !== null && $name !== '') {
                $redis->client('SETNAME', $name);
            }

            $this->connection = $redis;
            $this->database = $database;
            $this->markReconnected();

            if ($this->config['events'] && $this->container->bound('events')) {
                $this->eventDispatcher = $this->container->make('events');
            }

            return true;
        } catch (Throwable $exception) {
            if ($cancellation = RedisCancellation::cancellationFrom(
                $exception,
                'Connecting to Redis was canceled.',
            )) {
                throw $cancellation;
            }

            throw $exception;
        }
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
            $config['port'],
            $config['timeout'],
            null,
            0, // Hypervel applies the complete retry policy through setOptions().
            $config['read_timeout'],
        ];

        if ($config['context'] !== []) {
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
                'scheme' => $this->config['scheme'],
                'host' => $host,
                'port' => $port,
                'timeout' => $this->config['timeout'],
                'read_timeout' => $this->config['read_timeout'],
                'context' => $this->config['context'],
            ]);
        } catch (Throwable $exception) {
            if ($cancellation = RedisCancellation::cancellationFrom(
                $exception,
                'Connecting to Redis through Sentinel was canceled.',
            )) {
                throw $cancellation;
            }

            throw new ConnectionException('Connection reconnect failed ' . $exception->getMessage());
        }

        return $redis;
    }
}
