<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Providers\Lcobucci;

use Lcobucci\JWT\Signer\InvalidKeyProvided;
use Lcobucci\JWT\Signer\Key;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

final readonly class ParsedKey implements Key
{
    private OpenSSLAsymmetricKey $key;

    public int $type;

    public int $bits;

    /**
     * Keep an immutable OpenSSL key and its type and size for repeated use.
     */
    private function __construct(
        #[SensitiveParameter]
        private string $contents,
        #[SensitiveParameter]
        private string $passphrase,
        OpenSSLAsymmetricKey|false $key,
    ) {
        if ($key === false || ($details = openssl_pkey_get_details($key)) === false) {
            throw InvalidKeyProvided::cannotBeParsed(self::opensslErrors());
        }

        $this->key = $key;
        $this->type = $details['type'];
        $this->bits = $details['bits'];
    }

    /**
     * Parse a private key once for repeated signing.
     */
    public static function fromPrivateKey(#[SensitiveParameter] string $contents, #[SensitiveParameter] string $passphrase = ''): self
    {
        return new self($contents, $passphrase, openssl_pkey_get_private($contents, $passphrase));
    }

    /**
     * Parse a public key once for repeated verification.
     */
    public static function fromPublicKey(string $contents): self
    {
        return new self($contents, '', openssl_pkey_get_public($contents));
    }

    /**
     * Return the original PEM contents.
     *
     * @return non-empty-string
     */
    public function contents(): string
    {
        return $this->contents;
    }

    /**
     * Return the private key's passphrase.
     */
    public function passphrase(): string
    {
        return $this->passphrase;
    }

    /**
     * Return the parsed OpenSSL key.
     */
    public function getKey(): OpenSSLAsymmetricKey
    {
        return $this->key;
    }

    /**
     * Collect the OpenSSL errors for a failed key or signature operation.
     */
    public static function opensslErrors(): string
    {
        $errors = '';

        while ($error = openssl_error_string()) {
            $errors .= PHP_EOL . '* ' . $error;
        }

        return $errors;
    }
}
