<?php

declare(strict_types=1);

namespace Hypervel\Database\Connectors;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConfigurationUrlParser;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use LogicException;
use PDO;
use PDOException;

class ConnectionFactory
{
    /**
     * The custom connection resolvers.
     *
     * @var array<string, callable>
     */
    protected array $extensions = [];

    /**
     * Create a new connection factory instance.
     */
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Establish a database connection based on the configuration.
     */
    public function make(array $config, ?string $name = null): Connection
    {
        $config = $this->parseConfig($config, $name);

        // First we will check by the connection name to see if an extension has been
        // registered specifically for that connection. If it has we will call the
        // Closure and pass it the config allowing it to resolve the connection.
        // Next we will check to see if an extension has been registered for a driver
        // and will call the Closure if so, which allows us to have a more generic
        // resolver for the drivers themselves which applies to all connections.
        $driver = $config['driver'] ?? null;
        $resolver = $name !== null && isset($this->extensions[$name])
            ? $this->extensions[$name]
            : ($driver !== null ? $this->extensions[$driver] ?? null : null);

        if ($resolver !== null) {
            $connection = call_user_func($resolver, $config, $name);

            if (! $connection instanceof Connection) {
                throw new InvalidArgumentException('Database connection extensions must return a Connection instance.');
            }

            return $connection;
        }

        return $this->createPdoConnectionFromConfig($config);
    }

    /**
     * Register an extension connection resolver.
     *
     * Boot-only. The resolver persists in the singleton factory's extensions
     * array for the worker lifetime and applies to every subsequent connection.
     */
    public function extend(string $name, callable $resolver): void
    {
        $this->extensions[$name] = $resolver;
    }

    /**
     * Remove an extension connection resolver.
     *
     * Boot or tests only. Mutates the singleton factory's extensions array;
     * concurrent coroutines establishing connections may see the resolver
     * removed mid-resolution.
     */
    public function forgetExtension(string $name): void
    {
        unset($this->extensions[$name]);
    }

    /**
     * Create an in-memory SQLite connection using a shared PDO.
     *
     * Used by connection pooling for in-memory SQLite where all pool slots
     * must share the same PDO instance to see the same data. Without this,
     * each pooled connection would get its own empty in-memory database.
     *
     * Laravel-compatible PDO resolvers may return a custom PdoConnection subclass.
     */
    public function makeSqliteFromSharedPdo(PDO $pdo, array $config, ?string $name = null): PdoConnection
    {
        $config = $this->parseConfig($config, $name);
        $this->ensureNoSharedInMemorySqliteExtension($config);

        // Use write config if read/write is configured, matching normal factory behavior
        $connectionConfig = isset($config['read'])
            ? $this->getWriteConfig($config)
            : $config;

        // Go through createConnection() to respect custom resolvers
        return $this->createConnection(
            'sqlite',
            $pdo,
            $connectionConfig['database'],
            $connectionConfig['prefix'],
            $connectionConfig
        );
    }

    /**
     * Create the initial pooled in-memory SQLite connection.
     */
    public function makeSharedInMemorySqliteConnection(array $config, ?string $name = null): PdoConnection
    {
        $config = $this->parseConfig($config, $name);
        $this->ensureNoSharedInMemorySqliteExtension($config);

        $connectionConfig = isset($config['read'])
            ? $this->getWriteConfig($config)
            : $config;

        return $this->createPdoConnectionFromConfig($connectionConfig);
    }

    /**
     * Parse and prepare the database configuration.
     */
    public function parseConfig(array $config, ?string $name): array
    {
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);

        return Arr::add(Arr::add($config, 'prefix', ''), 'name', $name);
    }

    /**
     * Determine if the configuration has a read side.
     */
    public function hasReadConfig(array $config): bool
    {
        return isset($config[ConnectionName::READ]);
    }

    /**
     * Get the single-role read configuration for a read / write connection.
     */
    public function configForRead(array $config): array
    {
        $name = $config['name'] ?? null;
        $config = $this->parseConfig($config, $name);
        $readConfig = $this->getReadConfig($config);

        return Arr::add(
            $readConfig,
            Connection::READ_WRITE_TYPE_CONFIG_KEY,
            ConnectionName::READ
        );
    }

    /**
     * Create a PDO-backed connection from its configuration.
     */
    protected function createPdoConnectionFromConfig(array $config): PdoConnection
    {
        if (isset($config['read'])) {
            return $this->createReadWriteConnection($config);
        }

        return $this->createSingleConnection($config);
    }

    /**
     * Create a single PDO-backed connection instance.
     */
    protected function createSingleConnection(array $config): PdoConnection
    {
        $pdo = $this->createPdoResolver($config);

        return $this->createConnection(
            $config['driver'],
            $pdo,
            $config['database'],
            $config['prefix'],
            $config
        );
    }

    /**
     * Create a read / write database connection instance.
     */
    protected function createReadWriteConnection(array $config): PdoConnection
    {
        $connection = $this->createSingleConnection($this->getWriteConfig($config));

        return $connection
            ->setReadPdo($this->createReadPdo($config))
            ->setReadPdoConfig($this->getReadConfig($config));
    }

    /**
     * Create a new PDO instance for reading.
     */
    protected function createReadPdo(array $config): Closure
    {
        return $this->createPdoResolver($this->getReadConfig($config));
    }

    /**
     * Get the read configuration for a read / write connection.
     */
    protected function getReadConfig(array $config): array
    {
        return $this->parseConfig(
            $this->mergeReadWriteConfig(
                $config,
                $this->getReadWriteConfig($config, 'read')
            ),
            $config['name'] ?? null,
        );
    }

    /**
     * Get the write configuration for a read / write connection.
     */
    protected function getWriteConfig(array $config): array
    {
        return $this->parseConfig(
            $this->mergeReadWriteConfig(
                $config,
                $this->getReadWriteConfig($config, 'write')
            ),
            $config['name'] ?? null,
        );
    }

    /**
     * Get a read / write level configuration.
     */
    protected function getReadWriteConfig(array $config, string $type): array
    {
        return isset($config[$type][0])
            ? Arr::random($config[$type])
            : $config[$type];
    }

    /**
     * Merge a configuration for a read / write connection.
     */
    protected function mergeReadWriteConfig(array $config, array $merge): array
    {
        return Arr::except(array_merge($config, $merge), ['read', 'write']);
    }

    /**
     * Create a new Closure that resolves to a PDO instance.
     */
    protected function createPdoResolver(array $config): Closure
    {
        return array_key_exists('host', $config)
            ? $this->createPdoResolverWithHosts($config)
            : $this->createPdoResolverWithoutHosts($config);
    }

    /**
     * Create a new Closure that resolves to a PDO instance with a specific host or an array of hosts.
     */
    protected function createPdoResolverWithHosts(array $config): Closure
    {
        return function () use ($config) {
            foreach (Arr::shuffle($this->parseHosts($config)) as $host) {
                $config['host'] = $host;

                try {
                    return $this->createConnector($config)->connect($config);
                } catch (PDOException $e) {
                    continue;
                }
            }

            if (isset($e)) {
                throw $e;
            }
        };
    }

    /**
     * Parse the hosts configuration item into an array.
     *
     * @throws InvalidArgumentException
     */
    protected function parseHosts(array $config): array
    {
        $hosts = Arr::wrap($config['host']);

        if (empty($hosts)) {
            throw new InvalidArgumentException('Database hosts array is empty.');
        }

        return $hosts;
    }

    /**
     * Create a new Closure that resolves to a PDO instance where there is no configured host.
     */
    protected function createPdoResolverWithoutHosts(array $config): Closure
    {
        return fn () => $this->createConnector($config)->connect($config);
    }

    /**
     * Create a connector instance based on the configuration.
     *
     * @throws InvalidArgumentException
     */
    public function createConnector(array $config): ConnectorInterface
    {
        if (! isset($config['driver'])) {
            throw new InvalidArgumentException('A driver must be specified.');
        }

        if ($this->container->bound($key = "db.connector.{$config['driver']}")) {
            return $this->container->make($key);
        }

        return match ($config['driver']) {
            'mysql' => new MySqlConnector,
            'mariadb' => new MariaDbConnector,
            'pgsql' => new PostgresConnector,
            'sqlite' => new SQLiteConnector,
            default => throw new InvalidArgumentException("Unsupported driver [{$config['driver']}]."),
        };
    }

    /**
     * Create a new connection instance.
     *
     * @throws InvalidArgumentException
     */
    protected function createConnection(string $driver, PDO|Closure $connection, string $database, string $prefix = '', array $config = []): PdoConnection
    {
        if ($resolver = Connection::getResolver($driver)) {
            $resolvedConnection = $resolver($connection, $database, $prefix, $config);

            if (! $resolvedConnection instanceof PdoConnection) {
                throw new InvalidArgumentException('PDO connection resolvers must return a PdoConnection instance.');
            }

            return $resolvedConnection;
        }

        return match ($driver) {
            'mysql' => new MySqlConnection($connection, $database, $prefix, $config),
            'mariadb' => new MariaDbConnection($connection, $database, $prefix, $config),
            'pgsql' => new PostgresConnection($connection, $database, $prefix, $config),
            'sqlite' => new SQLiteConnection($connection, $database, $prefix, $config),
            default => throw new InvalidArgumentException("Unsupported driver [{$driver}]."),
        };
    }

    /**
     * Ensure pooled in-memory SQLite uses a PDO connection resolver.
     */
    private function ensureNoSharedInMemorySqliteExtension(array $config): void
    {
        $name = $config['name'] ?? null;

        if (($name !== null && isset($this->extensions[$name]))
            || isset($this->extensions['sqlite'])) {
            throw new LogicException(
                "Pooled in-memory SQLite connections cannot use config-first extensions. Use Connection::resolverFor('sqlite', ...) to register a PDO connection subclass."
            );
        }
    }
}
