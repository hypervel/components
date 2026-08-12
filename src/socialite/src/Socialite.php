<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use Closure;
use Hypervel\Socialite\Contracts\Factory;
use Hypervel\Socialite\Contracts\User as UserContract;
use Hypervel\Socialite\Testing\SocialiteFake;
use Hypervel\Support\Facades\Facade;

/**
 * @method static \Hypervel\Socialite\Contracts\Provider with(string $driver)
 * @method static \Hypervel\Socialite\Contracts\Provider driver(\UnitEnum|string|null $driver = null)
 * @method static \Hypervel\Socialite\Two\AbstractProvider buildOAuth2Provider(string $provider, array|null $config)
 * @method static string getDefaultDriver()
 * @method static \Hypervel\Socialite\SocialiteManager extend(string $driver, Closure $callback)
 * @method static array getDrivers()
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static \Hypervel\Socialite\SocialiteManager setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Socialite\SocialiteManager forgetDrivers()
 *
 * @see \Hypervel\Socialite\SocialiteManager
 */
class Socialite extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Factory::class;
    }

    /**
     * Register a fake Socialite instance.
     */
    public static function fake(string $driver, UserContract|Closure|null $user = null): SocialiteFake
    {
        $root = static::getFacadeRoot();

        if ($root instanceof SocialiteFake) {
            $fake = $root;
        } else {
            $fake = new SocialiteFake($root);

            static::swap($fake);
        }

        return $fake->fake($driver, $user);
    }
}
