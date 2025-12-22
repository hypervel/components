<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Router\Contracts\UrlGenerator as UrlGeneratorContract;

/**
 * @method static string route(string $name, array $parameters = [], bool $absolute = true, string $server = 'http')
 * @method static string to(string $path, array $extra = [], bool|null $secure = null)
 * @method static string query(string $path, array $query = [], array $extra = [], bool|null $secure = null)
 * @method static string secure(string $path, array $extra = [])
 * @method static string asset(string $path, bool|null $secure = null)
 * @method static string secureAsset(string $path)
 * @method static string assetFrom(string $root, string $path, bool|null $secure = null)
 * @method static string formatScheme(bool|null $secure = null)
 * @method static string signedRoute(\BackedEnum|string $name, array $parameters = [], \DateInterval|\DateTimeInterface|int|null $expiration = null, bool $absolute = true, string $server = 'http')
 * @method static string temporarySignedRoute(\BackedEnum|string $name, \DateInterval|\DateTimeInterface|int|null $expiration, array $parameters = [], bool $absolute = true, string $server = 'http')
 * @method static bool hasValidSignature(\Hyperf\HttpServer\Contract\RequestInterface $request, bool $absolute = true, array $ignoreQuery = [])
 * @method static bool hasValidRelativeSignature(\Hyperf\HttpServer\Contract\RequestInterface $request, array $ignoreQuery = [])
 * @method static bool hasCorrectSignature(\Hyperf\HttpServer\Contract\RequestInterface $request, bool $absolute = true, array $ignoreQuery = [])
 * @method static bool signatureHasNotExpired(\Hyperf\HttpServer\Contract\RequestInterface $request)
 * @method static string full()
 * @method static string current()
 * @method static string previous(string|bool $fallback = false)
 * @method static string previousPath(mixed $fallback = false)
 * @method static string format(string $root, string $path)
 * @method static bool isValidUrl(string $path)
 * @method static void forceScheme(string|null $scheme)
 * @method static void forceHttps(bool $force = true)
 * @method static void useOrigin(string|null $root)
 * @method static \Hypervel\Router\UrlGenerator formatHostUsing(\Closure $callback)
 * @method static \Hypervel\Router\UrlGenerator formatPathUsing(\Closure $callback)
 * @method static \Hypervel\Router\UrlGenerator setSignedKey(string|null $signedKey = null)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 *
 * @see \Hypervel\Router\UrlGenerator
 */
class URL extends Facade
{
    protected static function getFacadeAccessor()
    {
        return UrlGeneratorContract::class;
    }
}
