<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hypervel\Cache\Contracts\Factory as FactoryContract;
use Hypervel\Cache\Contracts\Repository as RepositoryContract;
use Hypervel\Cache\Contracts\Store;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface as DispatcherContract;

use function Hyperf\Support\make;
use function Hyperf\Tappable\tap;

/**
 * @mixin \Hypervel\Cache\Contracts\Repository
 * @mixin \Hypervel\Cache\Contracts\LockProvider
 * @mixin \Hypervel\Cache\TaggableStore
 */
class CacheManager implements FactoryContract
{
    /**
     * The array of resolved cache stores.
     */
    protected array $stores = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * Create a new Cache manager instance.
     */
    public function __construct(
        protected ContainerInterface $app
    ) {
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->{$method}(...$parameters);
    }

    /**
     * Get a cache store instance by name, wrapped in a repository.
     */
    public function store(?string $name = null): RepositoryContract
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->stores[$name] ??= $this->resolve($name);
    }

    /**
     * Get a cache driver instance.
     */
    public function driver(?string $driver = null): RepositoryContract
    {
        return $this->store($driver);
    }

    /**
     * Create a new cache repository with the given implementation.
     */
    public function repository(Store $store, array $config = []): Repository
    {
        return tap(new Repository($store), function ($repository) use ($config) {
            if ($config['events'] ?? false) {
                $this->setEventDispatcher($repository);
            }
        });
    }

    /**
     * Re-set the event dispatcher on all resolved cache repositories.
     */
    public function refreshEventDispatcher(): void
    {
        foreach ($this->stores as $name => $repository) {
            if ($this->getConfig($name)['events'] ?? false) {
                $this->setEventDispatcher($repository);
            }
        }
    }

    /**
     * Get the default cache driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->get(ConfigInterface::class)
            ->get('cache.default', 'file');
    }

    /**
     * Set the default cache driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->app->get(ConfigInterface::class)
            ->set('cache.default', $name);
    }

    /**
     * Unset the given driver instances.
     */
    public function forgetDriver(array|string|null $name = null): static
    {
        $name ??= $this->getDefaultDriver();

        foreach ((array) $name as $cacheName) {
            if (isset($this->stores[$cacheName])) {
                unset($this->stores[$cacheName]);
            }
        }

        return $this;
    }

    /**
     * Disconnect the given driver and remove from local cache.
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultDriver();

        unset($this->stores[$name]);
    }

    /**
     * Register a custom driver creator Closure.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(ContainerInterface $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Attempt to get the store from the local cache.
     */
    protected function getStore(string $name): RepositoryContract
    {
        return $this->stores[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given store.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): RepositoryContract
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Cache store [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(array $config): RepositoryContract
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create an instance of the array cache driver.
     */
    protected function createArrayDriver(array $config): Repository
    {
        return $this->repository(new ArrayStore($config['serialize'] ?? false), $config);
    }

    /**
     * Create an instance of the file cache driver.
     */
    protected function createFileDriver(array $config): Repository
    {
        $store = make(FileStore::class, [
            'directory' => $config['path'],
            'filePermission' => $config['permission'] ?? null,
        ])->setLockDirectory($config['lock_path'] ?? null);

        return $this->repository($store, $config);
    }

    /**
     * Create an instance of the Null cache driver.
     */
    protected function createNullDriver(): Repository
    {
        return $this->repository(new NullStore(), []);
    }

    /**
     * Create an instance of the Redis cache driver.
     */
    protected function createRedisDriver(array $config): Repository
    {
        $redis = $this->app->get(RedisFactory::class);

        $connection = $config['connection'] ?? 'default';

        $store = new RedisStore($redis, $this->getPrefix($config), $connection);

        return $this->repository(
            $store->setLockConnection($config['lock_connection'] ?? $connection),
            $config
        );
    }

    /**
     * Create an instance of the Swoole cache driver.
     */
    protected function createSwooleDriver(array $config): Repository
    {
        $cacheTable = $this->app->get(SwooleTableManager::class)->get($config['table']);
        $store = new SwooleStore(
            $cacheTable,
            $config['memory_limit_buffer'] ?? 0.05,
            $config['eviction_policy'] ?? SwooleStore::EVICTION_POLICY_LRU,
            $config['eviction_proportion'] ?? 0.05
        );

        return $this->repository($store, $config);
    }

    /**
     * Create an instance of the Stack cache driver.
     */
    protected function createStackDriver(array $config): Repository
    {
        $stores = collect($config['stores'])->map(function ($config, $name) {
            if (! is_array($config)) {
                $name = $config;
                $config = [];
            }

            $store = $this->getStore($name)->getStore();

            return new StackStoreProxy($store, $config['ttl'] ?? null);
        })->all();

        return $this->repository(new StackStore($stores), $config);
    }

    /**
     * Create an instance of the database cache driver.
     */
    protected function createDatabaseDriver(array $config): Repository
    {
        $connectionResolver = $this->app->get(\Hypervel\Database\ConnectionResolverInterface::class);

        $store = new DatabaseStore(
            $connectionResolver,
            $config['connection'] ?? 'default',
            $config['table'],
            $this->getPrefix($config),
            $config['lock_table'] ?? 'cache_locks',
            $config['lock_lottery'] ?? [2, 100],
            $config['lock_timeout'] ?? 86400
        );

        return $this->repository($store, $config);
    }

    /**
     * Set the event dispatcher on the given repository instance.
     */
    protected function setEventDispatcher(Repository $repository): void
    {
        if (! $this->app->has(DispatcherContract::class)) {
            return;
        }

        $repository->setEventDispatcher(
            $this->app->get(DispatcherContract::class)
        );
    }

    /**
     * Get the cache prefix.
     */
    protected function getPrefix(array $config): string
    {
        return $config['prefix'] ?? $this->app->get(ConfigInterface::class)->get('cache.prefix');
    }

    /**
     * Get the cache connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if ($name !== 'null') {
            return $this->app->get(ConfigInterface::class)->get("cache.stores.{$name}");
        }

        return ['driver' => 'null'];
    }
}
