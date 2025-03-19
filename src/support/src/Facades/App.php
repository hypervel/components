<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static string version()
 * @method static void bootstrapWith(string[] $bootstrappers)
 * @method static void beforeBootstrapping(string $bootstrapper, \Closure $callback)
 * @method static void afterBootstrapping(string $bootstrapper, \Closure $callback)
 * @method static bool hasBeenBootstrapped()
 * @method static \Hypervel\Foundation\Application setBasePath(string $basePath)
 * @method static string path(string $path = '')
 * @method static string basePath(string $path = '')
 * @method static string resourcePath(string $path = '')
 * @method static string viewPath(string $path = '')
 * @method static string joinPaths(string $basePath, string $path = '')
 * @method static string|bool environment(array|string ...$environments)
 * @method static bool isLocal()
 * @method static bool isProduction()
 * @method static string detectEnvironment()
 * @method static bool runningUnitTests()
 * @method static bool hasDebugModeEnabled()
 * @method static \Hypervel\Support\ServiceProvider register(\Hypervel\Support\ServiceProvider|string $provider, bool $force = false)
 * @method static \Hypervel\Support\ServiceProvider|null getProvider(\Hypervel\Support\ServiceProvider|string $provider)
 * @method static array getProviders(\Hypervel\Support\ServiceProvider|string $provider)
 * @method static \Hypervel\Support\ServiceProvider resolveProvider(string $provider)
 * @method static bool isBooted()
 * @method static void boot()
 * @method static void abort(int $code, string $message = '', array $headers = [])
 * @method static array getLoadedProviders()
 * @method static bool providerIsLoaded(string $provider)
 * @method static string getLocale()
 * @method static bool isLocale(string $locale)
 * @method static string currentLocale()
 * @method static string getFallbackLocale()
 * @method static void setLocale(string $locale)
 * @method static string getNamespace()
 * @method static void make(string $name, array $parameters = [])
 * @method static void get(string $id)
 * @method static void set(string $name, mixed $entry)
 * @method static void unbind(string $name)
 * @method static void remove(string $name)
 * @method static bool bound(string $abstract)
 * @method static bool has(mixed|string $id)
 * @method static bool isAlias(string $name)
 * @method static void bind(string $abstract, null|\Closure|string $concrete = null)
 * @method static bool hasMethodBinding(string $method)
 * @method static void bindMethod(array|string $method, \Closure $callback)
 * @method static mixed callMethodBinding(string $method, mixed $instance)
 * @method static void bindIf(string $abstract, null|\Closure|string $concrete = null)
 * @method static void extend(string $abstract, \Closure $closure)
 * @method static mixed instance(string $abstract, mixed $instance)
 * @method static void alias(string $abstract, string $alias)
 * @method static mixed rebinding(string $abstract, \Closure $callback)
 * @method static mixed refresh(string $abstract, mixed $target, string $method)
 * @method static mixed call(callable|string $callback, array $parameters = [], string|null $defaultMethod = null)
 * @method static \Closure factory(string $abstract)
 * @method static mixed makeWith(callable|string $abstract, array $parameters = [])
 * @method static void beforeResolving(\Closure|string $abstract, \Closure|null $callback = null)
 * @method static void resolving(\Closure|string $abstract, \Closure|null $callback = null)
 * @method static void afterResolving(\Closure|string $abstract, \Closure|null $callback = null)
 * @method static array getBindings()
 * @method static string getAlias(string $abstract)
 * @method static void forgetExtenders(string $abstract)
 * @method static void forgetInstance(string $abstract)
 * @method static void forgetInstances()
 * @method static void flush()
 * @method static \Hypervel\Container\Contracts\Container getInstance()
 * @method static \Hypervel\Container\Contracts\Container setInstance(\Hypervel\Container\Contracts\Container $container)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 *
 * @see \Hypervel\Foundation\Application
 */
class App extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'app';
    }
}
