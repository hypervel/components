<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Closure;
use Hypervel\Context\Context;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Database\Connection
 */
class DatabaseManager implements ConnectionResolverInterface
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * The active connection instances.
     *
     * Note: In Hypervel's pooled connection mode, connections are stored
     * per-coroutine in Context, not in this array. This property exists
     * for Laravel API compatibility but is not populated during normal
     * pooled operation.
     *
     * @var array<string, \Hypervel\Database\Connection>
     */
    protected array $connections = [];

    /**
     * The dynamically configured (DB::build) connection configurations.
     *
     * @var array<string, array>
     */
    protected array $dynamicConnectionConfigurations = [];

    /**
     * The custom connection resolvers.
     *
     * @var array<string, callable>
     */
    protected array $extensions = [];

    /**
     * The callback to be executed to reconnect to a database.
     */
    protected Closure $reconnector;

    /**
     * Create a new database manager instance.
     */
    public function __construct(
        protected ContainerContract $app,
        protected ConnectionFactory $factory
    ) {
        $this->reconnector = function ($connection) {
            $connection->setPdo(
                $this->reconnect($connection->getName())->getRawPdo()
            );
        };
    }

    /**
     * Get a database connection instance.
     *
     * Delegates to ConnectionResolver for pooled, per-coroutine connection management.
     * Resolves the default connection name here (checking Context for usingConnection override)
     * before passing to the resolver.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface
    {
        return $this->app->get(ConnectionResolverInterface::class)
            ->connection(enum_value($name) ?? $this->getDefaultConnection());
    }

    /**
     * Resolve a connection directly without using the connection pool.
     *
     * This method is used by SimpleConnectionResolver for testing and Capsule
     * environments where connection pooling is not needed. It manages connections
     * in the $connections array like Laravel's original DatabaseManager.
     *
     * @internal For use by SimpleConnectionResolver only
     */
    public function resolveConnectionDirectly(string $name): ConnectionInterface
    {
        if (! isset($this->connections[$name])) {
            $this->connections[$name] = $this->configure(
                $this->makeConnection($name)
            );

            $this->dispatchConnectionEstablishedEvent($this->connections[$name]);
        }

        return $this->connections[$name];
    }

    /**
     * Build a database connection instance from the given configuration.
     *
     * @throws RuntimeException Always - dynamic connections not supported in Hypervel
     */
    public function build(array $config): ConnectionInterface
    {
        throw new RuntimeException(
            'Dynamic database connections via DB::build() are not supported in Hypervel. '
            . 'Configure all connections in config/database.php instead.'
        );
    }

    /**
     * Calculate the dynamic connection name for an on-demand connection based on its configuration.
     */
    public static function calculateDynamicConnectionName(array $config): string
    {
        return 'dynamic_' . md5((new Collection($config))->map(function ($value, $key) {
            return $key . (is_string($value) || is_int($value) ? $value : '');
        })->implode(''));
    }

    /**
     * Get a database connection instance from the given configuration.
     *
     * @throws RuntimeException Always - dynamic connections not supported in Hypervel
     */
    public function connectUsing(string $name, array $config, bool $force = false): ConnectionInterface
    {
        throw new RuntimeException(
            'Dynamic database connections via DB::connectUsing() are not supported in Hypervel. '
            . 'Configure all connections in config/database.php instead.'
        );
    }

    /**
     * Make the database connection instance.
     */
    protected function makeConnection(string $name): Connection
    {
        $config = $this->configuration($name);

        // First we will check by the connection name to see if an extension has been
        // registered specifically for that connection. If it has we will call the
        // Closure and pass it the config allowing it to resolve the connection.
        if (isset($this->extensions[$name])) {
            return call_user_func($this->extensions[$name], $config, $name);
        }

        // Next we will check to see if an extension has been registered for a driver
        // and will call the Closure if so, which allows us to have a more generic
        // resolver for the drivers themselves which applies to all connections.
        if (isset($this->extensions[$driver = $config['driver']])) {
            return call_user_func($this->extensions[$driver], $config, $name);
        }

        return $this->factory->make($config, $name);
    }

    /**
     * Get the configuration for a connection.
     *
     * @throws InvalidArgumentException
     */
    protected function configuration(string $name): array
    {
        $connections = $this->app['config']['database.connections'];

        $config = $this->dynamicConnectionConfigurations[$name] ?? Arr::get($connections, $name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Database connection [{$name}] not configured.");
        }

        return (new ConfigurationUrlParser())
            ->parseConfiguration($config);
    }

    /**
     * Prepare the database connection instance.
     */
    protected function configure(Connection $connection): Connection
    {
        // Set the event dispatcher if available.
        if ($this->app->bound('events')) {
            $connection->setEventDispatcher($this->app['events']);
        }

        if ($this->app->bound('db.transactions')) {
            $connection->setTransactionManager($this->app->get('db.transactions'));
        }

        // Set a reconnector callback to reconnect from this manager with the name of
        // the connection, which will allow us to reconnect from the connections.
        $connection->setReconnector($this->reconnector);

        return $connection;
    }

    /**
     * Dispatch the ConnectionEstablished event if the event dispatcher is available.
     */
    protected function dispatchConnectionEstablishedEvent(Connection $connection): void
    {
        if (! $this->app->bound('events')) {
            return;
        }

        $this->app['events']->dispatch(
            new ConnectionEstablished($connection)
        );
    }

    /**
     * Disconnect from the given database and flush its pool.
     *
     * In pooled mode, this disconnects the current coroutine's connection,
     * clears its context key (so the next connection() call gets a fresh
     * pooled connection), and flushes the pool. Use this when connection
     * configuration has changed and you need to fully reset.
     *
     * Note: The current coroutine may briefly hold two pooled connections
     * (the old one releases via defer at coroutine end). This is acceptable
     * for purge's intended rare usage.
     */
    public function purge(UnitEnum|string|null $name = null): void
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();

        // Disconnect current connection if any
        $this->disconnect($name);

        // Clear context so next connection() gets a fresh pooled connection
        $contextKey = $this->getConnectionContextKey($name);
        Context::destroy($contextKey);

        // Clear cached connection for SimpleConnectionResolver (non-pooled mode)
        unset($this->connections[$name]);

        // Clear resolver-level caching (e.g., DatabaseConnectionResolver's static cache)
        $resolver = $this->app->get(ConnectionResolverInterface::class);
        if ($resolver instanceof FlushableConnectionResolver) {
            $resolver->flush($name);
        }

        // Flush the pool to honor config changes
        if ($this->app->has(PoolFactory::class)) {
            $this->app->get(PoolFactory::class)->flushPool($name);
        }
    }

    /**
     * Disconnect from the given database.
     *
     * In pooled mode, this nulls the PDOs on the current coroutine's connection
     * (if one exists), forcing a reconnect on the next query. Does not clear
     * context or affect the pool - the connection is still released at coroutine end.
     */
    public function disconnect(UnitEnum|string|null $name = null): void
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();
        $contextKey = $this->getConnectionContextKey($name);

        // Only act if this coroutine already has a connection
        $connection = Context::get($contextKey);
        if ($connection instanceof Connection) {
            $connection->disconnect();
        }
    }

    /**
     * Reconnect to the given database.
     *
     * In pooled mode, if this coroutine already has a connection, reconnects
     * its PDOs and returns it. Otherwise gets a fresh connection from the pool.
     */
    public function reconnect(UnitEnum|string|null $name = null): Connection
    {
        $name = enum_value($name) ?: $this->getDefaultConnection();
        $contextKey = $this->getConnectionContextKey($name);

        // If we already have a connection in this coroutine, reconnect it
        $connection = Context::get($contextKey);
        if ($connection instanceof Connection) {
            $connection->reconnect();
            $this->dispatchConnectionEstablishedEvent($connection);

            return $connection;
        }

        // Otherwise get a fresh one from the pool
        // @phpstan-ignore return.type (connection() returns ConnectionInterface but concrete Connection in practice)
        return $this->connection($name);
    }

    /**
     * Set the default database connection for the callback execution.
     *
     * Uses Context for coroutine-safe state management, ensuring concurrent
     * requests don't interfere with each other's default connection.
     */
    public function usingConnection(UnitEnum|string $name, callable $callback): mixed
    {
        $previous = Context::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);

        Context::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, enum_value($name));

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                Context::destroy(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY);
            } else {
                Context::set(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY, $previous);
            }
        }
    }

    /**
     * Refresh the PDO connections on a given connection.
     */
    protected function refreshPdoConnections(string $name): Connection
    {
        $fresh = $this->configure(
            $this->makeConnection($name)
        );

        return $this->connections[$name]
            ->setPdo($fresh->getRawPdo())
            ->setReadPdo($fresh->getRawReadPdo());
    }

    /**
     * Get the default connection name.
     *
     * Checks Context first for per-coroutine override (from usingConnection()),
     * then falls back to the global config default.
     */
    public function getDefaultConnection(): string
    {
        return Context::get(ConnectionResolver::DEFAULT_CONNECTION_CONTEXT_KEY)
            ?? $this->app['config']['database.default'];
    }

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(string $name): void
    {
        $this->app['config']['database.default'] = $name;
    }

    /**
     * Get the context key for storing a connection.
     *
     * Uses the same format as ConnectionResolver for consistency.
     */
    protected function getConnectionContextKey(string $name): string
    {
        return sprintf('__database.connection.%s', $name);
    }

    /**
     * Get all of the supported drivers.
     *
     * @return string[]
     */
    public function supportedDrivers(): array
    {
        return ['mysql', 'mariadb', 'pgsql', 'sqlite'];
    }

    /**
     * Get all of the drivers that are actually available.
     *
     * @return string[]
     */
    public function availableDrivers(): array
    {
        return array_intersect(
            $this->supportedDrivers(),
            PDO::getAvailableDrivers()
        );
    }

    /**
     * Register an extension connection resolver.
     */
    public function extend(string $name, callable $resolver): void
    {
        $this->extensions[$name] = $resolver;
    }

    /**
     * Remove an extension connection resolver.
     */
    public function forgetExtension(string $name): void
    {
        unset($this->extensions[$name]);
    }

    /**
     * Return all of the created connections.
     *
     * Note: In Hypervel's pooled connection mode, connections are stored
     * per-coroutine in Context rather than in this array. This method
     * returns an empty array in normal pooled operation. Use the pool
     * infrastructure to inspect active connections if needed.
     *
     * @return array<string, Connection>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Set the database reconnector callback.
     */
    public function setReconnector(callable $reconnector): void
    {
        $this->reconnector = $reconnector;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(Application $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Dynamically pass methods to the default connection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->connection()->{$method}(...$parameters);
    }
}
