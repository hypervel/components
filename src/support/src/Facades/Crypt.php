<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static bool supported(string $key, string $cipher)
 * @method static string generateKey(string $cipher)
 * @method static string encrypt(mixed $value, bool $serialize = true)
 * @method static string encryptString(string $value)
 * @method static mixed decrypt(string $payload, bool $unserialize = true)
 * @method static string decryptString(string $payload)
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
}
