<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Contracts\Hashing\Hasher;

/**
 * @method static \Hypervel\Hashing\BcryptHasher createBcryptDriver()
 * @method static \Hypervel\Hashing\ArgonHasher createArgonDriver()
 * @method static \Hypervel\Hashing\Argon2IdHasher createArgon2idDriver()
 * @method static array info(string $hashedValue)
 * @method static string make(string $value, array $options = [])
 * @method static bool check(string $value, string|null $hashedValue, array $options = [])
 * @method static bool needsRehash(string $hashedValue, array $options = [])
 * @method static bool isHashed(string $value)
 * @method static string getDefaultDriver()
 * @method static mixed driver(string|null $driver = null)
 * @method static \Hypervel\Hashing\HashManager extend(string $driver, \Closure $callback)
 * @method static array getDrivers()
 * @method static \Psr\Container\ContainerInterface getContainer()
 * @method static \Hypervel\Hashing\HashManager setContainer(\Psr\Container\ContainerInterface $container)
 * @method static \Hypervel\Hashing\HashManager forgetDrivers()
 *
 * @see \Hypervel\Hashing\HashManager
 */
class Hash extends Facade
{
    protected static function getFacadeAccessor()
    {
        return Hasher::class;
    }
}
