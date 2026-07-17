<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Encryption;

use SensitiveParameter;

interface StringEncrypter
{
    /**
     * Encrypt a string without serialization.
     *
     * @throws \Hypervel\Contracts\Encryption\EncryptException
     */
    public function encryptString(#[SensitiveParameter] string $value): string;

    /**
     * Decrypt the given string without unserialization.
     *
     * @throws \Hypervel\Contracts\Encryption\DecryptException
     */
    public function decryptString(string $payload): string;
}
