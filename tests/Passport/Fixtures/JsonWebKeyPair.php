<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Fixtures;

use Firebase\JWT\JWT;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;

readonly class JsonWebKeyPair
{
    /**
     * Hold signing material and its public JWK.
     *
     * @param array<string, string> $jwk
     */
    public function __construct(public string $privateKey, public array $jwk)
    {
    }

    /**
     * Generate signing material and its public JWK for a supported algorithm.
     */
    public static function generate(string $algorithm = 'ES256', int $bits = 2048): static
    {
        $key = openssl_pkey_new($algorithm === 'RS256'
            ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]
            : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privateKey);

        return static::fromPrivateKey($privateKey);
    }

    /**
     * Reuse the factory's RSA signing material without generating another key.
     */
    public static function sharedRsa(): static
    {
        return static::fromPrivateKey((new SigningKeyFactory)->definition()['private_key']);
    }

    /**
     * Read an existing RSA or P-256 signing key without generating another pair.
     */
    public static function fromPrivateKey(string $privateKey): static
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($privateKey));
        // OpenSSL omits leading zero bytes, while JWK coordinates have a fixed width.
        $jwk = $details['type'] === OPENSSL_KEYTYPE_RSA
            ? ['e' => JWT::urlsafeB64Encode($details['rsa']['e']), 'kty' => 'RSA', 'n' => JWT::urlsafeB64Encode($details['rsa']['n'])]
            : ['crv' => 'P-256', 'kty' => 'EC', 'x' => JWT::urlsafeB64Encode(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => JWT::urlsafeB64Encode(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))];

        return new static($privateKey, $jwk);
    }

    /**
     * Calculate the RFC 7638 thumbprint of the public key's required members.
     */
    public function thumbprint(): string
    {
        return JWT::urlsafeB64Encode(hash('sha256', json_encode($this->jwk, JSON_THROW_ON_ERROR), true));
    }
}
