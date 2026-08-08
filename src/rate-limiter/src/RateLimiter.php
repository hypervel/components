<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter;

use Closure;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\RateLimiter\Contracts\Store;
use Hypervel\RateLimiter\Swoole\TableManager;
use Hypervel\Support\MultipleInstanceManager;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @mixin Limiter
 */
class RateLimiter extends MultipleInstanceManager
{
    /**
     * The configured named limiter callbacks.
     *
     * @var array<string, Closure>
     */
    protected array $limiters = [];

    /**
     * The optional store selected for each named limiter.
     *
     * @var array<string, string>
     */
    protected array $limiterStores = [];

    /**
     * The callbacks used to resolve scopes for named limiter keys.
     *
     * @var list<Closure(string): ?string>
     */
    protected array $keyScopeResolvers = [];

    /**
     * Get a limiter store by name.
     */
    public function store(UnitEnum|string|null $name = null): Limiter
    {
        if ($name instanceof UnitEnum) {
            $name = (string) enum_value($name);
        }

        /** @var Limiter */
        return $this->instance($name);
    }

    /**
     * Register a named limiter configuration.
     *
     * Boot-only. The callback and store selection persist on the singleton
     * manager for the worker lifetime and affect every subsequent request.
     */
    public function for(
        UnitEnum|string $name,
        Closure $callback,
        UnitEnum|string|null $store = null,
    ): static {
        $name = $this->normalizeName($name);

        $this->limiters[$name] = $callback;

        if ($store === null) {
            unset($this->limiterStores[$name]);
        } else {
            $this->limiterStores[$name] = $this->normalizeName($store);
        }

        return $this;
    }

    /**
     * Get the given named rate limiter.
     */
    public function limiter(UnitEnum|string $name): ?Closure
    {
        return $this->limiters[$this->normalizeName($name)] ?? null;
    }

    /**
     * Get the store registered for a named rate limiter.
     */
    public function limiterStore(UnitEnum|string $name): ?string
    {
        return $this->limiterStores[$this->normalizeName($name)] ?? null;
    }

    /**
     * Register a named limiter key scope resolver.
     *
     * Boot-only. Each non-null callback is appended in registration order,
     * while passing null clears every registered callback. The callbacks
     * persist on the singleton manager for the worker lifetime and affect
     * every subsequent named limiter operation.
     *
     * @param null|(Closure(string): ?string) $resolver
     */
    public function resolveKeyScopeUsing(?Closure $resolver): void
    {
        if ($resolver === null) {
            $this->keyScopeResolvers = [];

            return;
        }

        $this->keyScopeResolvers[] = $resolver;
    }

    /**
     * Get the default rate limiter store name.
     */
    public function getDefaultInstance(): string
    {
        return $this->config->string('rate-limiter.default');
    }

    /**
     * Set the default rate limiter store name.
     *
     * Boot-only. Mutates process-global config; per-request use races across coroutines.
     */
    public function setDefaultInstance(string $name): void
    {
        $this->config->set('rate-limiter.default', $name);
    }

    /**
     * Get the store-specific configuration.
     */
    public function getInstanceConfig(string $name): array
    {
        $config = $this->config->get('rate-limiter.stores.' . $name);

        if (! is_array($config)) {
            throw new InvalidArgumentException("Rate limiter store [{$name}] is not defined.");
        }

        return [...$config, 'name' => $name];
    }

    /**
     * Resolve a store and wrap it in the public limiter API.
     */
    protected function resolve(string $name): Limiter
    {
        $store = parent::resolve($name);

        if (! $store instanceof Store) {
            throw new InvalidArgumentException(sprintf(
                'Rate limiter driver [%s] must return an instance of [%s].',
                get_debug_type($store),
                Store::class,
            ));
        }

        return new Limiter(
            $store,
            new KeyResolver(
                $this->config->string('rate-limiter.prefix'),
                fn (string $limiterName): array => $this->resolveKeyScopes($limiterName),
            ),
        );
    }

    /**
     * Resolve the contributed scopes for a named limiter.
     *
     * @return list<string>
     */
    protected function resolveKeyScopes(string $limiterName): array
    {
        $scopes = [];

        foreach ($this->keyScopeResolvers as $resolver) {
            $scope = $resolver($limiterName);

            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * Create a worker-lifetime array store.
     */
    protected function createWorkerArrayDriver(): Store
    {
        return new WorkerArrayStore;
    }

    /**
     * Create a database store.
     */
    protected function createDatabaseDriver(array $config): Store
    {
        $connection = $config['connection'] ?? null;
        $table = $config['table'] ?? null;

        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            throw new InvalidArgumentException('The rate limiter database connection must be null or a non-empty string.');
        }

        if (! is_string($table) || $table === '') {
            throw new InvalidArgumentException('The rate limiter database table must be a non-empty string.');
        }

        return new DatabaseStore(
            $this->app->make(ConnectionResolverInterface::class),
            $connection,
            $table,
        );
    }

    /**
     * Create a Redis store.
     */
    protected function createRedisDriver(array $config): Store
    {
        $connection = $config['connection'] ?? null;

        if (! is_string($connection) || $connection === '') {
            throw new InvalidArgumentException('The rate limiter Redis connection must be a non-empty string.');
        }

        return new RedisStore(
            $this->app->make(RedisFactory::class),
            $connection,
        );
    }

    /**
     * Create a Swoole store.
     */
    protected function createSwooleDriver(array $config): Store
    {
        $name = $config['name'] ?? null;
        $memoryLimitBuffer = $config['memory_limit_buffer'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException(
                'The resolved Swoole rate limiter store configuration is missing its manager-supplied name.'
            );
        }

        if (! is_float($memoryLimitBuffer) && ! is_int($memoryLimitBuffer)) {
            throw new InvalidArgumentException('The Swoole rate limiter memory limit buffer must be numeric.');
        }

        return new SwooleStore(
            $this->app->make(TableManager::class)->get($name),
            (float) $memoryLimitBuffer,
            $this->app->make(LoggerInterface::class),
        );
    }

    /**
     * Normalize an enum or string manager name.
     */
    protected function normalizeName(UnitEnum|string $name): string
    {
        return $name instanceof UnitEnum
            ? (string) enum_value($name)
            : $name;
    }
}
