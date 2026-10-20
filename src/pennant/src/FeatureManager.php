<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\ConnectionName;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pennant\Contracts\Driver;
use Hypervel\Pennant\Contracts\FeatureScopeSerializeable;
use Hypervel\Pennant\Drivers\ArrayDriver;
use Hypervel\Pennant\Drivers\DatabaseDriver;
use Hypervel\Pennant\Drivers\Decorator;
use Hypervel\Pennant\Drivers\StoredValueCache;
use Hypervel\Pennant\Exceptions\FeaturePartitionNotResolved;
use InvalidArgumentException;
use RuntimeException;

/**
 * @mixin Decorator
 */
class FeatureManager
{
    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * The array of resolved Pennant stores.
     *
     * @var array<string, Decorator>
     */
    protected array $stores = [];

    /**
     * The registered custom driver creators.
     *
     * @var array<string, Closure>
     */
    protected array $customCreators = [];

    /**
     * The default scope resolver.
     *
     * @var null|(Closure(string): mixed)
     */
    protected ?Closure $defaultScopeResolver = null;

    /**
     * Indicates if the Eloquent "morph map" should be used when serializing.
     */
    protected bool $useMorphMap = false;

    /**
     * The column that partitions stored feature values.
     */
    protected ?string $partitionColumn = null;

    /**
     * The resolver of the current partition value.
     *
     * @var null|(Closure(string): (null|int|string))
     */
    protected ?Closure $partitionResolver = null;

    /**
     * Create a new Pennant manager instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Get a Pennant store instance.
     *
     * @throws InvalidArgumentException
     */
    public function store(?string $store = null): Decorator
    {
        return $this->driver($store);
    }

    /**
     * Get a Pennant store instance by name.
     *
     * @throws InvalidArgumentException
     */
    public function driver(?string $name = null): Decorator
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->stores[$name] = $this->get($name);
    }

    /**
     * Attempt to get the store from the local cache.
     */
    protected function get(string $name): Decorator
    {
        return $this->stores[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given store.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): Decorator
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Pennant store [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            $driver = $this->callCustomCreator($config);
        } else {
            $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';

            if (method_exists($this, $driverMethod)) {
                $driver = $this->{$driverMethod}($config, $name);
            } else {
                throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
            }
        }

        return new Decorator(
            $name,
            $driver,
            $this->defaultScopeResolver($name),
            $this->partitionResolver($name, $config),
            $this->container,
        );
    }

    /**
     * Call a custom driver creator.
     *
     * @param array<string, mixed> $config
     */
    protected function callCustomCreator(array $config): Driver
    {
        return $this->customCreators[$config['driver']]($this->container, $config);
    }

    /**
     * Create an instance of the array driver.
     *
     * @param array<string, mixed> $config
     */
    public function createArrayDriver(array $config, string $name): ArrayDriver
    {
        return new ArrayDriver($name, $this->partitionResolver($name, $config), []);
    }

    /**
     * Create an instance of the database driver.
     *
     * @param array<string, mixed> $config
     */
    public function createDatabaseDriver(array $config, string $name): DatabaseDriver
    {
        return new DatabaseDriver(
            $this->container->make('db'),
            $this->databaseConnection($config),
            $this->databaseTable($config),
            $this->partitionResolver($name, $config),
            $this->storedValueCache($config, $name),
            [],
        );
    }

    /**
     * Create the shared cache of a database store's stored values.
     *
     * Writes through any database store must invalidate the values cached by every other store
     * reading the same rows, so each cache configured on an overlapping store is a target. A
     * null connection overlaps every connection because the runtime default can select any.
     *
     * @param array<string, mixed> $config
     */
    protected function storedValueCache(array $config, string $name): ?StoredValueCache
    {
        $table = $this->databaseTable($config);
        $connection = $this->databaseConnection($config);
        $own = isset($config['cache']) ? $this->cacheProfile($name, $config['cache']) : null;
        $profiles = [];

        if ($own !== null) {
            $profiles["{$own[0]}:{$own[1]}"] = $own;
        }

        foreach ($this->container->make('config')->array('pennant.stores') as $storeName => $storeConfig) {
            if ($storeConfig['driver'] !== 'database' || ! isset($storeConfig['cache']) || $this->databaseTable($storeConfig) !== $table) {
                continue;
            }

            $storeConnection = $this->databaseConnection($storeConfig);

            if ($connection !== null && $storeConnection !== null
                && ConnectionName::withoutWriteType($connection) !== ConnectionName::withoutWriteType($storeConnection)) {
                continue;
            }

            $profile = $this->cacheProfile($storeName, $storeConfig['cache']);

            $profiles["{$profile[0]}:{$profile[1]}"] = $profile;
        }

        if ($profiles === []) {
            return null;
        }

        $cache = $this->container->make('cache');

        $targets = array_map(fn (array $profile) => [$cache->store($profile[0]), $profile[1]], array_values($profiles));

        return $own === null
            ? new StoredValueCache($table, $targets)
            : new StoredValueCache($table, $targets, $cache->store($own[0]), $own[1]);
    }

    /**
     * Get the cache store name and TTL of a database store's shared cache.
     *
     * @param array<string, mixed> $cache
     * @return array{string, int}
     *
     * @throws InvalidArgumentException when the TTL is not positive
     */
    protected function cacheProfile(string $name, array $cache): array
    {
        $ttl = $cache['ttl'] ?? StoredValueCache::DEFAULT_TTL;

        // The cache treats a non-positive TTL as a delete, and values must not be cached forever.
        if ($ttl < 1) {
            throw new InvalidArgumentException("The cache TTL of Pennant store [{$name}] must be a positive number of seconds.");
        }

        return [$cache['store'], $ttl];
    }

    /**
     * Get a database store's connection name, or null for the default connection.
     *
     * @param array<string, mixed> $config
     */
    protected function databaseConnection(array $config): ?string
    {
        $connection = $config['connection'] ?? null;

        return $connection === '' ? null : $connection;
    }

    /**
     * Get a database store's table.
     *
     * @param array<string, mixed> $config
     */
    protected function databaseTable(array $config): string
    {
        return $config['table'] ?? 'features';
    }

    /**
     * Serialize the given scope for storage.
     *
     * @throws RuntimeException when the scope can't be serialized
     */
    public function serializeScope(mixed $scope): string
    {
        return match (true) {
            $scope instanceof FeatureScopeSerializeable => $scope->featureScopeSerialize(),
            $scope === null => '__hypervel_null',
            is_string($scope) => $scope,
            is_numeric($scope) => (string) $scope,
            // Every unsaved model would otherwise share one stored value.
            $scope instanceof Model && $scope->getKey() === null => throw new RuntimeException('Unable to serialize a model without a key as the feature scope. Save the model or pass a scope that identifies it.'),
            $scope instanceof Model && $this->useMorphMap => $scope->getMorphClass() . '|' . $scope->getKey(),
            $scope instanceof Model && ! $this->useMorphMap => $scope::class . '|' . $scope->getKey(),
            default => throw new RuntimeException('Unable to serialize the feature scope to a string. You should implement the FeatureScopeSerializeable contract.')
        };
    }

    /**
     * Specify that the Eloquent morph map should be used when serializing.
     *
     * Boot-only. The setting persists on the manager for the worker lifetime and
     * changes how every subsequent request serializes model scopes.
     */
    public function useMorphMap(bool $value = true): static
    {
        $this->useMorphMap = $value;

        return $this;
    }

    /**
     * Flush the driver caches.
     *
     * Only the current coroutine's values are flushed.
     */
    public function flushCache(): void
    {
        foreach ($this->stores as $driver) {
            $driver->flushCache();
        }
    }

    /**
     * The default scope resolver.
     *
     * @return Closure(): mixed
     */
    protected function defaultScopeResolver(string $driver): Closure
    {
        return function () use ($driver) {
            if ($this->defaultScopeResolver !== null) {
                return ($this->defaultScopeResolver)($driver);
            }

            return $this->container->make('auth')->guard()->user();
        };
    }

    /**
     * Set the default scope resolver.
     *
     * Boot-only. The resolver persists on the manager for the worker lifetime and
     * applies to every subsequent request.
     *
     * @param callable(string): mixed $resolver
     */
    public function resolveScopeUsing(callable $resolver): void
    {
        $this->defaultScopeResolver = $resolver(...);
    }

    /**
     * Partition stored feature values by the given column.
     *
     * The resolver receives the store name and returns the current partition value. Stores
     * configured with "partitioned" set to false are not partitioned.
     *
     * Boot-only. Register before any store is resolved. The resolver persists on the manager
     * for the worker lifetime and applies to every subsequent request.
     *
     * @param Closure(string): (null|int|string) $resolver
     *
     * @throws InvalidArgumentException when the column is not a simple SQL identifier
     * @throws RuntimeException when a store has already been resolved
     */
    public function resolvePartitionUsing(string $column, Closure $resolver): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
            throw new InvalidArgumentException("Pennant partition column [{$column}] must be a simple SQL identifier.");
        }

        // A store resolved earlier would keep reading and writing across every partition.
        if ($this->stores !== []) {
            throw new RuntimeException('Register the Pennant partition resolver before resolving any Pennant store.');
        }

        $this->partitionColumn = $column;
        $this->partitionResolver = $resolver;
    }

    /**
     * The resolver of a store's current partition.
     *
     * @param array<string, mixed> $config
     * @return null|(Closure(): FeaturePartition)
     */
    protected function partitionResolver(string $name, array $config): ?Closure
    {
        if ($this->partitionResolver === null || ($config['partitioned'] ?? true) === false) {
            return null;
        }

        $column = $this->partitionColumn;
        $resolver = $this->partitionResolver;

        return static function () use ($name, $column, $resolver): FeaturePartition {
            $value = $resolver($name);

            if ($value === null || $value === '') {
                throw FeaturePartitionNotResolved::forStore($name, $column);
            }

            return new FeaturePartition($column, $value);
        };
    }

    /**
     * Get the Pennant store configuration.
     *
     * @return null|array<string, mixed>
     */
    protected function getConfig(string $name): ?array
    {
        return $this->container->make('config')->get("pennant.stores.{$name}");
    }

    /**
     * Get the default store name.
     */
    public function getDefaultDriver(): string
    {
        return $this->container->make('config')->string('pennant.default');
    }

    /**
     * Set the default store name.
     *
     * Boot-only. The default store is process-global config shared by every
     * request in the worker.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->container->make('config')->set('pennant.default', $name);
    }

    /**
     * Unset the given store instances.
     *
     * Boot or tests only. Stores are shared by every request in the worker, and
     * the current coroutine's values for a forgotten store are flushed.
     *
     * @param null|array<string>|string $name
     */
    public function forgetDriver(array|string|null $name = null): static
    {
        $name ??= $this->getDefaultDriver();

        foreach ((array) $name as $storeName) {
            if (isset($this->stores[$storeName])) {
                $this->stores[$storeName]->flushCache();

                unset($this->stores[$storeName]);
            }
        }

        return $this;
    }

    /**
     * Forget all of the resolved store instances.
     *
     * Boot or tests only. Stores are shared by every request in the worker, and
     * the current coroutine's values for the forgotten stores are flushed.
     */
    public function forgetDrivers(): static
    {
        foreach ($this->stores as $store) {
            $store->flushCache();
        }

        $this->stores = [];

        return $this;
    }

    /**
     * Register a custom driver creator Closure.
     *
     * Boot-only. The creator persists on the manager for the worker lifetime.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the container instance used by the manager.
     *
     * Tests only. The container persists on the manager and its stores for the
     * worker lifetime.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        foreach ($this->stores as $store) {
            $store->setContainer($container);
        }

        return $this;
    }

    /**
     * Dynamically call the default store instance.
     *
     * @param array<mixed> $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->{$method}(...$parameters);
    }
}
