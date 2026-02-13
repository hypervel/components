<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Facades;

use Hypervel\Socialite\Contracts\Factory;
use Hypervel\Support\Facades\Facade;

/**
 * @method static mixed with(string $driver)
 * @method static mixed buildProvider(string $provider, array|null $config)
 * @method static array formatConfig(array $config)
 * @method static \Hypervel\Socialite\SocialiteManager forgetDrivers()
 * @method static string getDefaultDriver()
 * @method static mixed driver(string|null $driver = null)
 * @method static \Hypervel\Socialite\SocialiteManager extend(string $driver, \Closure $callback)
 * @method static array getDrivers()
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static \Hypervel\Socialite\SocialiteManager setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Psr\Http\Message\ResponseInterface redirect()
 * @method static \Hypervel\Socialite\Two\User user()
 * @method static \Hypervel\Socialite\Two\User userFromToken(string $token)
 * @method static mixed getAccessTokenResponse(string $code)
 * @method static \Hypervel\Socialite\Two\Token refreshToken(string $refreshToken)
 * @method static \Hypervel\Socialite\Two\AbstractProvider scopes(array|string $scopes)
 * @method static \Hypervel\Socialite\Two\AbstractProvider setScopes(array|string $scopes)
 * @method static array getScopes()
 * @method static \Hypervel\Socialite\Two\AbstractProvider redirectUrl(string $url)
 * @method static \Hypervel\Socialite\Two\AbstractProvider setRequest(\Hypervel\Contracts\Http\Request $request)
 * @method static \Hypervel\Socialite\Two\AbstractProvider stateless()
 * @method static \Hypervel\Socialite\Two\AbstractProvider enablePKCE()
 * @method static mixed getContext(string $key, mixed $default = null)
 * @method static mixed setContext(string $key, mixed $value)
 * @method static mixed getOrSetContext(string $key, mixed $value)
 *
 * @see \Hypervel\Socialite\SocialiteManager
 * @see \Hypervel\Socialite\Two\AbstractProvider
 */
class Socialite extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Factory::class;
    }
}
