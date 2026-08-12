<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hypervel\Cache\ModelCacheStoreValidator;
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
     * The callback used to build the identifier segment of cache keys.
     *
     * Global for all cached Eloquent user providers. Set once in a service
     * provider's boot() method. Evaluated at call time so it can read
     * per-request context (e.g., tenant ID from Context).
     *
     * @var null|(Closure(mixed, class-string<Model&UserContract>, null|(Model&UserContract)): string)
     */
    protected static ?Closure $cacheKeyResolver = null;

    /**
     * Global resolver returning additional per-request tags to union with
     * the static config tags at every cache write.
     *
     * Set once in a service provider's boot() method. Evaluated fresh on
     * each cache put so it can read per-request context.
     *
     * @var null|(Closure(): list<string>)
     */
    protected static ?Closure $cacheTagsResolver = null;

    /**
     * Registry of cache descriptors per model class.
     *
     * Each entry is keyed by a deterministic descriptor hash, holding
     * enough information to rebuild the exact cache key on invalidation
     * (storeName, prefix), combined with the model class it is registered
     * under, without retaining a reference to any provider instance.
     * Duplicate configs collapse on insert.
     *
     * @var array<class-string, array<string, array{storeName: ?string, prefix: string}>>
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
    protected int $cacheTtl;

    /**
     * The cache key prefix.
     */
    protected string $cachePrefix;

    /**
     * Static tags applied to every cache write (unioned with whatever the
     * tag resolver returns). Null = no tags configured.
     *
     * @var null|list<string>
     */
    protected ?array $cacheTags = null;

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

        return $this->resolveWriteCache()->rememberNullable(
            $this->buildCacheKey($identifier),
            $this->cacheTtl,
            fn () => $this->fetchUserById($identifier),
        );
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

        try {
            $user->save();
        } finally {
            $user->timestamps = $timestamps;
        }

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
     * The store is validated before any instance state is mutated, so a
     * rejected store leaves the provider in its prior uncached state and
     * does not register a descriptor or model event listeners.
     *
     * Boot-only. User providers are held by cached guards; runtime use mutates
     * the provider used by every subsequent authentication lookup.
     *
     * @param null|array<string> $tags optional tag names enabling tag-based bulk flush; requires any-mode tag support
     *
     * @throws InvalidArgumentException when the TTL, tags, or resolved store are not supported
     */
    public function enableCache(
        ?string $storeName,
        int $ttl = 300,
        ?string $prefix = 'auth_users',
        ?array $tags = null,
    ): static {
        if ($ttl <= 0) {
            throw new InvalidArgumentException('The auth user cache TTL must be greater than zero.');
        }

        if ($tags !== null && ! array_all($tags, static fn (mixed $tag): bool => is_string($tag))) {
            throw new InvalidArgumentException('The auth user cache tags must contain only strings.');
        }

        $container = Container::getInstance();
        $cache = $container->make('cache')->store($storeName);

        $validator = $container->make(ModelCacheStoreValidator::class);
        $feature = "Auth user cache for model [{$this->model}]";
        $validator->validate(
            $cache,
            $feature,
        );

        if ($tags !== null && $tags !== []) {
            $validator->validateAnyModeTags($cache, $feature);
            $this->cacheTags = array_values($tags);
        } else {
            $this->cacheTags = null;
        }

        $this->cache = $cache;
        $this->cacheStoreName = $storeName;
        $this->cacheTtl = $ttl;
        $this->cachePrefix = $prefix === null || $prefix === '' ? 'auth_users' : $prefix;

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
     * Uses the same key resolver as retrieveById(), passing null for the user
     * model so context-aware keys use the caller's current context.
     */
    public function clearUserCache(mixed $identifier): void
    {
        $this->cache?->forget($this->buildCacheKey($identifier));
    }

    /**
     * Set the cache key resolver for all cached Eloquent user providers.
     *
     * The callback receives the user identifier and provider model class.
     * Invalidation triggered by a model event also provides the saved or
     * deleted user; lookups and manual invalidation provide null. It should
     * return a string that uniquely identifies the user within the current
     * context (e.g., including tenant ID for multi-tenant apps). Called once
     * in a service provider's boot() method — the closure is evaluated fresh
     * on each lookup or invalidation so per-request context like tenant ID
     * is current.
     *
     * The fully qualified model class name is always included in the key
     * automatically. The resolver only controls the identifier segment.
     *
     * Boot-only. The resolver persists in a static property for the worker
     * lifetime and runs on every cached user lookup and invalidation.
     *
     * @param Closure(mixed, class-string<Model&UserContract>, null|(Model&UserContract)): string $callback
     */
    public static function resolveUserCacheKeyUsing(Closure $callback): void
    {
        static::$cacheKeyResolver = $callback;
    }

    /**
     * Set the cache tags resolver for all cached Eloquent user providers.
     *
     * The callback receives no arguments and should return a list of tag
     * names for the current request context. Called fresh on each cache
     * put so it can read per-request state.
     *
     * Effective tags applied to each write = static config tags
     * (per-provider, from auth.providers.*.cache.tags) unioned with the
     * resolver's return value.
     *
     * Boot-only. The resolver persists in a static property for the worker
     * lifetime and runs on every cached user write.
     *
     * @param Closure(): list<string> $callback
     */
    public static function resolveUserCacheTagsUsing(Closure $callback): void
    {
        static::$cacheTagsResolver = $callback;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$cacheKeyResolver = null;
        static::$cacheTagsResolver = null;
        static::$cachedProviders = [];
        static::$cacheEventsRegistered = [];
    }

    /**
     * Build the cache key for a user identifier.
     *
     * Always includes the fully qualified model class name so providers
     * using different models never collide, even when two models share a
     * basename across namespaces. The custom resolver (if set) controls
     * the identifier segment only.
     */
    protected function buildCacheKey(mixed $identifier): string
    {
        $identifierSegment = static::resolveCacheKeyIdentifier(
            $identifier,
            $this->model,
        );

        return $this->cachePrefix . ':' . $this->model . ':' . $identifierSegment;
    }

    /**
     * Resolve the identifier segment of a user cache key.
     *
     * @param class-string<Model&UserContract> $model
     */
    protected static function resolveCacheKeyIdentifier(
        mixed $identifier,
        string $model,
        (Model&UserContract)|null $user = null,
    ): string {
        return static::$cacheKeyResolver
            ? (static::$cacheKeyResolver)($identifier, $model, $user)
            : (string) $identifier;
    }

    /**
     * Resolve the cache repository to use for puts.
     *
     * If static tags are configured (opt-in gate), returns a tagged
     * repository with the union of static and dynamic tags. Otherwise
     * returns the plain repo. Computed per-write because dynamic tags
     * can change per request.
     *
     * Uses Repository::tags() rather than reaching into the raw store
     * via getStore()->tags() so the tagged cache inherits the
     * repository's config and event dispatcher wiring (CachePut events
     * etc. fire correctly on tagged writes).
     */
    protected function resolveWriteCache(): CacheRepository
    {
        $effectiveTags = $this->effectiveCacheTags();

        if ($effectiveTags === []) {
            return $this->cache; /* @phpstan-ignore return.type */
        }

        return $this->cache->tags($effectiveTags); /* @phpstan-ignore method.notFound (tags() is on Repository concrete, not the Repository contract) */
    }

    /**
     * Compute the effective tag set: static config tags ∪ dynamic resolver output.
     *
     * The static tag config is the feature gate: if no static tags are
     * configured, returns an empty array and the dynamic resolver is
     * ignored. This matches where enableCache() performs the store
     * validation — without static tags, the store was never checked for
     * TaggableStore + any-mode support, so there's no safe way to apply
     * dynamic tags either.
     *
     * @return list<string>
     */
    protected function effectiveCacheTags(): array
    {
        if ($this->cacheTags === null || $this->cacheTags === []) {
            return [];
        }

        $dynamic = static::$cacheTagsResolver !== null
            ? (static::$cacheTagsResolver)()
            : [];

        return [...$this->cacheTags, ...$dynamic];
    }

    /**
     * Register this provider's cache descriptor and set up model event
     * listeners for automatic cache invalidation.
     *
     * Uses a descriptor-based registry: each (storeName, prefix) pair is
     * stored under a deterministic hash for its model class so duplicate
     * configs collapse. On save/delete, the listener resolves the cache-key
     * identifier while the model context is available. After commit, it reads
     * the current descriptors, re-resolves each store by name, and calls
     * forget(). Nothing holds a reference to a provider instance — safe
     * against forgetGuards() + re-resolve cycles under Swoole.
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
        $descriptorKey = hash(
            'xxh128',
            ($this->cacheStoreName ?? '') . '|' . $this->cachePrefix . '|' . $modelClass
        );

        static::$cachedProviders[$modelClass][$descriptorKey] = [
            'storeName' => $this->cacheStoreName,
            'prefix' => $this->cachePrefix,
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

        $invalidate = static function (Model&UserContract $user) use ($modelClass): void {
            $id = $user->getAuthIdentifier();
            $identifierSegment = static::resolveCacheKeyIdentifier($id, $modelClass, $user);
            $connection = $user->getConnection();

            $callback = static function () use ($identifierSegment, $modelClass): void {
                $cacheManager = Container::getInstance()->make('cache');

                foreach (static::$cachedProviders[$modelClass] ?? [] as $descriptor) {
                    $cacheManager
                        ->store($descriptor['storeName'])
                        ->forget($descriptor['prefix'] . ':' . $modelClass . ':' . $identifierSegment);
                }
            };

            if ($connection->getTransactionManager() === null && $connection->transactionLevel() === 0) {
                $callback();

                return;
            }

            $connection->afterCommit($callback);
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
     *
     * Boot or tests only. User providers are held by cached guards; runtime use
     * mutates password verification for every subsequent authentication lookup.
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
     * Boot or tests only. User providers are held by cached guards; runtime use
     * changes the model used by every subsequent authentication lookup.
     *
     * @param class-string<Model&UserContract> $model
     */
    public function setModel(string $model): static
    {
        $this->model = $model;

        if ($this->cache !== null) {
            $this->registerCacheInvalidationEvents();
        }

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
     * Boot or tests only. User providers are held by cached guards; runtime use
     * mutates the query applied to every subsequent authentication lookup.
     *
     * @param null|(Closure(Builder):mixed) $queryCallback
     */
    public function withQuery(?Closure $queryCallback = null): static
    {
        $this->queryCallback = $queryCallback;

        return $this;
    }
}
