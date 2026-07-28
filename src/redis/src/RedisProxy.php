<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use BadMethodCallException;
use Closure;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Redis\Connection as ConnectionContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Exceptions\InvalidRedisConnectionException;
use Hypervel\Redis\Limiters\ConcurrencyLimiterBuilder;
use Hypervel\Redis\Limiters\DurationLimiterBuilder;
use Hypervel\Redis\Pool\PoolFactory;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Redis\Traits\MultiExec;
use Hypervel\Support\Arr;
use Redis;
use Throwable;

/**
 * Pool-aware Redis connection proxy.
 *
 * Each proxy represents a named Redis connection. Commands are executed by
 * checking out a connection from the pool, running the command, and releasing
 * the connection back. Context management ensures that stateful commands reuse
 * the same connection within a coroutine.
 *
 * @method bool|Redis discard()
 * @mixin \Hypervel\Redis\RedisConnection
 */
class RedisProxy implements ConnectionContract
{
    use MultiExec;

    /**
     * Context key prefix for per-connection pool state.
     */
    public const CONNECTION_CONTEXT_PREFIX = '__redis.connection.';

    /**
     * Context key prefix for the coroutine owning deferred pool cleanup.
     */
    private const DEFERRED_RELEASE_OWNER_CONTEXT_KEY_PREFIX = '__redis.deferred_release_owner.';

    /**
     * Methods that must be called while explicitly holding a pool connection.
     *
     * These methods must remain excluded from Redis facade generation.
     */
    private const CONNECTION_BOUND_METHODS = [
        'auth',
        'check',
        'client',
        'clearwatchstate',
        'close',
        'connect',
        'discardtransaction',
        'getactiveconnection',
        'getconnection',
        'getcreatedat',
        'getlastreleasetime',
        'getlastusetime',
        'getshouldtransform',
        'heartbeatcheck',
        'isidleexpired',
        'islifetimeexpired',
        'masters',
        'pconnect',
        'reconnect',
        'release',
        'safescan',
        'setdatabase',
        'setoption',
        'shouldtransform',
    ];

    /**
     * Create a new Redis proxy instance.
     */
    public function __construct(
        protected PoolFactory $factory,
        protected string $poolName,
        protected RedisSentinelFactory $sentinelFactory,
    ) {
    }

    /**
     * Get the connection name.
     */
    public function getName(): string
    {
        return $this->poolName;
    }

    /**
     * Determine if this connection uses Redis Cluster.
     */
    public function isCluster(): bool
    {
        $config = $this->factory->getPool($this->poolName)->getConfig();

        return $config['cluster']['enable'] ?? false;
    }

    /**
     * Register a custom Redis connection macro.
     *
     * Boot-only. Macros persist for the worker lifetime and affect every Redis connection.
     */
    public function macro(string $name, callable|object $macro): void
    {
        RedisConnection::macro($name, $macro);
    }

    /**
     * Mix another object into the Redis connection.
     *
     * Boot-only. Registered macros persist for the worker lifetime and affect every Redis connection.
     */
    public function mixin(object $mixin, bool $replace = true): void
    {
        RedisConnection::mixin($mixin, $replace);
    }

    /**
     * Determine if a Redis connection macro is registered.
     */
    public function hasMacro(string $name): bool
    {
        return RedisConnection::hasMacro($name);
    }

    /**
     * Flush the registered Redis connection macros.
     *
     * Boot or tests only. Concurrent coroutines may resolve different methods during a flush.
     */
    public function flushMacros(): void
    {
        RedisConnection::flushMacros();
    }

    /**
     * Scan keys matching a pattern.
     *
     * @param mixed $cursor
     * @param mixed ...$arguments
     */
    public function scan($cursor, ...$arguments)
    {
        return $this->__call('scan', [$cursor, ...$arguments]);
    }

    /**
     * Scan hash fields matching a pattern.
     *
     * @param mixed $key
     * @param mixed $cursor
     * @param mixed ...$arguments
     */
    public function hScan($key, $cursor, ...$arguments)
    {
        return $this->__call('hScan', [$key, $cursor, ...$arguments]);
    }

    /**
     * Scan sorted set members matching a pattern.
     *
     * @param mixed $key
     * @param mixed $cursor
     * @param mixed ...$arguments
     */
    public function zScan($key, $cursor, ...$arguments)
    {
        return $this->__call('zScan', [$key, $cursor, ...$arguments]);
    }

    /**
     * Scan set members matching a pattern.
     *
     * @param mixed $key
     * @param mixed $cursor
     * @param mixed ...$arguments
     */
    public function sScan($key, $cursor, ...$arguments)
    {
        return $this->__call('sScan', [$key, $cursor, ...$arguments]);
    }

    /**
     * Pass dynamic method calls to a pooled Redis connection.
     * @param mixed $name
     * @param mixed $arguments
     */
    public function __call($name, $arguments)
    {
        $command = strtolower($name);

        if (in_array($command, ['subscribe', 'psubscribe'], true)) {
            return $this->handleSubscribe($command, $arguments); // @phpstan-ignore method.void
        }

        if (in_array($command, self::CONNECTION_BOUND_METHODS, true)) {
            throw new BadMethodCallException(sprintf(
                'Redis connection method [%s] must be called on a held Redis connection. Use Redis::withConnection(...) or Redis::withPinnedConnection(...).',
                $name
            ));
        }

        $hasContextConnection = CoroutineContext::has($this->getContextKey());
        $connection = $this->getConnection($hasContextConnection);

        $start = hrtime(true) / 1e9;
        $result = null;
        $commandException = null;
        $eventException = null;
        $cleanupException = null;

        try {
            /** @var RedisConnection $connection */
            $connection = $connection->getConnection();
            $result = $command === 'discard'
                ? $connection->discardTransaction()
                : $connection->{$name}(...$arguments);
        } catch (Throwable $throwable) {
            $commandException = $throwable;

            try {
                if ($connection->getEventDispatcher()?->hasListeners(CommandFailed::class)) {
                    $time = round((hrtime(true) / 1e9 - $start) * 1000, 2);
                    $connection->getEventDispatcher()->dispatch(
                        new CommandFailed($name, $arguments, $throwable, $connection, $time)
                    );
                }
            } catch (Throwable $throwable) {
                $eventException = $throwable;
            }
        }

        if ($commandException === null) {
            try {
                if ($connection->getEventDispatcher()?->hasListeners(CommandExecuted::class)) {
                    $time = round((hrtime(true) / 1e9 - $start) * 1000, 2);
                    $connection->getEventDispatcher()->dispatch(
                        new CommandExecuted($name, $arguments, $time, $connection)
                    );
                }
            } catch (Throwable $throwable) {
                $eventException = $throwable;
            }
        }

        try {
            if ($hasContextConnection) {
                // Connection is already in context, don't release
            } elseif ($commandException === null && $this->shouldUseSameConnection($command)) {
                // On success with same-connection command: store in context for reuse
                if ($command === 'select' && array_key_exists(0, $arguments)) {
                    $connection->setDatabase((int) $arguments[0]);
                }

                CoroutineContext::set($this->getContextKey(), $connection);

                $coroutineId = Coroutine::id();

                if ($coroutineId > 0) {
                    $deferredReleaseOwnerContextKey = $this->getDeferredReleaseOwnerContextKey();

                    if (CoroutineContext::get($deferredReleaseOwnerContextKey) !== $coroutineId) {
                        Coroutine::defer(function (): void {
                            $this->releaseContextConnection();
                        });

                        CoroutineContext::set($deferredReleaseOwnerContextKey, $coroutineId);
                    }
                }
            } else {
                // Release the connection
                $connection->release();
            }
        } catch (Throwable $throwable) {
            $cleanupException = $throwable;
        }

        if ($eventException !== null) {
            throw $eventException;
        }

        if ($commandException !== null) {
            throw $commandException;
        }

        if ($cleanupException !== null) {
            throw $cleanupException;
        }

        return $result;
    }

    /**
     * Release the connection stored in coroutine context.
     *
     * @internal
     */
    public function releaseContextConnection(): void
    {
        $contextKey = $this->getContextKey();
        $connection = CoroutineContext::get($contextKey);

        CoroutineContext::forget($contextKey);

        if ($connection instanceof RedisConnection) {
            $connection->release();
        }
    }

    /**
     * Discard the connection stored in coroutine context.
     *
     * @internal
     */
    public function discardContextConnection(): void
    {
        $contextKey = $this->getContextKey();
        $connection = CoroutineContext::get($contextKey);

        CoroutineContext::forget($contextKey);

        if ($connection instanceof RedisConnection) {
            $connection->discard();
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
     * Determine which commands must reuse the same connection.
     */
    protected function shouldUseSameConnection(string $methodName): bool
    {
        return in_array($methodName, [
            'multi',
            'pipeline',
            'select',
            'watch',
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
            ? CoroutineContext::get($this->getContextKey())
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
        return self::CONNECTION_CONTEXT_PREFIX . $this->poolName;
    }

    /**
     * Get the context key for this coroutine's deferred release owner.
     */
    private function getDeferredReleaseOwnerContextKey(): string
    {
        return self::DEFERRED_RELEASE_OWNER_CONTEXT_KEY_PREFIX . $this->poolName;
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
        $hasContextConnection = CoroutineContext::has($this->getContextKey());
        $connection = $this->getConnection($hasContextConnection, $transform);

        try {
            $connection->getConnection();

            return $callback($connection);
        } finally {
            if (! $hasContextConnection) {
                $connection->release();
            }
        }
    }

    /**
     * Pin a pool connection in coroutine context for the duration of a callback.
     *
     * All Redis operations inside the callback will reuse the same connection,
     * avoiding multiple pool checkouts. Re-entrant: if a connection is already
     * pinned, the callback runs on it without re-pinning.
     */
    public function withPinnedConnection(callable $callback): mixed
    {
        $contextKey = $this->getContextKey();
        $hadContextConnection = CoroutineContext::has($contextKey);
        $connection = $this->getConnection($hadContextConnection);

        if (! $hadContextConnection) {
            CoroutineContext::set($contextKey, $connection);
        }

        try {
            $connection->getConnection();

            return $callback();
        } finally {
            if (! $hadContextConnection) {
                CoroutineContext::forget($contextKey);
                $connection->release();
            }
        }
    }

    /**
     * Execute a callback with serialization and compression temporarily disabled.
     *
     * Pins a pool connection and disables phpredis serialization and compression
     * for the duration of the callback, then restores settings and releases.
     *
     * This is needed for rate limiter counters which must be stored as raw
     * integers — phpredis serialization (e.g., igbinary) would corrupt them.
     */
    public function withoutSerializationOrCompression(callable $callback): mixed
    {
        return $this->withPinnedConnection(function () use ($callback) {
            $contextKey = $this->getContextKey();
            $connection = CoroutineContext::get($contextKey);

            return $connection->withoutSerializationOrCompression($callback);
        });
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
        $pool = $this->factory->getPool($this->poolName);
        $config = $pool->getConfig();

        if ($config['sentinel']['enable'] ?? false) {
            [$host, $port] = $this->sentinelFactory->resolveMaster($config);

            return $this->createSubscriber(
                $config,
                $host,
                $port,
                $config['scheme'] ?? null,
                $config['context'] ?? [],
            );
        }

        if (! ($config['cluster']['enable'] ?? false)) {
            return $this->createSubscriber(
                $config,
                $config['host'],
                (int) $config['port'],
                $config['scheme'] ?? null,
                $config['context'] ?? [],
            );
        }

        $connection = $pool->get();
        $discoveryException = null;
        $masters = [];

        try {
            if (! $connection instanceof PhpRedisClusterConnection) {
                throw new InvalidRedisConnectionException(
                    'The Redis Cluster pool returned an invalid connection.'
                );
            }

            $connection->getConnection();
            $masters = $connection->masters();
        } catch (Throwable $exception) {
            $discoveryException = $exception;
        }

        try {
            $connection->release();
        } catch (Throwable $exception) {
            if ($discoveryException === null) {
                throw $exception;
            }
        }

        if ($discoveryException !== null) {
            throw $discoveryException;
        }

        $context = $config['cluster']['context'] ?? [];
        $failures = [];

        foreach ($masters as $master) {
            if (! is_array($master)
                || ! isset($master[0], $master[1])
                || ! is_string($master[0])
                || $master[0] === ''
                || (! is_int($master[1]) && ! (is_string($master[1]) && ctype_digit($master[1])))) {
                $failures[] = '[invalid master]: invalid endpoint';
                continue;
            }

            try {
                return $this->createSubscriber(
                    $config,
                    $master[0],
                    (int) $master[1],
                    null,
                    $context,
                );
            } catch (Throwable $exception) {
                $failures[] = sprintf(
                    '[%s:%d]: %s',
                    $master[0],
                    $master[1],
                    $exception->getMessage(),
                );
            }
        }

        throw new InvalidRedisConnectionException(sprintf(
            'Unable to connect a Redis subscriber to any Cluster master: %s.',
            implode('; ', $failures),
        ));
    }

    /**
     * Create a dedicated subscriber from a resolved endpoint.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     */
    private function createSubscriber(
        array $config,
        string $host,
        int $port,
        ?string $scheme,
        array $context,
    ): Subscriber {
        /** @var null|array|string $password */
        $password = $config['password'] ?? null;

        /** @var null|string $username */
        $username = $config['username'] ?? null;

        return new Subscriber(
            host: $host,
            port: $port,
            password: $password,
            timeout: (float) ($config['timeout'] ?? 5.0),
            prefix: (string) (($config['options'] ?? [])['prefix'] ?? ''),
            username: $username,
            scheme: $scheme,
            context: $context,
        );
    }

    /**
     * Register a Redis command listener with the connection.
     *
     * Boot-only. The listener persists on the singleton event dispatcher for
     * the worker lifetime and runs for every subsequent Redis command event.
     */
    public function listen(Closure $callback): void
    {
        $container = Container::getInstance();

        if ($container->bound('events')) {
            $container->make('events')->listen(CommandExecuted::class, $callback);
        }
    }

    /**
     * Register a Redis command failure listener with the connection.
     *
     * Boot-only. The listener persists on the singleton event dispatcher for
     * the worker lifetime and runs for every subsequent Redis failure event.
     */
    public function listenForFailures(Closure $callback): void
    {
        $container = Container::getInstance();

        if ($container->bound('events')) {
            $container->make('events')->listen(CommandFailed::class, $callback);
        }
    }

    /**
     * Subscribe to a set of given channels for messages.
     */
    public function subscribe(array|string $channels, Closure $callback): void
    {
        $this->__call('subscribe', [$channels, $callback]);
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe(array|string $channels, Closure $callback): void
    {
        $this->__call('psubscribe', [$channels, $callback]);
    }

    /**
     * Run a command against the Redis database.
     */
    public function command(string $method, array $parameters = []): mixed
    {
        return $this->__call($method, $parameters);
    }

    /**
     * Throttle a callback for a maximum number of executions over a given duration.
     */
    public function throttle(string $name): DurationLimiterBuilder
    {
        return new DurationLimiterBuilder($this, $name);
    }

    /**
     * Funnel a callback for a maximum number of simultaneous executions.
     */
    public function funnel(string $name): ConcurrencyLimiterBuilder
    {
        return new ConcurrencyLimiterBuilder($this, $name);
    }

    /**
     * Flush (delete) all Redis keys matching a pattern.
     *
     * Use this for standalone/one-off flush operations. It handles the connection
     * lifecycle automatically (get from pool, flush, release). Uses the default
     * connection, or specify one via Redis::connection($name)->flushByPattern().
     *
     * If you already have a raw connection from
     * withConnection(..., transform: false), call
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
