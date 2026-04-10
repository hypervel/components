<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Redis\Connection as ConnectionContract;
use Hypervel\Contracts\Redis\Factory as FactoryContract;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Limiters\ConcurrencyLimiterBuilder;
use Hypervel\Redis\Limiters\DurationLimiterBuilder;
use Hypervel\Redis\Pool\PoolFactory;
use InvalidArgumentException;
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
     * The registered custom connection creators.
     *
     * @var array<string, callable>
     */
    protected array $customCreators = [];

    /**
     * Create a new Redis manager instance.
     */
    public function __construct(
        protected ContainerContract $app,
        protected PoolFactory $factory,
        protected RedisConfig $config
    ) {
    }

    /**
     * Get a Redis connection by name.
     */
    public function connection(UnitEnum|string|null $name = null): RedisProxy
    {
        $name = enum_value($name) ?? 'default';

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        if (isset($this->customCreators[$name])) {
            return $this->connections[$name] = call_user_func(
                $this->customCreators[$name],
                $this->app,
                $name
            );
        }

        // Validate the connection exists in config before creating the proxy.
        // Throws InvalidArgumentException if the name is not configured.
        $this->config->connectionConfig($name);

        return $this->connections[$name] = new RedisProxy($this->factory, $name);
    }

    /**
     * Disconnect the given connection and remove from local cache.
     *
     * Releases any context-pinned connection, clears the coroutine context
     * entry, and flushes the underlying pool so all connections are closed
     * and re-created on next use.
     */
    public function purge(UnitEnum|string|null $name = null): void
    {
        $name = enum_value($name) ?? 'default';

        unset($this->connections[$name]);

        // Release any context-pinned connection before clearing context.
        // The coroutine defer in RedisProxy::__call() looks up the connection
        // from context to release it — if we forget the key first, the defer
        // finds null and the checked-out pool slot is leaked.
        $contextKey = RedisProxy::CONNECTION_CONTEXT_PREFIX . $name;
        $connection = CoroutineContext::get($contextKey);
        if ($connection instanceof RedisConnection) {
            $connection->release();
        }
        CoroutineContext::forget($contextKey);

        $this->factory->flushPool($name);
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
     * Register a custom connection creator.
     *
     * The callback receives the container and connection name, and must
     * return a RedisProxy instance (or subclass).
     *
     * @param callable(ContainerContract, string): RedisProxy $resolver
     */
    public function extend(string $name, callable $resolver): static
    {
        $this->customCreators[$name] = $resolver;

        // Invalidate any cached proxy so the next connection() call uses the new creator
        unset($this->connections[$name]);

        return $this;
    }

    /**
     * Remove a custom connection creator.
     */
    public function forgetExtension(string $name): void
    {
        unset($this->customCreators[$name], $this->connections[$name]);

        // Invalidate cached proxy so the next connection() call goes through normal resolution
    }

    /**
     * Register a Redis command listener with the connection.
     */
    public function listen(Closure $callback): void
    {
        if ($this->app->bound('events')) {
            $this->app->make('events')->listen(CommandExecuted::class, $callback);
        }
    }

    /**
     * Register a Redis command failure listener with the connection.
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
