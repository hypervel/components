<?php

declare(strict_types=1);

namespace Hypervel\Database\Connectors;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\Connection;
use Hypervel\Database\MariaDbConnection;
use Hypervel\Database\MySqlConnection;
use Hypervel\Database\PostgresConnection;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use PDO;
use PDOException;

class ConnectionFactory
{
    /**
     * Create a new connection factory instance.
     */
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Establish a PDO connection based on the configuration.
     */
    public function make(array $config, ?string $name = null): Connection
    {
        $config = $this->parseConfig($config, $name);

        if (isset($config['read'])) {
            return $this->createReadWriteConnection($config);
        }

        return $this->createSingleConnection($config);
    }

    /**
     * Create a connection instance using an existing PDO.
     *
     * Used by connection pooling for in-memory SQLite where all pool slots
     * must share the same PDO instance to see the same data.
     */
    public function makeFromPdo(PDO $pdo, array $config, ?string $name = null): Connection
    {
        $config = $this->parseConfig($config, $name);

        $connection = $this->createConnection(
            $config['driver'],
            $pdo,
            $config['database'],
            $config['prefix'],
            $config
        );

        // If read/write config exists, use the same shared PDO for reads.
        // For in-memory SQLite, read replicas don't make sense anyway.
        // Match the normal read/write path by also setting readPdoConfig.
        if (isset($config['read'])) {
            $connection
                ->setReadPdo($pdo)
                ->setReadPdoConfig($this->getReadConfig($config));
        }

        return $connection;
    }

    /**
     * Parse and prepare the database configuration.
     */
    protected function parseConfig(array $config, ?string $name): array
    {
        return Arr::add(Arr::add($config, 'prefix', ''), 'name', $name);
    }

    /**
     * Create a single database connection instance.
     */
    protected function createSingleConnection(array $config): Connection
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
    protected function createReadWriteConnection(array $config): Connection
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
        return $this->mergeReadWriteConfig(
            $config,
            $this->getReadWriteConfig($config, 'read')
        );
    }

    /**
     * Get the write configuration for a read / write connection.
     */
    protected function getWriteConfig(array $config): array
    {
        return $this->mergeReadWriteConfig(
            $config,
            $this->getReadWriteConfig($config, 'write')
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
            return $this->container->get($key);
        }

        return match ($config['driver']) {
            'mysql' => new MySqlConnector(),
            'mariadb' => new MariaDbConnector(),
            'pgsql' => new PostgresConnector(),
            'sqlite' => new SQLiteConnector(),
            default => throw new InvalidArgumentException("Unsupported driver [{$config['driver']}]."),
        };
    }

    /**
     * Create a new connection instance.
     *
     * @throws InvalidArgumentException
     */
    protected function createConnection(string $driver, PDO|Closure $connection, string $database, string $prefix = '', array $config = []): Connection
    {
        if ($resolver = Connection::getResolver($driver)) {
            return $resolver($connection, $database, $prefix, $config);
        }

        return match ($driver) {
            'mysql' => new MySqlConnection($connection, $database, $prefix, $config),
            'mariadb' => new MariaDbConnection($connection, $database, $prefix, $config),
            'pgsql' => new PostgresConnection($connection, $database, $prefix, $config),
            'sqlite' => new SQLiteConnection($connection, $database, $prefix, $config),
            default => throw new InvalidArgumentException("Unsupported driver [{$driver}]."),
        };
    }
}
