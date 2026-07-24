<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use RuntimeException;
use SensitiveParameter;

/**
 * @method static bool supported(string $key, string $cipher)
 * @method static string generateKey(string $cipher)
 * @method static string encrypt(mixed $value, bool $serialize = true)
 * @method static string encryptString(string $value)
 * @method static mixed decrypt(string $payload, bool $unserialize = true)
 * @method static string decryptString(string $payload)
 * @method static bool appearsEncrypted(mixed $value)
 * @method static string getKey()
 * @method static array getAllKeys()
 * @method static array getPreviousKeys()
 * @method static \Hypervel\Encryption\Encrypter previousKeys(array $keys)
 *
 * @see \Hypervel\Encryption\Encrypter
 */
class Crypt extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'encrypter';
    }

    /**
     * Handle dynamic, static calls to the encrypter.
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
