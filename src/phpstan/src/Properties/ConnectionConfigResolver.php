<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\Database\Concerns\ParsesSearchPath;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Connectors\ConnectionFactory;
use Hypervel\Database\Connectors\Connector;
use Hypervel\Database\Migrations\Migrator;
use Hypervel\Database\Schema\Builder;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\Support\Str;
use InvalidArgumentException;
use PDO;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;

use function array_combine;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_replace;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function serialize;

/**
 * Resolves the configuration facts of the booted application's database connections.
 *
 * The facts are deterministic: every record a connection can select is read, never
 * the random selection a running pool makes.
 *
 * @internal
 */
#[AutowiredService]
class ConnectionConfigResolver
{
    use HasContainer;
    use ParsesSearchPath;

    /** The PDO options that change the values a query fetches. */
    private const array FETCH_OPTIONS = [
        PDO::ATTR_CASE => true,
        PDO::ATTR_ORACLE_NULLS => true,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ];

    /** @var array<string, null|ConnectionConfig> */
    private array $connections = [];

    /** @var array<string, true> the config keys read, whose sources the schema facts depend on */
    private array $consumedKeys = [];

    /** @var array<string, array{bool, null|false|string}> each config key read as a connection name, with its state */
    private array $configuredNames = [];

    /** @var array<string, array{bool, ?bool}> each guard config key, with its presence and boolean value */
    private array $configuredBooleans = [];

    /** @var array<string, array{service: class-string, result: array{bool, ?string}}> each mapped migration class used, with the service and result of its callback */
    private array $resolvedConnections = [];

    /**
     * Create a connection configuration resolver.
     *
     * @param array<string, string> $migrationConnections
     */
    public function __construct(
        #[AutowiredParameter]
        private array $migrationConnections,
    ) {
    }

    /**
     * Get the facts of a connection name, or null when its configuration cannot be resolved.
     */
    public function connection(string $name): ?ConnectionConfig
    {
        if (array_key_exists($name, $this->connections)) {
            return $this->connections[$name];
        }

        return $this->connections[$name] = $this->resolveConnection($name);
    }

    /**
     * Get the name of the default connection.
     */
    public function getDefaultConnectionName(): ?string
    {
        $name = $this->config('database.default');

        return is_string($name) ? $name : null;
    }

    /**
     * Get the names of the configured connections.
     *
     * @return list<string>
     */
    public function getConnectionNames(): array
    {
        $connections = $this->config('database.connections');

        return is_array($connections) ? array_map('strval', array_keys($connections)) : [];
    }

    /**
     * Get whether a config key holding a connection name is present, and its value when it
     * is a string or null, or false for any other value.
     *
     * @return array{bool, null|false|string}
     */
    public function getConfiguredName(string $key): array
    {
        $value = $this->config($key);

        return $this->configuredNames[$key] = [
            $this->getContainer()->make('config')->has($key),
            is_string($value) || $value === null ? $value : false,
        ];
    }

    /**
     * Get each config key read as a connection name, with its state.
     *
     * @return array<string, array{bool, null|false|string}>
     */
    public function getConfiguredNames(): array
    {
        return $this->configuredNames;
    }

    /**
     * Read a migration guard's config key, preserving the distinction between missing and non-boolean.
     *
     * @return array{bool, ?bool}
     */
    public function getConfiguredBoolean(string $key): array
    {
        $value = $this->config($key);

        return $this->configuredBooleans[$key] = [
            $this->getContainer()->make('config')->has($key),
            is_bool($value) ? $value : null,
        ];
    }

    /**
     * Get the config values read by migration guards.
     *
     * @return array<string, array{bool, ?bool}>
     */
    public function getConfiguredBooleans(): array
    {
        return $this->configuredBooleans;
    }

    /**
     * Resolve a mapped migration getter once, returning null when it has no mapping.
     *
     * @return null|array{bool, ?string}
     */
    public function resolveMigrationConnection(string $class): ?array
    {
        if (! isset($this->migrationConnections[$class])) {
            return null;
        }

        if (! isset($this->resolvedConnections[$class])) {
            [$service, $method] = Str::parseCallback($this->migrationConnections[$class]);

            if ($method === null || $method === '') {
                throw new InvalidArgumentException("The migrationConnections entry for [{$class}] must specify a Class@method callback.");
            }

            $instance = $this->getContainer()->make($service);
            $name = $instance->{$method}();
            $this->resolvedConnections[$class] = [
                'service' => $instance::class,
                'result' => [is_string($name) || $name === null, is_string($name) ? $name : null],
            ];
        }

        return $this->resolvedConnections[$class]['result'];
    }

    /**
     * Get the connection callbacks used during aggregation and the classes that supplied their results.
     *
     * @return array<string, array{service: class-string, result: array{bool, ?string}}>
     */
    public function getResolvedConnections(): array
    {
        return $this->resolvedConnections;
    }

    /**
     * Get the config keys read so far.
     *
     * @return list<string>
     */
    public function getConsumedKeys(): array
    {
        return array_keys($this->consumedKeys);
    }

    /**
     * Get the normalized configuration and boot state that schema aggregation reads.
     *
     * Fetch options and custom resolvers only affect lookups, so changing them keeps the aggregated schema.
     *
     * @return array<string, mixed>
     */
    public function getSchemaConfiguration(): array
    {
        $names = $this->getConnectionNames();

        return [
            'default' => $this->getDefaultConnectionName(),
            'connections' => array_combine($names, array_map(function (string $name): ?array {
                $connection = $this->connection($name);

                return $connection === null ? null : [
                    $connection->owner,
                    $connection->driver,
                    $connection->prefix,
                    $connection->defaultSchema,
                    $connection->searchPath,
                    $connection->nativeJson,
                    $connection->nativeJsonb,
                ];
            }, $names)),
            'morphKeyType' => Builder::$defaultMorphKeyType,
            'migrationConnections' => $this->migrationConnections,
        ];
    }

    /**
     * Resolve the facts of a connection name.
     */
    private function resolveConnection(string $name): ?ConnectionConfig
    {
        try {
            $connectionName = ConnectionName::parse($name);
            $owner = Migrator::resolveMigrationConnectionName($connectionName->base);
        } catch (InvalidArgumentException) {
            return null;
        }

        $config = $this->config("database.connections.{$connectionName->base}");

        if (! is_array($config)) {
            return null;
        }

        /** @var ConnectionFactory $factory */
        $factory = $this->getContainer()->make('db.factory');
        $config = $factory->parseConfig($config, $connectionName->base);
        $records = $this->getRecords($factory, $config, $connectionName);
        $identities = array_unique(array_map(static fn (array $record): string => serialize([
            $record['driver'] ?? null,
            $record['prefix'] ?? null,
            $record['database'] ?? null,
            $record['search_path'] ?? $record['schema'] ?? null,
            $record['username'] ?? null,
            (bool) ($record['use_native_json'] ?? false),
            (bool) ($record['use_native_jsonb'] ?? false),
        ]), $records));

        // Lookups cannot tell which of several differing endpoints a query reaches.
        if (count($identities) !== 1 || ! is_string($driver = $records[0]['driver'] ?? null)) {
            return null;
        }

        $record = $records[0];
        $customized = $factory->getExtension($config, $connectionName->base) !== null;
        $fetchOptions = [];

        try {
            $connector = $factory->createConnector($record);
        } catch (InvalidArgumentException) {
            $connector = null;
        }

        if ($connector instanceof Connector) {
            foreach ($records as $endpoint) {
                $options = array_intersect_key($connector->getOptions($endpoint), self::FETCH_OPTIONS);

                if (! in_array($options, $fetchOptions, true)) {
                    $fetchOptions[] = $options;
                }
            }
        } else {
            $customized = true;
        }

        return new ConnectionConfig(
            owner: $owner,
            driver: $driver,
            prefix: (string) $record['prefix'],
            defaultSchema: match ($driver) {
                'mysql', 'mariadb' => is_string($record['database'] ?? null) ? $record['database'] : null,
                'sqlite' => 'main',
                default => null,
            },
            searchPath: $driver === 'pgsql' ? $this->getSearchPath($record) : [],
            nativeJson: (bool) ($record['use_native_json'] ?? false),
            nativeJsonb: (bool) ($record['use_native_jsonb'] ?? false),
            fetchOptions: $fetchOptions,
            customized: $customized,
        );
    }

    /**
     * Get every endpoint configuration a connection name can select.
     *
     * A base name reads from either side, so it can reach every read and write record.
     *
     * @param array<string, mixed> $config
     *
     * @return non-empty-list<array<string, mixed>>
     */
    private function getRecords(ConnectionFactory $factory, array $config, ConnectionName $name): array
    {
        if (! $factory->hasReadConfig($config)) {
            return [$config];
        }

        $roles = match (true) {
            $name->isRead() => ['read'],
            $name->isWrite() => ['write'],
            default => ['read', 'write'],
        };

        $records = [];

        // Mirrors DatabasePool::roleEndpoints(), which builds each record a role may select.
        foreach ($roles as $role) {
            foreach (isset($config[$role][0]) ? $config[$role] : [$config[$role]] as $record) {
                $endpoint = array_replace($config, [$role => $record]);
                $records[] = $role === 'read' ? $factory->configForRead($endpoint) : $factory->configForWrite($endpoint);
            }
        }

        return $records;
    }

    /**
     * Get the schemas an unqualified name resolves through, as PostgresBuilder::getCurrentSchemaListing() reads them.
     *
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    private function getSearchPath(array $record): array
    {
        return array_values(array_map(
            static fn (string $schema): string => $schema === '$user' ? (string) ($record['username'] ?? '') : $schema,
            $this->parseSearchPath($record['search_path'] ?? $record['schema'] ?? 'public'),
        ));
    }

    /**
     * Read a config key from the booted application, recording it as consumed.
     */
    private function config(string $key): mixed
    {
        $this->consumedKeys[$key] = true;

        return $this->getContainer()->make('config')->get($key);
    }
}
