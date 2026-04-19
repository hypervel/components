<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\SwooleStore;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable as UserContract;
use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Hashing\Hasher as HasherContract;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use InvalidArgumentException;
use SensitiveParameter;

class EloquentUserProvider implements UserProvider
{
    /**
     * Sentinel value cached for user IDs that don't exist.
     *
     * Must be serializable (not an object) because it's stored in an
     * external cache store.
     *
     * @var array{__auth_null_sentinel: true}
     */
    protected const NULL_SENTINEL = ['__auth_null_sentinel' => true];

    /**
     * Whitelist of cache store classes supported for auth user caching.
     *
     * Checked with instanceof in ensureSupportedAuthCacheStore(), so
     * legitimate subclasses of these stores are also accepted.
     *
     * @var list<class-string>
     */
    private const array SUPPORTED_AUTH_CACHE_STORES = [
        RedisStore::class,
        DatabaseStore::class,
        FileStore::class,
        SwooleStore::class,
        StackStore::class,
    ];

    /**
     * The callback used to build the identifier segment of cache keys.
     *
     * Global for all cached Eloquent user providers. Set once in a service
     * provider's boot() method. Evaluated at call time so it can read
     * per-request context (e.g., tenant ID from Context).
     *
     * @var null|(Closure(mixed): string)
     */
    protected static ?Closure $cacheKeyResolver = null;

    /**
     * Registry of cache descriptors per model class.
     *
     * Each entry is keyed by a deterministic descriptor hash, holding
     * enough information to rebuild the exact cache key on invalidation
     * (storeName, prefix, modelSegment) without retaining a reference
     * to any provider instance. Duplicate configs collapse on insert.
     *
     * @var array<class-string, array<string, array{storeName: ?string, prefix: string, modelSegment: string}>>
     */
    protected static array $cachedProviders = [];

    /**
     * Whether model event listeners have been registered for a model class.
     *
     * @var array<class-string, true>
     */
    protected static array $cacheEventsRegistered = [];

    /**
     * The callback that may modify the user retrieval queries.
     *
     * @var null|(Closure(Builder):mixed)
     */
    protected ?Closure $queryCallback = null;

    /**
     * The cache store for user lookups.
     */
    protected ?CacheRepository $cache = null;

    /**
     * The cache store name (null = default store).
     *
     * Stored so the descriptor registry can re-resolve the store by name
     * on invalidation without holding a strong reference to this provider.
     */
    protected ?string $cacheStoreName = null;

    /**
     * The cache TTL in seconds.
     */
    protected int $cacheTtl = 300;

    /**
     * The cache key prefix.
     */
    protected string $cachePrefix = 'auth_users';

    /**
     * Memoized model key segment (the fully qualified class name).
     *
     * Computed once in enableCache() and reused on every retrieveById()
     * to avoid per-request string work on the hot path.
     */
    protected string $modelSegment = '';

    /**
     * Create a new database user provider.
     *
     * @param class-string<Model&UserContract> $model
     */
    public function __construct(
        protected HasherContract $hasher,
        protected string $model,
    ) {
    }

    /**
     * Retrieve a user by their unique identifier.
     */
    public function retrieveById(mixed $identifier): ?UserContract
    {
        if (! $this->cache) {
            return $this->fetchUserById($identifier);
        }

        $key = $this->buildCacheKey($identifier);
        $cached = $this->cache->get($key);

        if ($cached === self::NULL_SENTINEL) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $user = $this->fetchUserById($identifier);

        $this->cache->put($key, $user ?? self::NULL_SENTINEL, $this->cacheTtl);

        return $user;
    }

    /**
     * Fetch a user by ID from the database.
     */
    protected function fetchUserById(mixed $identifier): ?UserContract
    {
        $model = $this->createModel();

        return $this->newModelQuery($model) /* @phpstan-ignore return.type */
            ->where($model->getAuthIdentifierName(), $identifier)
            ->first();
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token.
     */
    public function retrieveByToken(mixed $identifier, #[SensitiveParameter] string $token): ?UserContract
    {
        $model = $this->createModel();

        /** @var null|(Model&UserContract) $retrievedModel */
        $retrievedModel = $this->newModelQuery($model)->where(
            $model->getAuthIdentifierName(),
            $identifier
        )->first();

        if (! $retrievedModel) {
            return null;
        }

        $rememberToken = $retrievedModel->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token) ? $retrievedModel : null;
    }

    /**
     * Update the "remember me" token for the given user in storage.
     *
     * @param Model&UserContract $user
     */
    public function updateRememberToken(UserContract $user, #[SensitiveParameter] string $token): void
    {
        $user->setRememberToken($token);

        $timestamps = $user->timestamps;

        $user->timestamps = false;

        $user->save();

        $user->timestamps = $timestamps;

        // Cache invalidation (when caching is enabled) is handled by the
        // saved model event listener — no explicit clear needed here.
    }

    /**
     * Retrieve a user by the given credentials.
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?UserContract
    {
        $credentials = array_filter(
            $credentials,
            fn ($key) => ! is_string($key) || ! str_contains($key, 'password'),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($credentials)) {
            return null;
        }

        // First we will add each credential element to the query as a where clause.
        // Then we can execute the query and, if we found a user, return it in a
        // Eloquent User "model" that will be utilized by the Guard instances.
        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if (is_array($value) || $value instanceof Arrayable) {
                $query->whereIn($key, $value);
            } elseif ($value instanceof Closure) {
                $value($query);
            } else {
                $query->where($key, $value);
            }
        }

        return $query->first(); /* @phpstan-ignore return.type */
    }

    /**
     * Validate a user against the given credentials.
     */
    public function validateCredentials(UserContract $user, #[SensitiveParameter] array $credentials): bool
    {
        if (is_null($plain = $credentials['password'])) {
            return false;
        }

        if (is_null($hashed = $user->getAuthPassword())) {
            return false;
        }

        return $this->hasher->check($plain, $hashed);
    }

    /**
     * Rehash the user's password if required and supported.
     *
     * @param Model&UserContract $user
     */
    public function rehashPasswordIfRequired(UserContract $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
        if (! $this->hasher->needsRehash($user->getAuthPassword()) && ! $force) {
            return;
        }

        $user->forceFill([
            $user->getAuthPasswordName() => $this->hasher->make($credentials['password']),
        ])->save();
    }

    /**
     * Enable cross-request caching for user lookups.
     *
     * Accepts a store name (or null for the default store) rather than a
     * pre-resolved repository so the descriptor registry can re-resolve
     * by name on invalidation and avoid holding strong references.
     *
     * A null or empty-string prefix is normalized to the feature default
     * ('auth_users') so misconfiguration does not create hard-to-read keys
     * with a leading colon.
     *
     * The store is validated against the supported-drivers whitelist BEFORE
     * any instance state is mutated, so a rejected store leaves the provider
     * in its prior (uncached) state and does not register a descriptor or
     * model event listeners.
     *
     * @throws InvalidArgumentException when the resolved store is not supported
     */
    public function enableCache(?string $storeName, int $ttl = 300, ?string $prefix = 'auth_users'): static
    {
        $cache = Container::getInstance()->make('cache')->store($storeName);
        $this->ensureSupportedAuthCacheStore($cache);

        $this->cache = $cache;
        $this->cacheStoreName = $storeName;
        $this->cacheTtl = $ttl;
        $this->cachePrefix = $prefix === null || $prefix === '' ? 'auth_users' : $prefix;
        $this->modelSegment = $this->model;

        $this->registerCacheInvalidationEvents();

        return $this;
    }

    /**
     * Determine if cross-request user caching is enabled.
     */
    public function isCacheEnabled(): bool
    {
        return $this->cache !== null;
    }

    /**
     * Clear the cached user for the given identifier.
     *
     * Uses the same key resolver as retrieveById(), so it respects
     * tenant context and custom key callbacks.
     */
    public function clearUserCache(mixed $identifier): void
    {
        $this->cache?->forget($this->buildCacheKey($identifier));
    }

    /**
     * Set the cache key resolver for all cached Eloquent user providers.
     *
     * The callback receives the user identifier and should return a string
     * that uniquely identifies the user within the current context (e.g.,
     * including tenant ID for multi-tenant apps). Called once in a service
     * provider's boot() method — the closure is evaluated fresh on each
     * retrieveById() call so per-request context like tenant ID is current.
     *
     * The fully qualified model class name is always included in the key
     * automatically. The resolver only controls the identifier segment.
     *
     * @param Closure(mixed): string $callback
     */
    public static function resolveUserCacheKeyUsing(Closure $callback): void
    {
        static::$cacheKeyResolver = $callback;
    }

    /**
     * Flush static state for test isolation.
     */
    public static function flushState(): void
    {
        static::$cacheKeyResolver = null;
        static::$cachedProviders = [];
        static::$cacheEventsRegistered = [];
    }

    /**
     * Ensure the configured cache store is supported for auth user caching.
     *
     * Throws when the resolved Store is not an instance of one of the
     * whitelisted classes. Called from enableCache() before any instance
     * state is mutated, so a rejected store leaves the provider in its
     * prior uncached state. Uses instanceof so legitimate subclasses of
     * supported stores are accepted.
     *
     * @throws InvalidArgumentException
     */
    protected function ensureSupportedAuthCacheStore(CacheRepository $cache): void
    {
        $store = $cache->getStore();

        foreach (self::SUPPORTED_AUTH_CACHE_STORES as $supported) {
            if ($store instanceof $supported) {
                return;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Auth user caching does not support cache store [%s]. See the auth cache documentation for supported stores.',
            $store::class
        ));
    }

    /**
     * Build the cache key for a user identifier.
     *
     * Always includes the fully qualified model class name (memoized in
     * enableCache()) so providers using different models never collide —
     * even when two models share a basename across namespaces. The custom
     * resolver (if set) controls the identifier segment only.
     */
    protected function buildCacheKey(mixed $identifier): string
    {
        $identifierSegment = static::$cacheKeyResolver
            ? (static::$cacheKeyResolver)($identifier)
            : (string) $identifier;

        return $this->cachePrefix . ':' . $this->modelSegment . ':' . $identifierSegment;
    }

    /**
     * Register this provider's cache descriptor and set up model event
     * listeners for automatic cache invalidation.
     *
     * Uses a descriptor-based registry: each (storeName, prefix, modelSegment)
     * triple is stored under a deterministic hash so duplicate configs
     * collapse. On save/delete, the listener iterates descriptors for the
     * model class, re-resolves each store by name via the cache manager,
     * rebuilds the key using the current global resolver callback, and
     * calls forget(). Nothing holds a reference to a provider instance —
     * safe against forgetGuards() + re-resolve cycles under Swoole.
     *
     * Event listener registration is guarded by the model's dispatcher
     * being non-null — HasEvents::registerModelEvent() silently no-ops
     * when the dispatcher isn't set, so we only mark the class as
     * registered AFTER a successful attempt, leaving a retry window on
     * the next enableCache() call.
     */
    protected function registerCacheInvalidationEvents(): void
    {
        $modelClass = $this->model;

        // Insert or replace the descriptor — duplicate configs collapse.
        $descriptorKey = md5(
            ($this->cacheStoreName ?? '') . '|' . $this->cachePrefix . '|' . $this->modelSegment
        );

        static::$cachedProviders[$modelClass][$descriptorKey] = [
            'storeName' => $this->cacheStoreName,
            'prefix' => $this->cachePrefix,
            'modelSegment' => $this->modelSegment,
        ];

        if (isset(static::$cacheEventsRegistered[$modelClass])) {
            return;
        }

        // registerModelEvent() silently no-ops if the dispatcher isn't set.
        // Use the public getEventDispatcher() since Model::$dispatcher is
        // protected. Inside withoutEvents() this returns a NullDispatcher
        // wrapping the real one — non-null, so we proceed, and the listener
        // still attaches to the real dispatcher underneath.
        if ($modelClass::getEventDispatcher() === null) {
            return;
        }

        $invalidate = static function (UserContract $user): void {
            $id = $user->getAuthIdentifier();
            $identifierSegment = static::$cacheKeyResolver
                ? (static::$cacheKeyResolver)($id)
                : (string) $id;

            $cacheManager = Container::getInstance()->make('cache');

            foreach (static::$cachedProviders[$user::class] ?? [] as $descriptor) {
                $cacheManager
                    ->store($descriptor['storeName'])
                    ->forget($descriptor['prefix'] . ':' . $descriptor['modelSegment'] . ':' . $identifierSegment);
            }
        };

        $modelClass::saved($invalidate);
        $modelClass::deleted($invalidate);

        static::$cacheEventsRegistered[$modelClass] = true;
    }

    /**
     * Get a new query builder for the model instance.
     */
    protected function newModelQuery(?Model $model = null): Builder
    {
        $query = is_null($model)
            ? $this->createModel()->newQuery()
            : $model->newQuery();

        with($query, $this->queryCallback);

        return $query;
    }

    /**
     * Create a new instance of the model.
     *
     * @return Model&UserContract
     */
    public function createModel(): Model
    {
        $class = '\\' . ltrim($this->model, '\\');

        return new $class;
    }

    /**
     * Get the hasher implementation.
     */
    public function getHasher(): HasherContract
    {
        return $this->hasher;
    }

    /**
     * Set the hasher implementation.
     */
    public function setHasher(HasherContract $hasher): static
    {
        $this->hasher = $hasher;

        return $this;
    }

    /**
     * Get the name of the Eloquent user model.
     *
     * @return class-string<Model&UserContract>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Set the name of the Eloquent user model.
     *
     * @param class-string<Model&UserContract> $model
     */
    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * Get the callback that modifies the query before retrieving users.
     *
     * @return null|(Closure(Builder):mixed)
     */
    public function getQueryCallback(): ?Closure
    {
        return $this->queryCallback;
    }

    /**
     * Set the callback to modify the query before retrieving users.
     *
     * @param null|(Closure(Builder):mixed) $queryCallback
     */
    public function withQuery(?Closure $queryCallback = null): static
    {
        $this->queryCallback = $queryCallback;

        return $this;
    }
}
