<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Encryption;

interface StringEncrypter
{
    /**
     * Encrypt a string without serialization.
     *
     * @throws \Hypervel\Encryption\Exceptions\EncryptException
     */
    public function encryptString(string $value): string;

    /**
     * Decrypt the given string without unserialization.
     *
     * @throws \Hypervel\Encryption\Exceptions\DecryptException
     */
    public function decryptString(string $payload): string;
}
