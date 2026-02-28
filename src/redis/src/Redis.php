<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Redis\Traits\MultiExec;
use Hypervel\Support\Arr;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Redis\RedisConnection
 */
class Redis
{
    use MultiExec;

    protected string $poolName = 'default';

    public function __construct(
        protected PoolFactory $factory
    ) {
    }

    /**
     * Scan keys matching a pattern.
     * @param mixed $cursor
     */
    public function scan($cursor, ...$arguments)
    {
        return $this->__call('scan', [$cursor, ...$arguments]);
    }

    /**
     * Scan hash fields matching a pattern.
     * @param mixed $key
     * @param mixed $cursor
     */
    public function hScan($key, $cursor, ...$arguments)
    {
        return $this->__call('hScan', [$key, $cursor, ...$arguments]);
    }

    /**
     * Scan sorted set members matching a pattern.
     * @param mixed $key
     * @param mixed $cursor
     */
    public function zScan($key, $cursor, ...$arguments)
    {
        return $this->__call('zScan', [$key, $cursor, ...$arguments]);
    }

    /**
     * Scan set members matching a pattern.
     * @param mixed $key
     * @param mixed $cursor
     */
    public function sScan($key, $cursor, ...$arguments)
    {
        return $this->__call('sScan', [$key, $cursor, ...$arguments]);
    }

    public function __call($name, $arguments)
    {
        if (in_array($name, ['subscribe', 'psubscribe'], true)) {
            return $this->handleSubscribe($name, $arguments);
        }

        $hasContextConnection = Context::has($this->getContextKey());
        $connection = $this->getConnection($hasContextConnection);

        $start = (float) microtime(true);
        $result = null;
        $exception = null;

        try {
            /** @var RedisConnection $connection */
            $connection = $connection->getConnection();
            $result = $connection->{$name}(...$arguments);
        } catch (Throwable $e) {
            $exception = $e;
        } finally {
            $time = round((microtime(true) - $start) * 1000, 2);
            $connection->getEventDispatcher()?->dispatch(
                new CommandExecuted(
                    $name,
                    $arguments,
                    $time,
                    $connection,
                    $this->poolName,
                    $result,
                    $exception,
                )
            );

            if ($hasContextConnection) {
                // Connection is already in context, don't release
            } elseif ($exception === null && $this->shouldUseSameConnection($name)) {
                // On success with same-connection command: store in context for reuse
                if ($name === 'select' && array_key_exists(0, $arguments)) {
                    $connection->setDatabase((int) $arguments[0]);
                }
                Context::set($this->getContextKey(), $connection);
                defer(function () {
                    $this->releaseContextConnection();
                });
            } else {
                // Release the connection
                $connection->release();
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }

    /**
     * Release the connection stored in coroutine context.
     */
    protected function releaseContextConnection(): void
    {
        $contextKey = $this->getContextKey();
        $connection = Context::get($contextKey);

        if ($connection) {
            Context::set($contextKey, null);
            $connection->release();
        }
    }

    /**
     * Handle subscribe/psubscribe using the coroutine-native subscriber.
     *
     * Creates a dedicated socket connection (not from the pool) and bridges
     * the channel-based subscriber to the Laravel-style callback API.
     */
    protected function handleSubscribe(string $name, array $arguments): void
    {
        $channels = Arr::wrap($arguments[0]);
        $callback = $arguments[1];

        $subscriber = $this->subscriber();

        try {
            if ($name === 'subscribe') {
                $subscriber->subscribe(...$channels);
            } else {
                $subscriber->psubscribe(...$channels);
            }

            $channel = $subscriber->channel();
            while ($message = $channel->pop()) {
                $callback($message->payload, $message->channel);
            }
        } finally {
            if (! $subscriber->closed) {
                $subscriber->close();
            }
        }
    }

    /**
     * Define the commands that need same connection to execute.
     * When these commands executed, the connection will storage to coroutine context.
     */
    protected function shouldUseSameConnection(string $methodName): bool
    {
        return in_array($methodName, [
            'multi',
            'pipeline',
            'select',
        ], true);
    }

    /**
     * Get a connection from coroutine context, or from redis connection pool.
     *
     * @param bool $hasContextConnection Whether a connection exists in coroutine context
     * @param bool $transform Whether to enable Laravel-style result transformation
     */
    protected function getConnection(bool $hasContextConnection, bool $transform = true): RedisConnection
    {
        $connection = $hasContextConnection
            ? Context::get($this->getContextKey())
            : null;

        $connection = $connection
            ?: $this->factory->getPool($this->poolName)->get();

        if (! $connection instanceof RedisConnection) {
            throw new InvalidRedisConnectionException('The connection is not a valid RedisConnection.');
        }

        return $connection->shouldTransform($transform);
    }

    /**
     * The key to identify the connection object in coroutine context.
     */
    protected function getContextKey(): string
    {
        return sprintf('__redis.connection.%s', $this->poolName);
    }

    /**
     * Execute callback with a pinned connection from the pool.
     *
     * Use this for operations requiring multiple commands on the same connection
     * (e.g., evalSha + getLastError, multi-step Lua operations). The connection
     * is automatically returned to the pool after the callback completes.
     *
     * If a connection is already stored in coroutine context (e.g., from an
     * active multi/pipeline), that connection is reused and not released.
     *
     * @template T
     * @param callable(RedisConnection): T $callback
     * @param bool $transform Whether to enable Laravel-style result transformation (default: true)
     * @return T
     */
    public function withConnection(callable $callback, bool $transform = true): mixed
    {
        $hasContextConnection = Context::has($this->getContextKey());
        $connection = $this->getConnection($hasContextConnection, $transform);

        try {
            return $callback($connection);
        } finally {
            if (! $hasContextConnection) {
                $connection->release();
            }
        }
    }

    /**
     * Create a coroutine-native Redis subscriber.
     *
     * Returns a Subscriber with its own dedicated socket connection (not from
     * the pool). Use for the channel-based pub/sub API:
     *
     *     $sub = Redis::subscriber();
     *     $sub->subscribe('channel');
     *     while ($message = $sub->channel()->pop()) { ... }
     *     $sub->close();
     */
    public function subscriber(): Subscriber
    {
        $config = $this->factory->getPool($this->poolName)->getConfig();
        $options = $config['options'] ?? [];

        return new Subscriber(
            host: $config['host'],
            port: (int) $config['port'],
            password: (string) ($config['auth'] ?? ''),
            timeout: (float) ($config['timeout'] ?? 5.0),
            prefix: (string) ($options['prefix'] ?? ''),
        );
    }

    /**
     * Get a Redis connection by name.
     */
    public function connection(UnitEnum|string $name = 'default'): RedisProxy
    {
        return Container::getInstance()
            ->make(RedisFactory::class)
            ->get(enum_value($name));
    }

    /**
     * Flush (delete) all Redis keys matching a pattern.
     *
     * Use this for standalone/one-off flush operations. It handles the connection
     * lifecycle automatically (get from pool, flush, release). Uses the default
     * connection, or specify one via Redis::connection($name)->flushByPattern().
     *
     * If you already have a connection (e.g., inside withConnection()), call
     * $connection->flushByPattern() directly to avoid redundant pool operations.
     *
     * Uses SCAN to iterate keys efficiently and deletes them in batches.
     * Correctly handles OPT_PREFIX to avoid the double-prefixing bug.
     *
     * @param string $pattern The pattern to match (e.g., "cache:test:*").
     *                        Should NOT include OPT_PREFIX - it's handled automatically.
     * @return int Number of keys deleted
     */
    public function flushByPattern(string $pattern): int
    {
        return $this->withConnection(
            fn (RedisConnection $connection) => $connection->flushByPattern($pattern),
            transform: false
        );
    }
}
