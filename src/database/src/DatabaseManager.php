<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Closure;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Events\ConnectionEstablished;
use Hypervel\Foundation\Contracts\Application;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
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
        protected Application $app,
        protected ConnectionFactory $factory
    ) {
        $this->reconnector = function ($connection) {
            $connection->setPdo(
                $this->reconnect($connection->getNameWithReadWriteType())->getRawPdo()
            );
        };
    }

    /**
     * Get a database connection instance.
     *
     * Delegates to ConnectionResolver for pooled, per-coroutine connection management.
     */
    public function connection(UnitEnum|string|null $name = null): ConnectionInterface
    {
        return $this->app->get(ConnectionResolverInterface::class)
            ->connection(enum_value($name));
    }

    /**
     * Build a database connection instance from the given configuration.
     *
     * @throws RuntimeException Always - dynamic connections not supported in Hypervel
     */
    public function build(array $config): ConnectionInterface
    {
        throw new RuntimeException(
            'Dynamic database connections via DB::build() are not supported in Hypervel. ' .
            'Configure all connections in config/databases.php instead.'
        );
    }

    /**
     * Calculate the dynamic connection name for an on-demand connection based on its configuration.
     */
    public static function calculateDynamicConnectionName(array $config): string
    {
        return 'dynamic_'.md5((new Collection($config))->map(function ($value, $key) {
            return $key.(is_string($value) || is_int($value) ? $value : '');
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
            'Dynamic database connections via DB::connectUsing() are not supported in Hypervel. ' .
            'Configure all connections in config/databases.php instead.'
        );
    }

    /**
     * Parse the connection into an array of the name and read / write type.
     *
     * @return array{0: string, 1: string|null}
     */
    protected function parseConnectionName(string $name): array
    {
        return Str::endsWith($name, ['::read', '::write'])
            ? explode('::', $name, 2)
            : [$name, null];
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

        return (new ConfigurationUrlParser)
            ->parseConfiguration($config);
    }

    /**
     * Prepare the database connection instance.
     */
    protected function configure(Connection $connection, ?string $type): Connection
    {
        $connection = $this->setPdoForType($connection, $type)->setReadWriteType($type);

        // First we'll set the fetch mode and a few other dependencies of the database
        // connection. This method basically just configures and prepares it to get
        // used by the application. Once we're finished we'll return it back out.
        if ($this->app->bound('events')) {
            $connection->setEventDispatcher($this->app['events']);
        }

        if ($this->app->bound(DatabaseTransactionsManager::class)) {
            $connection->setTransactionManager($this->app->get(DatabaseTransactionsManager::class));
        }

        // Here we'll set a reconnector callback. This reconnector can be any callable
        // so we will set a Closure to reconnect from this manager with the name of
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
     * Prepare the read / write mode for database connection instance.
     */
    protected function setPdoForType(Connection $connection, ?string $type = null): Connection
    {
        if ($type === 'read') {
            $connection->setPdo($connection->getReadPdo());
        } elseif ($type === 'write') {
            $connection->setReadPdo($connection->getPdo());
        }

        return $connection;
    }

    /**
     * Disconnect from the given database and remove from local cache.
     */
    public function purge(UnitEnum|string|null $name = null): void
    {
        $this->disconnect($name = enum_value($name) ?: $this->getDefaultConnection());

        unset($this->connections[$name]);
    }

    /**
     * Disconnect from the given database.
     */
    public function disconnect(UnitEnum|string|null $name = null): void
    {
        if (isset($this->connections[$name = enum_value($name) ?: $this->getDefaultConnection()])) {
            $this->connections[$name]->disconnect();
        }
    }

    /**
     * Reconnect to the given database.
     */
    public function reconnect(UnitEnum|string|null $name = null): Connection
    {
        $this->disconnect($name = enum_value($name) ?: $this->getDefaultConnection());

        if (! isset($this->connections[$name])) {
            return $this->connection($name);
        }

        return tap($this->refreshPdoConnections($name), function ($connection) {
            $this->dispatchConnectionEstablishedEvent($connection);
        });
    }

    /**
     * Set the default database connection for the callback execution.
     */
    public function usingConnection(UnitEnum|string $name, callable $callback): mixed
    {
        $previousName = $this->getDefaultConnection();

        $this->setDefaultConnection($name = enum_value($name));

        try {
            return $callback();
        } finally {
            $this->setDefaultConnection($previousName);
        }
    }

    /**
     * Refresh the PDO connections on a given connection.
     */
    protected function refreshPdoConnections(string $name): Connection
    {
        [$database, $type] = $this->parseConnectionName($name);

        $fresh = $this->configure(
            $this->makeConnection($database), $type
        );

        return $this->connections[$name]
            ->setPdo($fresh->getRawPdo())
            ->setReadPdo($fresh->getRawReadPdo());
    }

    /**
     * Get the default connection name.
     */
    public function getDefaultConnection(): string
    {
        return $this->app['config']['database.default'];
    }

    /**
     * Set the default connection name.
     */
    public function setDefaultConnection(string $name): void
    {
        $this->app['config']['database.default'] = $name;
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

        return $this->connection()->$method(...$parameters);
    }
}
