<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Auth\Access\Gate;
use Hypervel\Auth\Console\ClearResetsCommand;
use Hypervel\Cache\CacheManager;
use Hypervel\Cache\ModelCacheStoreValidator;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Http\Request;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\StrCache;
use InvalidArgumentException;
use UnitEnum;

use function Hypervel\Support\enum_value;

class AuthServiceProvider extends ServiceProvider
{
    private const int MAX_QUERY_ATTRIBUTE_LENGTH = 63;

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->registerAuthenticator();
        $this->registerUserResolver();
        $this->registerAccessGate();
        $this->registerRequestUserResolver();
        $this->registerEventRebindHandler();
        $this->commands([ClearResetsCommand::class]);
    }

    /**
     * Bootstrap the service provider.
     */
    public function boot(
        CacheManager $cache,
        ConfigRepository $config,
        GateContract $gate,
    ): void {
        $this->registerQueryBuilderMacros($gate);

        // boot() runs after every provider has registered the cache binding.
        $cache->allowSerializableClassesUsing(function () use ($config): array {
            $models = [];

            foreach ($this->cachedEloquentProviders($config) as $settings) {
                $models[] = $settings['model'];
            }

            return $models === [] ? [] : [
                ...$models,
                EloquentCollection::class,
                Pivot::class,
                MorphPivot::class,
            ];
        });

        if ($this->app->runningInConsole()) {
            $this->app->booted(
                fn () => $this->validateCachedEloquentProviders($cache, $config),
            );

            return;
        }

        // Worker configuration is reloaded during BeforeWorkerStart.
        $events = $this->app->make('events');
        $events->listen(AfterWorkerStart::class, function (AfterWorkerStart $event): void {
            $this->validateCachedEloquentProviders(
                $this->app->make(CacheManager::class),
                $this->app->make(ConfigRepository::class),
            );
        });
    }

    /**
     * Register the query-aware authorization macros.
     */
    private function registerQueryBuilderMacros(GateContract $gate): void
    {
        // Macro closures are rebound to the Builder; this closure retains provider method access.
        $resolveAbility = fn (UnitEnum|string $ability): array => $this->resolveQueryAbility($ability);

        EloquentBuilder::macro('whereCan', function (
            UnitEnum|string $ability,
            mixed $user = null,
        ) use ($gate): EloquentBuilder {
            /** @var EloquentBuilder $this */
            $queryGate = $user === null ? $gate : $gate->forUser($user);

            return $queryGate->scope($ability, $this);
        });

        EloquentBuilder::macro('withCan', function (
            UnitEnum|string|array $abilities,
            mixed $user = null,
        ) use ($gate, $resolveAbility): EloquentBuilder {
            /** @var EloquentBuilder $this */
            $abilities = is_array($abilities) ? $abilities : [$abilities];

            if ($abilities === []) {
                return $this;
            }

            $resolvedAbilities = [];
            $resolvedAliases = [];

            foreach ($abilities as $ability) {
                [$ability, $alias] = $resolveAbility($ability);

                if (isset($resolvedAliases[$alias])) {
                    throw new InvalidArgumentException(
                        "Authorization result alias [{$alias}] is duplicated. Provide distinct explicit aliases.",
                    );
                }

                $resolvedAbilities[] = [$ability, $alias];
                $resolvedAliases[$alias] = true;
            }

            $queryGate = $user === null ? $gate : $gate->forUser($user);
            $casts = [];

            foreach ($resolvedAbilities as [$ability, $alias]) {
                $this->addSelect([
                    $alias => $queryGate->select($ability, $this),
                ]);
                $casts[$alias] = 'bool';
            }

            return $this->withCasts($casts);
        });
    }

    /**
     * Resolve an ability and its result alias.
     *
     * @return array{string, string}
     */
    private function resolveQueryAbility(UnitEnum|string $ability): array
    {
        $ability = (string) enum_value($ability);
        $segments = preg_split('/\s+as\s+/i', $ability, 2);
        $ability = trim($segments[0]);
        $explicitAlias = isset($segments[1]) ? trim($segments[1]) : null;

        if ($explicitAlias !== null) {
            return [$ability, $this->validateQueryAlias($explicitAlias)];
        }

        $normalizedAbility = trim(StrCache::snake((string) preg_replace(
            '/[^A-Za-z0-9_]+/',
            '_',
            $ability,
        )), '_');
        $alias = 'can_' . $normalizedAbility;

        if (strlen($alias) > self::MAX_QUERY_ATTRIBUTE_LENGTH) {
            throw new InvalidArgumentException(
                "The default authorization result alias for ability [{$ability}] exceeds "
                . self::MAX_QUERY_ATTRIBUTE_LENGTH
                . ' bytes. Provide a shorter explicit alias using [ability as alias].',
            );
        }

        return [$ability, $alias];
    }

    /**
     * Validate an explicit query result alias.
     */
    private function validateQueryAlias(string $alias): string
    {
        if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $alias) !== 1) {
            throw new InvalidArgumentException(
                "Authorization result alias [{$alias}] must start with a letter or underscore "
                . 'and contain only letters, numbers, and underscores.',
            );
        }

        if (strlen($alias) > self::MAX_QUERY_ATTRIBUTE_LENGTH) {
            throw new InvalidArgumentException(
                "Authorization result alias [{$alias}] may not exceed "
                . self::MAX_QUERY_ATTRIBUTE_LENGTH . ' bytes.',
            );
        }

        return $alias;
    }

    /**
     * Get cache-enabled Eloquent provider settings.
     *
     * @return list<array{
     *     name: string,
     *     model: class-string<AuthenticatableContract&Model>,
     *     store: ?string,
     *     usesTags: bool
     * }>
     */
    private function cachedEloquentProviders(ConfigRepository $config): array
    {
        $providers = [];

        foreach ($config->array('auth.providers') as $name => $provider) {
            if (! is_array($provider) || ($provider['driver'] ?? null) !== 'eloquent') {
                continue;
            }

            $cache = $provider['cache'] ?? null;

            if (! is_array($cache) || empty($cache['enabled'])) {
                continue;
            }

            $model = $provider['model'] ?? null;

            if (! is_string($model)
                || ! is_a($model, Model::class, true)
                || ! is_a($model, AuthenticatableContract::class, true)) {
                throw new InvalidArgumentException(
                    sprintf('Authentication provider [%s] model must be an Eloquent authenticatable class.', $name),
                );
            }

            $store = $cache['store'] ?? null;

            if (! is_string($store) && $store !== null) {
                throw new InvalidArgumentException(
                    sprintf('Authentication provider [%s] cache store must be a string or null.', $name),
                );
            }

            // Keep this fallback aligned with CreatesUserProviders::createEloquentProvider().
            $ttl = $cache['ttl'] ?? 300;

            if (! is_int($ttl) || $ttl <= 0) {
                throw new InvalidArgumentException(
                    sprintf('Authentication provider [%s] cache TTL must be a positive integer.', $name),
                );
            }

            $tags = $cache['tags'] ?? null;

            if ($tags !== null && (! is_array($tags)
                || ! array_all($tags, static fn (mixed $tag): bool => is_string($tag)))) {
                throw new InvalidArgumentException(
                    sprintf('Authentication provider [%s] cache tags must be an array of strings or null.', $name),
                );
            }

            $providers[] = [
                'name' => (string) $name,
                'model' => $model,
                'store' => $store,
                'usesTags' => $tags !== null && $tags !== [],
            ];
        }

        return $providers;
    }

    /**
     * Validate every configured cached Eloquent provider.
     */
    private function validateCachedEloquentProviders(
        CacheManager $cache,
        ConfigRepository $config,
    ): void {
        $providers = $this->cachedEloquentProviders($config);

        if ($providers === []) {
            return;
        }

        $validator = $this->app->make(ModelCacheStoreValidator::class);

        foreach ($providers as $settings) {
            $repository = $cache->store($settings['store']);
            $feature = "Auth user provider [{$settings['name']}]";
            $validator->validate(
                $repository,
                $feature,
            );

            if ($settings['usesTags']) {
                $validator->validateAnyModeTags($repository, $feature);
            }
        }
    }

    /**
     * Register the authenticator services.
     */
    protected function registerAuthenticator(): void
    {
        $this->app->singleton('auth', fn ($app) => new AuthManager($app));

        // bind() instead of singleton() because shouldUse() can change the
        // current default guard per-coroutine via Context. The actual guard
        // instances are still cached by AuthManager; this binding just needs
        // to resolve which cached guard is current at call time.
        $this->app->bind('auth.driver', fn ($app) => $app->make('auth')->guard());
    }

    /**
     * Register a resolver for the authenticated user.
     */
    protected function registerUserResolver(): void
    {
        // bind() is required here — each resolution must call the user resolver
        // fresh to get the current coroutine's authenticated user from Context.
        // A singleton would cache the first user and leak it across requests.
        $this->app->bind(AuthenticatableContract::class, fn ($app) => call_user_func($app->make('auth')->userResolver()));
    }

    /**
     * Register the access gate service.
     */
    protected function registerAccessGate(): void
    {
        $this->app->singleton(GateContract::class, function ($app) {
            return new Gate($app, fn () => call_user_func($app->make('auth')->userResolver()));
        });
    }

    /**
     * Set the user resolver on each resolved request instance.
     *
     * Uses callAfterResolving() instead of Laravel's rebinding('request', ...)
     * because Hypervel's request is bound via bind() and resolved from
     * RequestContext — it is not swapped via instance(), so rebinding
     * callbacks would never fire.
     */
    protected function registerRequestUserResolver(): void
    {
        $this->callAfterResolving(Request::class, function (Request $request) {
            $request->setUserResolver(function (?string $guard = null) {
                return call_user_func($this->app->make('auth')->userResolver(), $guard);
            });
        });
    }

    /**
     * Handle the re-binding of the event dispatcher binding.
     */
    protected function registerEventRebindHandler(): void
    {
        $this->app->rebinding('events', function ($app, $dispatcher) {
            if (! $app->resolved('auth')) {
                return;
            }

            $auth = $app->make('auth');

            if ($auth->hasResolvedGuards() === false) {
                return;
            }

            foreach ($auth->getGuards() as $guard) {
                if (method_exists($guard, 'setDispatcher')) {
                    $guard->setDispatcher($dispatcher);
                }
            }
        });
    }
}
