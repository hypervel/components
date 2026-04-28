<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Closure;
use Hypervel\Cache\DatabaseStore;
use Hypervel\Cache\FileStore;
use Hypervel\Cache\RedisStore;
use Hypervel\Cache\StackStore;
use Hypervel\Cache\SwooleStore;
use Hypervel\Cache\TaggableStore;
use Hypervel\Cache\TagMode;
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
     * Static tags applied to every cache write (unioned with whatever the
     * tag resolver returns). Null = no tags configured.
     *
     * @var null|list<string>
     */
    protected ?array $cacheTags = null;

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

        $this->resolveWriteCache()->put($key, $user ?? self::NULL_SENTINEL, $this->cacheTtl);

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
     * @param null|array<string> $tags optional tag names enabling tag-based bulk flush; requires a TaggableStore in TagMode::Any
     *
     * @throws InvalidArgumentException when the resolved store is not supported
     */
    public function enableCache(
        ?string $storeName,
        int $ttl = 300,
        ?string $prefix = 'auth_users',
        ?array $tags = null,
    ): static {
        $cache = Container::getInstance()->make('cache')->store($storeName);
        $this->ensureSupportedAuthCacheStore($cache);

        if ($tags !== null && $tags !== []) {
            $this->ensureTaggableAnyModeStore($cache);
            $this->cacheTags = array_values($tags);
        } else {
            $this->cacheTags = null;
        }

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
     * @param Closure(): list<string> $callback
     */
    public static function resolveUserCacheTagsUsing(Closure $callback): void
    {
        static::$cacheTagsResolver = $callback;
    }

    /**
     * Flush static state for test isolation.
     */
    public static function flushState(): void
    {
        static::$cacheKeyResolver = null;
        static::$cacheTagsResolver = null;
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
     * Ensure the resolved cache store supports tags and is in any-mode.
     *
     * Auth caching only supports tag-based bulk flush via any-mode because:
     *  - any-mode keys are independent of tags, so reads and per-user
     *    forgets stay on the plain repo (no mode branching in the hot path);
     *  - the dynamic tag resolver can't cause cross-context invalidation
     *    bugs since the auto-invalidation listener never touches tags.
     *
     * @throws InvalidArgumentException when the store can't support tags in any-mode
     */
    protected function ensureTaggableAnyModeStore(CacheRepository $cache): void
    {
        $store = $cache->getStore();

        if (! $store instanceof TaggableStore) {
            throw new InvalidArgumentException(sprintf(
                'Auth user caching tags require a TaggableStore; got [%s]. See the auth cache documentation for supported stores.',
                $store::class,
            ));
        }

        if ($store->getTagMode() !== TagMode::Any) {
            throw new InvalidArgumentException(sprintf(
                'Auth user caching tags require a store configured in TagMode::Any; got [%s] in mode [%s]. Configure a separate Redis store with tag_mode=any for auth caching.',
                $store::class,
                $store->getTagMode()->value,
            ));
        }
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
