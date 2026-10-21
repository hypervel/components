<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Lcobucci\JWT\Signer\InvalidKeyProvided;
use Lcobucci\JWT\Signer\Key;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

final readonly class ParsedKey implements Key
{
    private const int MINIMUM_KEY_LENGTH = 2048;

    private const array KEY_TYPES = [
        OPENSSL_KEYTYPE_RSA => 'RSA',
        OPENSSL_KEYTYPE_DSA => 'DSA',
        OPENSSL_KEYTYPE_DH => 'DH',
        OPENSSL_KEYTYPE_EC => 'EC',
    ];

    private OpenSSLAsymmetricKey $key;

    private string $publicKey;

    private string $keyId;

    /**
     * @var array<string, string>
     */
    private array $jwk;

    /**
     * Create a validated, immutable RSA key.
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

        if ($details['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw InvalidKeyProvided::incompatibleKeyType('RSA', self::KEY_TYPES[$details['type']] ?? 'unknown');
        }

        if ($details['bits'] < self::MINIMUM_KEY_LENGTH) {
            throw InvalidKeyProvided::tooShort(self::MINIMUM_KEY_LENGTH, $details['bits']);
        }

        $this->key = $key;
        $this->publicKey = $details['key'];

        $parameters = [
            'e' => self::base64Url($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => self::base64Url($details['rsa']['n']),
        ];

        // RFC 7638 requires these public parameters in lexicographic order.
        $this->keyId = self::base64Url(hash('sha256', json_encode($parameters, JSON_THROW_ON_ERROR), true));
        $this->jwk = [...$parameters, 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->keyId];
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
     * Return the public key's PEM contents.
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Return the public key's JWK thumbprint.
     */
    public function getKeyId(): string
    {
        return $this->keyId;
    }

    /**
     * Return the public JSON Web Key.
     *
     * @return array<string, string>
     */
    public function getJwk(): array
    {
        return $this->jwk;
    }

    /**
     * Encode a value without base64 padding.
     */
    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
