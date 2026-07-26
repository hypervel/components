<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Closure;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Redis\Connection as ConnectionContract;
use Hypervel\Contracts\Redis\Factory as FactoryContract;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Limiters\ConcurrencyLimiterBuilder;
use Hypervel\Redis\Limiters\DurationLimiterBuilder;
use Hypervel\Redis\Pool\PoolFactory;
use InvalidArgumentException;
use Throwable;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Redis\RedisProxy
 */
class RedisManager implements FactoryContract, ConnectionContract
{
    /**
     * The resolved connection proxies.
     *
     * @var array<string, RedisProxy>
     */
    protected array $connections = [];

    /**
     * Create a new Redis manager instance.
     */
    public function __construct(
        protected ContainerContract $app,
        protected PoolFactory $factory,
        protected RedisConfig $config,
        protected RedisSentinelFactory $sentinelFactory,
    ) {
    }

    /**
     * Get a Redis connection by name.
     */
    public function connection(UnitEnum|string|null $name = null): RedisProxy
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === '' ? 'default' : $name;

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        // Validate the connection exists in config before creating the proxy.
        // Throws InvalidArgumentException if the name is not configured.
        $this->config->connectionConfig($name);

        return $this->connections[$name] = new RedisProxy(
            $this->factory,
            $name,
            $this->sentinelFactory,
        );
    }

    /**
     * Disconnect the given connection and remove from local cache.
     *
     * Discards any context-pinned connection and flushes the underlying pool
     * so all connections are closed and re-created on next use.
     *
     * Boot or tests only. Flushes the shared pool; concurrent coroutines
     * checked out before this call may complete against the destroyed pool
     * and other coroutines lose their cached proxy reference.
     */
    public function purge(UnitEnum|string|null $name = null): void
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name = $name === null || $name === '' ? 'default' : $name;

        $proxy = $this->connections[$name] ?? null;
        unset($this->connections[$name]);

        $poolName = $proxy?->getName() ?? $name;
        $exception = null;

        if ($proxy !== null) {
            try {
                $proxy->discardContextConnection();
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }
        }

        try {
            $this->factory->flushPool($poolName);
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Return all of the created connections.
     *
     * @return array<string, RedisProxy>
     */
    public function connections(): array
    {
        return $this->connections;
    }

    /**
     * Enable Redis command events.
     *
     * Boot-only. Existing pools retain their snapshotted event configuration;
     * calling this after pool creation can leave generations with different behavior.
     */
    public function enableEvents(): void
    {
        $this->config->enableEvents();
    }

    /**
     * Disable Redis command events.
     *
     * Boot-only. Existing pools retain their snapshotted event configuration;
     * calling this after pool creation can leave generations with different behavior.
     */
    public function disableEvents(): void
    {
        $this->config->disableEvents();
    }

    // REMOVED: Connector-driver extend()/setDriver() do not apply to Hypervel's phpredis-only pooled transport.

    /**
     * Release connections retained by non-coroutine task execution.
     *
     * @internal
     */
    public function releaseConnections(): void
    {
        $this->terminateConnections(
            static function (RedisProxy $connection): void {
                $connection->releaseContextConnection();
            },
        );
    }

    /**
     * Discard connections retained by this manager.
     *
     * @internal
     */
    public function discardConnections(): void
    {
        $this->terminateConnections(
            static function (RedisProxy $connection): void {
                $connection->discardContextConnection();
            },
        );
    }

    /**
     * Terminate every created connection proxy.
     *
     * @param Closure(RedisProxy): void $terminate
     */
    protected function terminateConnections(Closure $terminate): void
    {
        $exception = null;

        foreach ($this->connections as $connection) {
            try {
                $terminate($connection);
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Register a Redis command listener with the connection.
     *
     * Boot-only. The listener persists on the singleton event dispatcher for
     * the worker lifetime and runs for every subsequent Redis command event.
     */
    public function listen(Closure $callback): void
    {
        if ($this->app->bound('events')) {
            $this->app->make('events')->listen(CommandExecuted::class, $callback);
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
        if ($this->app->bound('events')) {
            $this->app->make('events')->listen(CommandFailed::class, $callback);
        }
    }

    /**
     * Subscribe to a set of given channels for messages.
     */
    public function subscribe(array|string $channels, Closure $callback): void
    {
        $this->connection()->subscribe($channels, $callback);
    }

    /**
     * Subscribe to a set of given channels with wildcards.
     */
    public function psubscribe(array|string $channels, Closure $callback): void
    {
        $this->connection()->psubscribe($channels, $callback);
    }

    /**
     * Run a command against the Redis database.
     */
    public function command(string $method, array $parameters = []): mixed
    {
        return $this->connection()->command($method, $parameters);
    }

    /**
     * Throttle a callback for a maximum number of executions over a given duration.
     */
    public function throttle(string $name): DurationLimiterBuilder
    {
        return $this->connection()->throttle($name);
    }

    /**
     * Funnel a callback for a maximum number of simultaneous executions.
     */
    public function funnel(string $name): ConcurrencyLimiterBuilder
    {
        return $this->connection()->funnel($name);
    }

    /**
     * Pass methods onto the default Redis connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->connection()->{$method}(...$parameters);
    }
}
