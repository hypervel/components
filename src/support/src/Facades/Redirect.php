<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static \Hypervel\Http\RedirectResponse back(int $status = 302, array $headers = [], mixed $fallback = false)
 * @method static \Hypervel\Http\RedirectResponse refresh(int $status = 302, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse guest(string $path, int $status = 302, array $headers = [], bool|null $secure = null)
 * @method static \Hypervel\Http\RedirectResponse intended(mixed $default = '/', int $status = 302, array $headers = [], bool|null $secure = null)
 * @method static \Hypervel\Http\RedirectResponse to(string $path, int $status = 302, array $headers = [], bool|null $secure = null)
 * @method static \Hypervel\Http\RedirectResponse away(string $path, int $status = 302, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse secure(string $path, int $status = 302, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse route(\BackedEnum|string $route, mixed $parameters = [], int $status = 302, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse signedRoute(\BackedEnum|string $route, mixed $parameters = [], \DateTimeInterface|\DateInterval|int|null $expiration = null, int $status = 302, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse temporarySignedRoute(\BackedEnum|string $route, \DateTimeInterface|\DateInterval|int|null $expiration, mixed $parameters = [], int $status = 302, array $headers = [])
 * @method static \Hypervel\Http\RedirectResponse action(string|array $action, mixed $parameters = [], int $status = 302, array $headers = [])
 * @method static \Hypervel\Routing\UrlGenerator getUrlGenerator()
 * @method static void setSession(\Hypervel\Session\Store $session)
 * @method static string|null getIntendedUrl()
 * @method static \Hypervel\Routing\Redirector setIntendedUrl(string $url)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \Hypervel\Routing\Redirector
 */
class Redirect extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'redirect';
    }
}
