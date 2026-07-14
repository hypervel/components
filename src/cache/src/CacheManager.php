<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cache\Factory as FactoryContract;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Store;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher as DispatcherContract;
use Hypervel\Contracts\Session\Session;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Mockery;
use Mockery\LegacyMockInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin \Hypervel\Contracts\Cache\Repository
 * @mixin \Hypervel\Contracts\Cache\LockProvider
 * @mixin \Hypervel\Cache\TaggableStore
 */
class CacheManager implements FactoryContract
{
    /**
     * The context key prefix for memoized cache repositories.
     */
    protected const MEMOIZED_CONTEXT_KEY_PREFIX = '__cache.memoized.';

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
        protected Container $app
    ) {
    }

    /**
     * Get a cache store instance by name, wrapped in a repository.
     */
    public function store(UnitEnum|string|null $name = null): CacheRepository
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name ??= $this->getDefaultDriver();

        return $this->stores[$name] ??= $this->resolve($name);
    }

    /**
     * Get a cache driver instance.
     */
    public function driver(UnitEnum|string|null $driver = null): CacheRepository
    {
        return $this->store($driver);
    }

    /**
     * Get a memoized cache driver instance.
     *
     * The memoized repository is isolated to the current coroutine and resets
     * when the coroutine ends.
     */
    public function memo(UnitEnum|string|null $driver = null): CacheRepository
    {
        if ($driver instanceof UnitEnum) {
            $driver = (string) enum_value($driver);
        }

        $driver = $driver ?? $this->getDefaultDriver();

        // Laravel uses a scoped container binding here. Hypervel stores this
        // directly in coroutine context because coroutine teardown is the
        // request reset boundary.
        return CoroutineContext::getOrSet(
            self::MEMOIZED_CONTEXT_KEY_PREFIX . $driver,
            fn (): CacheRepository => $this->createMemoizedRepository($driver),
        );
    }

    /**
     * Create a memoized repository for the given driver.
     */
    protected function createMemoizedRepository(string $driver): CacheRepository
    {
        $isSpy = isset($this->app['cache']) && $this->app['cache'] instanceof LegacyMockInterface;

        /** @var Repository $store */
        $store = $this->store($driver);

        $repository = $this->repository(
            new MemoizedStore($driver, $store),
            ['events' => false]
        );

        return $isSpy ? Mockery::spy($repository) : $repository;
    }

    /**
     * Resolve the given store.
     *
     * @throws InvalidArgumentException
     */
    public function resolve(string $name): CacheRepository
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Cache store [{$name}] is not defined.");
        }

        $config = Arr::add($config, 'store', $name);

        return $this->build($config);
    }

    /**
     * Build a cache repository with the given configuration.
     *
     * @throws InvalidArgumentException
     */
    public function build(array $config): CacheRepository
    {
        $config = Arr::add($config, 'store', $config['name'] ?? 'ondemand');

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create' . Str::studly($config['driver']) . 'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(array $config): CacheRepository
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create an instance of the array cache driver.
     */
    protected function createArrayDriver(array $config): Repository
    {
        return $this->repository(new ArrayStore(
            $config['serialize'] ?? false,
            $this->getSerializableClasses($config),
        ), $config);
    }

    /**
     * Create an instance of the worker-lifetime array cache driver.
     */
    protected function createWorkerArrayDriver(array $config): Repository
    {
        return $this->repository(new WorkerArrayStore(
            $config['serialize'] ?? false,
            $this->getSerializableClasses($config),
        ), $config);
    }

    /**
     * Create an instance of the database cache driver.
     */
    protected function createDatabaseDriver(array $config): Repository
    {
        $connectionResolver = $this->app['db'];

        $store = new DatabaseStore(
            $connectionResolver,
            $config['connection'] ?? null,
            $config['table'],
            $this->getPrefix($config),
            $config['lock_table'] ?? 'cache_locks',
            $config['lock_lottery'] ?? [2, 100],
            $config['lock_timeout'] ?? 86400,
            $this->getSerializableClasses($config),
        );

        if ($lockConnection = $config['lock_connection'] ?? null) {
            $store->setLockConnection($lockConnection);
        }

        return $this->repository($store, $config);
    }

    /**
     * Create an instance of the failover cache driver.
     */
    protected function createFailoverDriver(array $config): Repository
    {
        return $this->repository(new FailoverStore(
            $this,
            $this->app->make(DispatcherContract::class),
            $config['stores']
        ), ['events' => false, ...$config]);
    }

    /**
     * Create an instance of the file cache driver.
     */
    protected function createFileDriver(array $config): Repository
    {
        return $this->repository(
            (new FileStore(
                $this->app['files'],
                $config['path'],
                $config['permission'] ?? null,
                $this->getSerializableClasses($config),
            ))
                ->setLockDirectory($config['lock_path'] ?? null),
            $config
        );
    }

    /**
     * Create an instance of the Null cache driver.
     */
    protected function createNullDriver(): Repository
    {
        return $this->repository(new NullStore, []);
    }

    /**
     * Create an instance of the Redis cache driver.
     */
    protected function createRedisDriver(array $config): Repository
    {
        $redis = $this->app['redis'];

        $connection = $config['connection'] ?? 'default';

        $store = new RedisStore(
            $redis,
            $this->getPrefix($config),
            $connection,
            serializableClasses: $this->getSerializableClasses($config),
        );
        $store->setTagMode($config['tag_mode'] ?? 'all');

        return $this->repository(
            $store->setLockConnection($config['lock_connection'] ?? $connection),
            $config
        );
    }

    /**
     * Create an instance of the session cache driver.
     */
    protected function createSessionDriver(array $config): Repository
    {
        return $this->repository(
            new SessionStore(
                $this->getSession(),
                $config['key'] ?? '_cache',
            ),
            $config
        );
    }

    /**
     * Get the session store implementation.
     *
     * @throws InvalidArgumentException
     */
    protected function getSession(): Session
    {
        if (! $this->app->has('session.store')) {
            throw new InvalidArgumentException('Session store requires session manager to be available in container.');
        }

        return $this->app->make('session.store');
    }

    /**
     * Create an instance of the Swoole cache driver.
     */
    protected function createSwooleDriver(array $config): Repository
    {
        $tableState = $this->app->make(SwooleTableManager::class)->get($config['table']);
        $store = new SwooleStore(
            $tableState,
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

            $store = $this->store($name)->getStore();

            return new StackStoreProxy($store, $config['ttl'] ?? null);
        })->all();

        return $this->repository(new StackStore($stores), $config);
    }

    /**
     * Create a new cache repository with the given implementation.
     */
    public function repository(Store $store, array $config = []): Repository
    {
        return tap(new Repository($store, Arr::only($config, ['store'])), function ($repository) use ($config) {
            if ($config['events'] ?? true) {
                $this->setEventDispatcher($repository);
            }
        });
    }

    /**
     * Set the event dispatcher on the given repository instance.
     */
    protected function setEventDispatcher(Repository $repository): void
    {
        if (! $this->app->bound(DispatcherContract::class)) {
            return;
        }

        $repository->setEventDispatcher(
            $this->app[DispatcherContract::class]
        );
    }

    /**
     * Re-set the event dispatcher on all resolved cache repositories.
     *
     * Boot or tests only. Replaces the dispatcher on every cached repository
     * for the worker lifetime; per-request use races across coroutines.
     * Reached by Event::fake() / Event::fakeFor() to point cached repositories
     * at the fake dispatcher and to restore them afterwards.
     */
    public function refreshEventDispatcher(): void
    {
        array_map($this->setEventDispatcher(...), $this->stores);
    }

    /**
     * Get the cache prefix.
     */
    protected function getPrefix(array $config): string
    {
        return $config['prefix'] ?? $this->app->make('config')->string('cache.prefix');
    }

    /**
     * Get the classes that should be allowed during unserialization.
     */
    protected function getSerializableClasses(array $config): array|bool|null
    {
        return $this->app->make('config')->get('cache.serializable_classes');
    }

    /**
     * Get the cache connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if ($name !== 'null') {
            return $this->app->make('config')->get("cache.stores.{$name}");
        }

        return ['driver' => 'null'];
    }

    /**
     * Get the default cache driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->make('config')->string('cache.default', 'null');
    }

    /**
     * Set the default cache driver name.
     *
     * Boot-only. Mutates process-global config; per-request use races across coroutines.
     */
    public function setDefaultDriver(UnitEnum|string $name): void
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $this->app['config']['cache.default'] = $name;
    }

    /**
     * Unset the given driver instances.
     *
     * Boot or tests only. Mutates the singleton's store cache; concurrent
     * coroutines may already hold a reference to the store and next resolution
     * will rebuild with fresh connections.
     */
    public function forgetDriver(array|UnitEnum|string|null $name = null): static
    {
        $name ??= $this->getDefaultDriver();

        foreach (is_array($name) ? $name : [$name] as $cacheName) {
            if ($cacheName instanceof UnitEnum) {
                $cacheName = (string) enum_value($cacheName);
            }

            if (isset($this->stores[$cacheName])) {
                unset($this->stores[$cacheName]);
            }
        }

        return $this;
    }

    /**
     * Disconnect the given driver and remove from local cache.
     *
     * Boot or tests only. Mutates the singleton's store cache; concurrent
     * coroutines may already hold a reference to the store and next resolution
     * will rebuild with fresh connections.
     */
    public function purge(UnitEnum|string|null $name = null): void
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        $name ??= $this->getDefaultDriver();

        unset($this->stores[$name]);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * Boot-only. The callback persists in the singleton's customCreators array
     * for the worker lifetime and applies to every subsequent store resolution.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the application instance used by the manager.
     *
     * Tests only. Swaps the singleton's container reference; per-request use
     * races across coroutines and breaks every concurrent request resolving
     * stores through this manager.
     */
    public function setApplication(Container $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->{$method}(...$parameters);
    }
}
