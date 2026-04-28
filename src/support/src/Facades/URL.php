<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static string full()
 * @method static string current()
 * @method static string previous(string|bool $fallback = false)
 * @method static string previousPath(string|bool $fallback = false)
 * @method static string to(string $path, array|string $extra = [], bool|null $secure = null)
 * @method static string query(string $path, array $query = [], array|string $extra = [], bool|null $secure = null)
 * @method static string secure(string $path, array $parameters = [])
 * @method static string asset(string $path, bool|null $secure = null)
 * @method static string secureAsset(string $path)
 * @method static string assetFrom(string $root, string $path, bool|null $secure = null)
 * @method static string formatScheme(bool|null $secure = null)
 * @method static string signedRoute(\BackedEnum|string $name, mixed $parameters = [], \DateInterval|\DateTimeInterface|int|null $expiration = null, bool $absolute = true)
 * @method static string temporarySignedRoute(\BackedEnum|string $name, \DateInterval|\DateTimeInterface|int $expiration, array $parameters = [], bool $absolute = true)
 * @method static bool hasValidSignature(\Hypervel\Http\Request $request, bool $absolute = true, \Closure|array $ignoreQuery = [])
 * @method static bool hasValidRelativeSignature(\Hypervel\Http\Request $request, \Closure|array $ignoreQuery = [])
 * @method static bool hasCorrectSignature(\Hypervel\Http\Request $request, bool $absolute = true, \Closure|array $ignoreQuery = [])
 * @method static bool signatureHasNotExpired(\Hypervel\Http\Request $request)
 * @method static string route(\BackedEnum|string $name, mixed $parameters = [], bool $absolute = true)
 * @method static string toRoute(\Hypervel\Routing\Route $route, mixed $parameters, bool $absolute)
 * @method static string action(array|string $action, array|string $parameters = [], bool $absolute = true)
 * @method static array formatParameters(mixed $parameters)
 * @method static string formatRoot(string $scheme, string|null $root = null)
 * @method static string format(string $root, string $path, \Hypervel\Routing\Route|null $route = null)
 * @method static bool isValidUrl(string $path)
 * @method static void defaults(array $defaults)
 * @method static array getDefaultParameters()
 * @method static void forceScheme(string|null $scheme)
 * @method static void forceHttps(bool $force = true)
 * @method static void useOrigin(string|null $root)
 * @method static void useAssetOrigin(string|null $root)
 * @method static void flushRequestState()
 * @method static \Hypervel\Routing\UrlGenerator formatHostUsing(\Closure $callback)
 * @method static \Hypervel\Routing\UrlGenerator formatPathUsing(\Closure $callback)
 * @method static \Closure pathFormatter()
 * @method static \Hypervel\Http\Request getRequest()
 * @method static void setRequest(\Hypervel\Http\Request $request)
 * @method static \Hypervel\Routing\UrlGenerator setRoutes(\Hypervel\Routing\RouteCollectionInterface $routes)
 * @method static \Hypervel\Routing\UrlGenerator setSessionResolver(callable $sessionResolver)
 * @method static \Hypervel\Routing\UrlGenerator setKeyResolver(callable $keyResolver)
 * @method static \Hypervel\Routing\UrlGenerator withKeyResolver(callable $keyResolver)
 * @method static \Hypervel\Routing\UrlGenerator resolveMissingNamedRoutesUsing(callable $missingNamedRouteResolver)
 * @method static string|null getRootControllerNamespace()
 * @method static \Hypervel\Routing\UrlGenerator setRootControllerNamespace(string $rootNamespace)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \Hypervel\Routing\UrlGenerator
 */
class URL extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'url';
    }
}
