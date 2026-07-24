<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use RuntimeException;
use SensitiveParameter;

/**
 * @method static \Hypervel\Hashing\BcryptHasher createBcryptDriver()
 * @method static \Hypervel\Hashing\ArgonHasher createArgonDriver()
 * @method static \Hypervel\Hashing\Argon2IdHasher createArgon2idDriver()
 * @method static array info(string $hashedValue)
 * @method static string make(string $value, array $options = [])
 * @method static bool check(string $value, string|null $hashedValue, array $options = [])
 * @method static bool needsRehash(string|null $hashedValue, array $options = [])
 * @method static bool isHashed(string $value)
 * @method static string getDefaultDriver()
 * @method static mixed driver(\UnitEnum|string|null $driver = null)
 * @method static \Hypervel\Hashing\HashManager extend(string $driver, \Closure $callback)
 * @method static array getDrivers()
 * @method static \Hypervel\Contracts\Container\Container getContainer()
 * @method static \Hypervel\Hashing\HashManager setContainer(\Hypervel\Contracts\Container\Container $container)
 * @method static \Hypervel\Hashing\HashManager forgetDrivers()
 *
 * @see \Hypervel\Hashing\HashManager
 */
class Hash extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'hash';
    }

    /**
     * Handle dynamic, static calls to the hasher.
     *
     * @throws RuntimeException
     */
    public static function __callStatic(string $method, #[SensitiveParameter] array $args)
    {
        // This mirrors Facade::__callStatic() locally because delegating to the
        // parent would retain the sensitive packed arguments in its stack frame.
        $instance = static::getFacadeRoot();

        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->{$method}(...$args);
    }
}
