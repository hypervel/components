<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Drivers;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Database\DatabaseManager;
use Hypervel\Database\DatabaseTransactionRecord;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Pennant\Attributes\Name;
use Hypervel\Pennant\Contracts\CanListStoredFeatures;
use Hypervel\Pennant\Contracts\CanSetManyFeaturesForScopes;
use Hypervel\Pennant\Contracts\DefinesFeaturesExternally;
use Hypervel\Pennant\Contracts\Driver;
use Hypervel\Pennant\Contracts\FeatureScopeable;
use Hypervel\Pennant\Contracts\HasFlushableCache;
use Hypervel\Pennant\Contracts\UsesDatabaseConnection;
use Hypervel\Pennant\Events\AllFeaturesPurged;
use Hypervel\Pennant\Events\DynamicallyRegisteringFeatureClass;
use Hypervel\Pennant\Events\FeatureDeleted;
use Hypervel\Pennant\Events\FeatureResolved;
use Hypervel\Pennant\Events\FeatureRetrieved;
use Hypervel\Pennant\Events\FeaturesPurged;
use Hypervel\Pennant\Events\FeatureUpdated;
use Hypervel\Pennant\Events\FeatureUpdatedForAllScopes;
use Hypervel\Pennant\Events\UnexpectedNullScopeEncountered;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\FeaturePartition;
use Hypervel\Pennant\GlobalScope;
use Hypervel\Pennant\LazilyResolvedFeature;
use Hypervel\Pennant\PendingScopedFeatureInteraction;
use Hypervel\Pennant\PennantState;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Lottery;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use UnitEnum;

use function Hypervel\Pennant\enum_value;

/**
 * @mixin PendingScopedFeatureInteraction
 */
class Decorator implements CanListStoredFeatures, CanSetManyFeaturesForScopes, Driver, HasFlushableCache
{
    use Macroable {
        __call as macroCall;
    }

    /**
     * The driver name.
     */
    protected string $name;

    /**
     * The driver being decorated.
     */
    protected Driver $driver;

    /**
     * The default scope resolver.
     *
     * @var Closure(): mixed
     */
    protected Closure $defaultScopeResolver;

    /**
     * The resolver of the store's current partition.
     *
     * @var null|(Closure(): FeaturePartition)
     */
    protected ?Closure $partitionResolver;

    /**
     * The container instance.
     */
    protected Container $container;

    /**
     * Map of feature names to their implementations.
     *
     * @var array<string, mixed>
     */
    protected array $nameMap = [];

    /**
     * The feature names of feature classes.
     *
     * @var array<class-string, string>
     */
    protected array $classNames = [];

    /**
     * The implementation classes of features defined from feature classes.
     *
     * Other defined values are constants, even when they look like class names or callables.
     *
     * @var array<string, class-string>
     */
    protected array $featureClasses = [];

    /**
     * The reflected resolvers of defined features.
     *
     * @var array<string, ReflectionFunctionAbstract>
     */
    protected array $resolverFunctions = [];

    /**
     * Whether the before hooks of defined features accept a null scope.
     *
     * @var array<string, bool>
     */
    protected array $beforeHookAcceptsNull = [];

    /**
     * Create a new driver decorator instance.
     *
     * Feature values are memoized in the current coroutine's PennantState instead of
     * on the decorator, which every request in the worker shares.
     *
     * @param Closure(): mixed $defaultScopeResolver
     * @param null|(Closure(): FeaturePartition) $partitionResolver
     */
    public function __construct(string $name, Driver $driver, Closure $defaultScopeResolver, ?Closure $partitionResolver, Container $container)
    {
        $this->name = $name;
        $this->driver = $driver;
        $this->defaultScopeResolver = $defaultScopeResolver;
        $this->partitionResolver = $partitionResolver;
        $this->container = $container;
    }

    /**
     * Discover and register the application's feature classes.
     *
     * Boot or tests only. The definitions persist on the store for the worker
     * lifetime and apply to every subsequent request.
     */
    public function discover(string $namespace = 'App\Features', ?string $path = null): void
    {
        $namespace = Str::finish($namespace, '\\');

        Collection::make((new Finder)
            ->files()
            ->name('*.php')
            ->depth(0)
            ->in($path ?? base_path('app/Features')))
            ->each(fn ($file) => $this->define("{$namespace}{$file->getBasename('.php')}"));
    }

    /**
     * Define an initial feature flag state resolver.
     *
     * Boot or tests only. The definition persists on the store for the worker
     * lifetime and applies to every subsequent request.
     *
     * @param class-string|string|UnitEnum $feature
     * @param mixed $resolver a resolver closure, a lottery, or the feature's value
     */
    public function define(UnitEnum|string $feature, mixed $resolver = null): void
    {
        $feature = enum_value($feature);

        if (func_num_args() === 1) {
            [$feature, $resolver] = [
                $this->resolveFeatureName($feature, $this->container->make($feature)),
                new LazilyResolvedFeature($feature),
            ];

            $this->nameMap[$feature] = $resolver->feature;
            $this->featureClasses[$feature] = $resolver->feature;
            $this->classNames[$resolver->feature] = $feature;
        } else {
            $this->nameMap[$feature] = $resolver;

            unset($this->featureClasses[$feature]);
        }

        unset($this->resolverFunctions[$feature], $this->beforeHookAcceptsNull[$feature]);

        $this->driver->define($feature, function (mixed $scope) use ($feature, $resolver): mixed {
            if ($resolver instanceof LazilyResolvedFeature) {
                $resolver = with($this->container->make($resolver->feature), fn ($instance) => method_exists($instance, 'resolve')
                    ? $instance->resolve(...) // @phpstan-ignore callable.nonNativeMethod
                    : $instance(...));
            }

            if (! $resolver instanceof Closure) {
                return $this->resolve($feature, fn () => $resolver, $scope);
            }

            if ($scope !== null) {
                return $this->resolve($feature, $resolver, $scope);
            }

            if ($this->canHandleNullScope($resolver)) {
                return $this->resolve($feature, $resolver, $scope);
            }

            if (Event::hasListeners(UnexpectedNullScopeEncountered::class)) {
                Event::dispatch(new UnexpectedNullScopeEncountered($feature));
            }

            return $this->resolve($feature, fn () => false, $scope);
        });
    }

    /**
     * Resolve the feature value.
     */
    protected function resolve(string $feature, callable $resolver, mixed $scope): mixed
    {
        $value = $resolver($scope);

        $value = $value instanceof Lottery ? $value() : $value;

        if (Event::hasListeners(FeatureResolved::class)) {
            Event::dispatch(new FeatureResolved($feature, $scope, $value));
        }

        return $value;
    }

    /**
     * Determine if the resolver can handle the scope.
     *
     * @param callable|class-string $resolver
     */
    public function isResolverValidForScope(callable|string $resolver, mixed $scope): bool
    {
        return $this->functionAcceptsScope($this->resolverFunction($resolver), $scope);
    }

    /**
     * Reflect the function that resolves a feature.
     *
     * @param callable|class-string $resolver
     */
    protected function resolverFunction(callable|string $resolver): ReflectionFunctionAbstract
    {
        if (is_string($resolver) && class_exists($resolver)) {
            $class = new ReflectionClass($resolver);

            return $class->hasMethod('resolve')
                ? $class->getMethod('resolve')
                : $class->getMethod('__invoke');
        }

        return new ReflectionFunction(Closure::fromCallable($resolver));
    }

    /**
     * Determine if the function's scope parameter accepts the scope.
     */
    protected function functionAcceptsScope(ReflectionFunctionAbstract $function, mixed $scope): bool
    {
        if ($function->getNumberOfParameters() === 0) {
            return true;
        }

        $type = $function->getParameters()[0]->getType();

        if ($type === null) {
            return true;
        }

        return $this->typeAllowsScope($type, $scope, $function);
    }

    /**
     * Determine if the type can handle the scope.
     *
     * @throws RuntimeException when the reflection type is unknown
     */
    protected function typeAllowsScope(ReflectionType $type, mixed $scope, ReflectionFunctionAbstract $function): bool
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $type) {
                if ($this->typeAllowsScope($type, $scope, $function)) {
                    return true;
                }
            }

            return false;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $type) {
                if (! $this->typeAllowsScope($type, $scope, $function)) {
                    return false;
                }
            }

            return true;
        }

        if ($type instanceof ReflectionNamedType) {
            if ($scope === null) {
                return $this->canHandleNullScope($function);
            }

            $typeName = $type->getName();

            if (! $type->isBuiltin()) {
                $class = $function instanceof ReflectionMethod
                    ? $function->getDeclaringClass()
                    : $function->getClosureScopeClass();

                $typeName = match (strtolower($typeName)) {
                    'self' => $class?->getName(),
                    'parent' => ($class?->getParentClass() ?: null)?->getName(),
                    default => $typeName,
                };

                return $typeName !== null && $scope instanceof $typeName;
            }

            // Under strict types an int is still accepted by a float parameter.
            return match ($typeName) {
                'mixed' => true,
                'object' => is_object($scope),
                'iterable' => is_iterable($scope),
                'callable' => is_callable($scope),
                'array' => is_array($scope),
                'string' => is_string($scope),
                'int' => is_int($scope),
                'float' => is_float($scope) || is_int($scope),
                'bool' => is_bool($scope),
                'true' => $scope === true,
                'false' => $scope === false,
                default => false,
            };
        }

        throw new RuntimeException('Unknown reflection type encountered.');
    }

    /**
     * Determine if the resolver accepts null scope.
     */
    protected function canHandleNullScope(callable|ReflectionFunctionAbstract $resolver): bool
    {
        $function = $resolver instanceof ReflectionFunctionAbstract
            ? $resolver
            : new ReflectionFunction(Closure::fromCallable($resolver));

        return $function->getNumberOfParameters() === 0
            || ! $function->getParameters()[0]->hasType()
            || $function->getParameters()[0]->getType()->allowsNull();
    }

    /**
     * Retrieve the names of all defined features.
     *
     * @return array<string>
     */
    public function defined(): array
    {
        return $this->driver->defined();
    }

    /**
     * Retrieve the names of all stored features.
     *
     * @return array<string>
     */
    public function stored(): array
    {
        if (! $this->driver instanceof CanListStoredFeatures) {
            throw new RuntimeException("The [{$this->name}] driver does not support listing stored features.");
        }

        return $this->driver->stored();
    }

    /**
     * Get multiple feature flag values.
     *
     * @internal
     *
     * @param array<int|string, mixed>|string $features
     * @return array<string, array<int, mixed>>
     */
    public function getAll(array|string $features): array
    {
        $features = $this->normalizeFeaturesToLoad($features);

        if ($features === []) {
            return [];
        }

        return $this->resolveFeatures($features, PennantState::current(), $this->connectionName(), $this->partitionKey());
    }

    /**
     * Get multiple feature flag values that are missing.
     *
     * @internal
     *
     * @param array<int|string, mixed>|string $features
     * @return array<string, array<int, mixed>>
     */
    public function getAllMissing(array|string $features): array
    {
        $features = $this->normalizeFeaturesToLoad($features);
        $state = PennantState::current();
        $connection = $this->connectionName();
        $partition = $this->partitionKey();

        foreach ($features as $feature => $scopes) {
            foreach ($scopes as $index => $scope) {
                if ($this->isCached($state, $connection, $partition, (string) $feature, Feature::serializeScope($scope))) {
                    unset($features[$feature][$index]);
                }
            }

            if ($features[$feature] === []) {
                unset($features[$feature]);
            }
        }

        if ($features === []) {
            return [];
        }

        return $this->resolveFeatures($features, $state, $connection, $partition);
    }

    /**
     * Resolve the values of the given features through their before hooks or the driver, and memoize them.
     *
     * @param array<string, array<int, mixed>> $features
     * @return array<string, array<int, mixed>>
     */
    protected function resolveFeatures(array $features, PennantState $state, string $connection, string $partition): array
    {
        $results = $features;
        $unresolved = [];

        foreach ($features as $feature => $scopes) {
            $feature = (string) $feature;

            if (! $this->hasBeforeHook($feature)) {
                $unresolved[$feature] = $scopes;

                continue;
            }

            $before = $this->container->make($this->implementationClass($feature))->before(...);

            foreach ($scopes as $index => $scope) {
                $value = $this->resolveBeforeHook($feature, $scope, $before);

                if ($value !== null) {
                    $results[$feature][$index] = $value;
                } else {
                    $unresolved[$feature][$index] = $scope;
                }
            }
        }

        if ($unresolved !== []) {
            foreach ($this->driver->getAll($unresolved) as $feature => $values) {
                foreach ($values as $index => $value) {
                    $results[$feature][$index] = $value;
                }
            }
        }

        $this->trackTransaction($state, $connection);

        foreach ($results as $feature => $values) {
            foreach ($values as $index => $value) {
                $this->putInCache($state, $connection, $partition, (string) $feature, Feature::serializeScope($features[$feature][$index]), $value);
            }
        }

        return $results;
    }

    /**
     * Normalize the features to load.
     *
     * @param array<int|string, mixed>|string $features
     * @return array<string, array<int, mixed>>
     */
    protected function normalizeFeaturesToLoad(array|string $features): array
    {
        $normalized = [];
        $defaultScopes = null;

        foreach (Arr::wrap($features) as $key => $value) {
            if (is_array($value) && array_key_exists('feature', $value) && array_key_exists('scope', $value)) {
                [$feature, $scopes] = [$value['feature'], $value['scope']];
            } elseif (is_int($key)) {
                [$feature, $scopes] = [$value, $defaultScopes ??= [$this->defaultScope()]];
            } else {
                [$feature, $scopes] = [$key, $value];
            }

            $scopes = $scopes instanceof Enumerable ? $scopes->all() : Arr::wrap($scopes);

            $normalized[$this->resolveFeature($feature)] = array_map($this->resolveScope(...), $scopes);
        }

        return $normalized;
    }

    /**
     * Retrieve a feature flag's value.
     *
     * @internal
     */
    public function get(UnitEnum|string $feature, mixed $scope): mixed
    {
        $feature = $this->resolveFeature($feature);

        $scope = $this->resolveScope($scope);

        $state = PennantState::current();
        $connection = $this->connectionName();
        $partition = $this->partitionKey();
        $scopeKey = Feature::serializeScope($scope);

        if ($this->isCached($state, $connection, $partition, $feature, $scopeKey)) {
            $value = $state->values[$connection][$this->name][$partition][$feature][$scopeKey];
        } else {
            $value = $this->hasBeforeHook($feature)
                ? $this->resolveBeforeHook($feature, $scope, $this->container->make($this->implementationClass($feature))->before(...))
                : null;

            $value ??= $this->driver->get($feature, $scope);

            $this->trackTransaction($state, $connection);

            $this->putInCache($state, $connection, $partition, $feature, $scopeKey, $value);
        }

        if (Event::hasListeners(FeatureRetrieved::class)) {
            Event::dispatch(new FeatureRetrieved($feature, $scope, $value));
        }

        return $value;
    }

    /**
     * Resolve the before hook value.
     */
    protected function resolveBeforeHook(string $feature, mixed $scope, callable $hook): mixed
    {
        if ($scope === null && ! ($this->beforeHookAcceptsNull[$feature] ??= $this->canHandleNullScope($hook))) {
            if (Event::hasListeners(UnexpectedNullScopeEncountered::class)) {
                Event::dispatch(new UnexpectedNullScopeEncountered($feature));
            }

            return null;
        }

        return $hook($scope);
    }

    /**
     * Set a feature flag's value.
     *
     * @internal
     */
    public function set(UnitEnum|string $feature, mixed $scope, mixed $value): void
    {
        $feature = $this->resolveFeature($feature);

        $scope = $this->resolveScope($scope);

        $state = PennantState::current();
        $connection = $this->connectionName();
        $partition = $this->partitionKey();
        $scopeKey = Feature::serializeScope($scope);

        $this->removeFromCache($state, $connection, $partition, $feature, $scopeKey);

        $this->driver->set($feature, $scope, $value);

        $this->trackTransaction($state, $connection);

        $this->putInCache($state, $connection, $partition, $feature, $scopeKey, $value);

        if (Event::hasListeners(FeatureUpdated::class)) {
            Event::dispatch(new FeatureUpdated($feature, $scope, $value));
        }
    }

    /**
     * Set multiple feature flag values.
     *
     * @internal
     *
     * @param list<array{ feature: string|UnitEnum, scope: mixed, value: mixed }> $features
     */
    public function setAll(array $features): void
    {
        $features = array_map(fn ($feature) => [
            'feature' => $this->resolveFeature($feature['feature']),
            'scope' => $this->resolveScope($feature['scope']),
            'value' => $feature['value'],
        ], $features);

        $state = PennantState::current();
        $connection = $this->connectionName();
        $partition = $this->partitionKey();
        $scopeKeys = [];

        foreach ($features as $index => $feature) {
            $scopeKeys[$index] = Feature::serializeScope($feature['scope']);

            $this->removeFromCache($state, $connection, $partition, $feature['feature'], $scopeKeys[$index]);
        }

        $updated = false;

        if ($this->driver instanceof CanSetManyFeaturesForScopes) {
            $this->driver->setAll($features);

            $updated = true;
        }

        $this->trackTransaction($state, $connection);

        $dispatch = Event::hasListeners(FeatureUpdated::class);

        foreach ($features as $index => $feature) {
            if (! $updated) {
                $this->driver->set($feature['feature'], $feature['scope'], $feature['value']);
            }

            $this->putInCache($state, $connection, $partition, $feature['feature'], $scopeKeys[$index], $feature['value']);

            if ($dispatch) {
                Event::dispatch(new FeatureUpdated(
                    $feature['feature'],
                    $feature['scope'],
                    $feature['value'],
                ));
            }
        }
    }

    /**
     * Create a pending feature interaction for the global scope, ignoring the default scope resolver.
     */
    public function globally(): PendingScopedFeatureInteraction
    {
        return $this->for(new GlobalScope);
    }

    /**
     * Activate the feature for everyone.
     *
     * @param array<string|UnitEnum>|string|UnitEnum $feature
     */
    public function activateForEveryone(UnitEnum|string|array $feature, mixed $value = true): void
    {
        Collection::wrap($feature)
            ->each(fn ($name) => $this->setForAllScopes($name, $value));
    }

    /**
     * Deactivate the feature for everyone.
     *
     * @param array<string|UnitEnum>|string|UnitEnum $feature
     */
    public function deactivateForEveryone(UnitEnum|string|array $feature): void
    {
        Collection::wrap($feature)
            ->each(fn ($name) => $this->setForAllScopes($name, false));
    }

    /**
     * Set a feature flag's value for all scopes.
     *
     * @internal
     */
    public function setForAllScopes(UnitEnum|string $feature, mixed $value): void
    {
        $feature = $this->resolveFeature($feature);

        unset(PennantState::current()->values[$this->connectionName()][$this->name][$this->partitionKey()][$feature]);

        $this->driver->setForAllScopes($feature, $value);

        if (Event::hasListeners(FeatureUpdatedForAllScopes::class)) {
            Event::dispatch(new FeatureUpdatedForAllScopes($feature, $value));
        }
    }

    /**
     * Delete a feature flag's value.
     *
     * @internal
     */
    public function delete(UnitEnum|string $feature, mixed $scope): void
    {
        $feature = $this->resolveFeature($feature);

        $scope = $this->resolveScope($scope);

        $this->removeFromCache(PennantState::current(), $this->connectionName(), $this->partitionKey(), $feature, Feature::serializeScope($scope));

        $this->driver->delete($feature, $scope);

        if (Event::hasListeners(FeatureDeleted::class)) {
            Event::dispatch(new FeatureDeleted($feature, $scope));
        }
    }

    /**
     * Purge the given feature from storage.
     *
     * @param null|array<string|UnitEnum>|string|UnitEnum $features
     */
    public function purge(UnitEnum|string|array|null $features = null): void
    {
        $state = PennantState::current();
        $connection = $this->connectionName();
        $partition = $this->partitionKey();

        if ($features === null) {
            unset($state->values[$connection][$this->name][$partition]);

            $this->driver->purge(null);

            if (Event::hasListeners(AllFeaturesPurged::class)) {
                Event::dispatch(new AllFeaturesPurged);
            }
        } else {
            $features = array_map($this->resolveFeature(...), Arr::wrap($features));

            foreach ($features as $feature) {
                unset($state->values[$connection][$this->name][$partition][$feature]);
            }

            $this->driver->purge($features);

            if (Event::hasListeners(FeaturesPurged::class)) {
                Event::dispatch(new FeaturesPurged($features));
            }
        }
    }

    /**
     * Retrieve the feature's name.
     */
    public function name(UnitEnum|string $feature): string
    {
        return $this->resolveFeature($feature);
    }

    /**
     * Retrieve the map of feature names to their implementations.
     *
     * @return array<string, mixed>
     */
    public function nameMap(): array
    {
        return $this->nameMap;
    }

    /**
     * Retrieve the feature's class.
     */
    public function instance(UnitEnum|string $name): mixed
    {
        $name = enum_value($name);

        if (isset($this->featureClasses[$name])) {
            return $this->container->make($this->featureClasses[$name]);
        }

        if (! array_key_exists($name, $this->nameMap)) {
            return class_exists($name) ? $this->container->make($name) : fn () => $name;
        }

        $feature = $this->nameMap[$name];

        if ($feature instanceof Closure || $feature instanceof Lottery) {
            return $feature;
        }

        return fn () => $feature;
    }

    /**
     * Retrieve the defined features for the given scope.
     *
     * @internal
     *
     * @return Collection<int, string>
     */
    public function definedFeaturesForScope(mixed $scope): Collection
    {
        $scope = $this->resolveScope($scope);

        if ($this->driver instanceof DefinesFeaturesExternally) {
            return new Collection($this->driver->definedFeaturesForScope($scope));
        }

        $defined = array_flip($this->defined());
        $features = [];

        foreach ($this->nameMap as $feature => $resolver) {
            if (! isset($defined[$feature])) {
                continue;
            }

            if (isset($this->featureClasses[$feature]) || $resolver instanceof Closure) {
                $function = $this->resolverFunctions[$feature] ??= $this->resolverFunction($resolver);

                if (! $this->functionAcceptsScope($function, $scope)) {
                    continue;
                }
            }

            $features[] = (string) $feature;
        }

        return new Collection($features);
    }

    /**
     * Resolve the feature name and ensure it is defined.
     */
    protected function resolveFeature(UnitEnum|string $feature): string
    {
        $feature = (string) enum_value($feature);

        if (array_key_exists($feature, $this->nameMap)) {
            return $feature;
        }

        if (isset($this->classNames[$feature])) {
            return $this->classNames[$feature];
        }

        return $this->shouldDynamicallyDefine($feature)
            ? $this->ensureDynamicFeatureIsDefined($feature)
            : $feature;
    }

    /**
     * Determine if the feature should be dynamically defined.
     */
    protected function shouldDynamicallyDefine(string $feature): bool
    {
        return ! in_array($feature, $this->defined(), true)
            && class_exists($feature)
            && (method_exists($feature, 'resolve') || method_exists($feature, '__invoke'));
    }

    /**
     * Dynamically define the feature.
     *
     * @param class-string $feature
     */
    protected function ensureDynamicFeatureIsDefined(string $feature): string
    {
        return $this->classNames[$feature] = tap($this->resolveFeatureName($feature, $this->container->make($feature)), function ($name) use ($feature) {
            if (! in_array($name, $this->defined(), true)) {
                if (Event::hasListeners(DynamicallyRegisteringFeatureClass::class)) {
                    Event::dispatch(new DynamicallyRegisteringFeatureClass($feature));
                }

                $this->define($feature);
            }
        });
    }

    /**
     * Determine if the given feature has a before hook.
     */
    protected function hasBeforeHook(string $feature): bool
    {
        $implementation = $this->implementationClass($feature);

        return $implementation !== null && method_exists($implementation, 'before');
    }

    /**
     * Retrieve the implementation feature class for the given feature name.
     *
     * @return null|class-string
     */
    protected function implementationClass(string $feature): ?string
    {
        return $this->featureClasses[$feature] ?? null;
    }

    /**
     * Resolve the name for a feature class.
     */
    protected function resolveFeatureName(string $class, object $instance): string
    {
        $attribute = (new ReflectionClass($instance))->getAttributes(Name::class)[0] ?? null;

        if ($attribute !== null) {
            return $attribute->newInstance()->name;
        }

        return $instance->name ?? $class;
    }

    /**
     * Resolve the scope.
     */
    protected function resolveScope(mixed $scope): mixed
    {
        return $scope instanceof FeatureScopeable
            ? $scope->toFeatureIdentifier($this->name)
            : $scope;
    }

    /**
     * Determine if a feature's value is in the cache for the given scope.
     */
    protected function isCached(PennantState $state, string $connection, string $partition, string $feature, string $scope): bool
    {
        return array_key_exists($scope, $state->values[$connection][$this->name][$partition][$feature] ?? []);
    }

    /**
     * Put the given feature's value into the cache.
     */
    protected function putInCache(PennantState $state, string $connection, string $partition, string $feature, string $scope, mixed $value): void
    {
        $state->values[$connection][$this->name][$partition][$feature][$scope] = $value;
    }

    /**
     * Remove the given feature's value from the cache.
     */
    protected function removeFromCache(PennantState $state, string $connection, string $partition, string $feature, string $scope): void
    {
        unset($state->values[$connection][$this->name][$partition][$feature][$scope]);
    }

    /**
     * Forget the values memoized for the connection if its current transaction rolls back.
     *
     * Values read or written inside a transaction may never be committed.
     */
    protected function trackTransaction(PennantState $state, string $connection): void
    {
        if ($connection === '') {
            return;
        }

        /** @var DatabaseManager $db */
        $db = $this->container->make('db');

        if (! $db->hasApplicableTransaction($connection)) {
            return;
        }

        $owner = $db->connection($connection)->getNameWithReadWriteType();

        /** @var DatabaseTransactionsManager $transactions */
        $transactions = $this->container->make('db.transactions');

        $state->forgetOnRollback(
            $transactions->callbackApplicableTransactions()->last(fn (DatabaseTransactionRecord $record) => $record->connection === $owner),
            $connection,
        );
    }

    /**
     * Get the name of the connection the driver currently uses, or an empty string for drivers without one.
     */
    protected function connectionName(): string
    {
        return $this->driver instanceof UsesDatabaseConnection ? $this->driver->connectionName() : '';
    }

    /**
     * Get the key of the store's current partition.
     */
    protected function partitionKey(): string
    {
        return $this->partitionResolver === null ? '' : ($this->partitionResolver)()->key();
    }

    /**
     * Retrieve the default scope.
     */
    protected function defaultScope(): mixed
    {
        return ($this->defaultScopeResolver)();
    }

    /**
     * Flush the in-memory cache of feature values.
     *
     * Only the current coroutine's values are flushed.
     */
    public function flushCache(): void
    {
        $state = PennantState::current();

        foreach (array_keys($state->values) as $connection) {
            unset($state->values[$connection][$this->name]);
        }

        if ($this->driver instanceof HasFlushableCache) {
            $this->driver->flushCache();
        }
    }

    /**
     * Get the underlying feature driver.
     */
    public function getDriver(): Driver
    {
        return $this->driver;
    }

    /**
     * Set the container instance used by the decorator.
     *
     * Tests only. The container persists on the store for the worker lifetime.
     */
    public function setContainer(Container $container): static
    {
        $this->container = $container;

        // Feature class names and before hooks are read from container-built instances.
        $this->classNames = [];
        $this->beforeHookAcceptsNull = [];

        return $this;
    }

    /**
     * Dynamically create a pending feature interaction.
     *
     * @param array<mixed> $parameters
     */
    public function __call(string $name, array $parameters): mixed
    {
        if (static::hasMacro($name)) {
            return $this->macroCall($name, $parameters);
        }

        $interaction = new PendingScopedFeatureInteraction($this);

        if ($name !== 'for' && ($scope = $this->defaultScope()) !== null) {
            $interaction->for($scope);
        }

        return $interaction->{$name}(...$parameters);
    }
}
