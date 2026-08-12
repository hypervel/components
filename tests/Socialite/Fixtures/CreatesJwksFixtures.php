<?php

declare(strict_types=1);

namespace Hypervel\Tests\Socialite\Fixtures;

trait CreatesJwksFixtures
{
    private function createRsaKeyPair(string $kid): array
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            $this->fail('Unable to generate RSA key pair for Socialite test.');
        }

        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);

        return [
            'kid' => $kid,
            'private' => $privateKey,
            'jwk' => [
                'kid' => $kid,
                'kty' => 'RSA',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->base64UrlEncode($details['rsa']['n']),
                'e' => $this->base64UrlEncode($details['rsa']['e']),
            ],
        ];
    }

    private function jwks(array $key): array
    {
        return ['keys' => [$key['jwk']]];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
