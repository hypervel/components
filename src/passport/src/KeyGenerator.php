<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Passport\Bridge\ParsedKey;
use InvalidArgumentException;
use RuntimeException;

class KeyGenerator
{
    public const int DEFAULT_LENGTH = 4096;

    /**
     * Generate an RSA signing key pair in PEM format.
     *
     * @return array{private: string, public: string}
     */
    public function generate(int $length = self::DEFAULT_LENGTH): array
    {
        if ($length < 2048) {
            throw new InvalidArgumentException('Passport signing keys must contain at least 2048 bits.');
        }

        $key = openssl_pkey_new(['private_key_bits' => $length, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false || ! openssl_pkey_export($key, $privateKey)
            || ($details = openssl_pkey_get_details($key)) === false) {
            throw new RuntimeException('Unable to generate signing keys:' . ParsedKey::opensslErrors());
        }

        return ['private' => $privateKey, 'public' => $details['key']];
    }
}
