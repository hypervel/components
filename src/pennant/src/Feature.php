<?php

declare(strict_types=1);

namespace Hypervel\Pennant;

use Hypervel\Support\Facades\Facade;

/**
 * @method static \Hypervel\Pennant\Drivers\ArrayDriver createArrayDriver(array<string, mixed> $config, string $name)
 * @method static \Hypervel\Pennant\Drivers\DatabaseDriver createDatabaseDriver(array<string, mixed> $config, string $name)
 * @method static \Hypervel\Pennant\Drivers\Decorator driver(string|null $name = null)
 * @method static \Hypervel\Pennant\FeatureManager extend(string $driver, \Closure $callback)
 * @method static void flushCache()
 * @method static \Hypervel\Pennant\FeatureManager forgetDriver(null|array<string>|string $name = null)
 * @method static \Hypervel\Pennant\FeatureManager forgetDrivers()
 * @method static string getDefaultDriver()
 * @method static void resolvePartitionUsing(string $column, \Closure $resolver)
 * @method static void resolveScopeUsing(callable $resolver)
 * @method static string serializeScope(mixed $scope)
 * @method static \Hypervel\Pennant\FeatureManager setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static void setDefaultDriver(string $name)
 * @method static \Hypervel\Pennant\Drivers\Decorator store(string|null $store = null)
 * @method static \Hypervel\Pennant\FeatureManager useMorphMap(bool $value = true)
 * @method static void activateForEveryone(array<string|\UnitEnum>|string|\UnitEnum $feature, mixed $value = true)
 * @method static void deactivateForEveryone(array<string|\UnitEnum>|string|\UnitEnum $feature)
 * @method static void define(string|\UnitEnum $feature, mixed $resolver = null)
 * @method static array<string> defined()
 * @method static void discover(string $namespace = 'App\\Features', string|null $path = null)
 * @method static void flushMacros()
 * @method static \Hypervel\Pennant\Contracts\Driver getDriver()
 * @method static \Hypervel\Pennant\PendingScopedFeatureInteraction globally()
 * @method static bool hasMacro(string $name)
 * @method static mixed instance(\UnitEnum|string $name)
 * @method static bool isResolverValidForScope(callable|string $resolver, mixed $scope)
 * @method static void macro(string $name, callable|object $macro)
 * @method static mixed macroCall(string $method, array $parameters)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static string name(\UnitEnum|string $feature)
 * @method static array<string, mixed> nameMap()
 * @method static void purge(null|array<string|\UnitEnum>|string|\UnitEnum $features = null)
 * @method static array<string> stored()
 * @method static void activate(array<int, string|\UnitEnum>|string|\UnitEnum $feature, mixed $value = true)
 * @method static bool active(\UnitEnum|string $feature)
 * @method static array<string, mixed> all()
 * @method static bool allAreActive(array<int, string|\UnitEnum> $features)
 * @method static bool allAreInactive(array<int, string|\UnitEnum> $features)
 * @method static void deactivate(array<int, string|\UnitEnum>|string|\UnitEnum $feature)
 * @method static \Hypervel\Pennant\PendingScopedFeatureInteraction for(mixed $scope)
 * @method static void forget(array<int, string|\UnitEnum>|string|\UnitEnum $features)
 * @method static bool inactive(\UnitEnum|string $feature)
 * @method static array<string, array<int, mixed>> load(array<int, string|\UnitEnum>|string|\UnitEnum $features)
 * @method static array<string, array<int, mixed>> loadAll()
 * @method static array<string, array<int, mixed>> loadMissing(array<int, string|\UnitEnum>|string|\UnitEnum $features)
 * @method static bool someAreActive(array<int, string|\UnitEnum> $features)
 * @method static bool someAreInactive(array<int, string|\UnitEnum> $features)
 * @method static mixed unless(\UnitEnum|string $feature, \Closure $whenInactive, \Closure|null $whenActive = null)
 * @method static mixed value(\UnitEnum|string $feature)
 * @method static array<string, mixed> values(array<int, string|\UnitEnum> $features)
 * @method static mixed when(\UnitEnum|string $feature, \Closure $whenActive, \Closure|null $whenInactive = null)
 *
 * @see \Hypervel\Pennant\FeatureManager
 */
class Feature extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return FeatureManager::class;
    }
}
