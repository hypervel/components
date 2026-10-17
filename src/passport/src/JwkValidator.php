<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use OpenSSLAsymmetricKey;
use UnexpectedValueException;

class JwkValidator
{
    public const array ALGORITHMS = ['RS256', 'ES256'];

    /**
     * Validate public signing material for the selected JWT algorithm.
     *
     * @param array<string, mixed> $jwk
     */
    public function validate(array $jwk, string $algorithm): Key
    {
        if (! $this->permits($jwk, $algorithm)) {
            throw new UnexpectedValueException('The public key does not permit the selected signature algorithm.');
        }

        $this->ensurePublic($jwk);
        $type = $algorithm === 'RS256' ? 'RSA' : 'EC';

        foreach ($type === 'RSA' ? ['n', 'e'] : ['x', 'y'] as $parameter) {
            if (! is_string($jwk[$parameter] ?? null) || preg_match('/^[A-Za-z0-9_-]+$/D', $jwk[$parameter]) !== 1) {
                throw new UnexpectedValueException('The public key contains an invalid parameter.');
            }
        }

        $key = JWK::parseKey($jwk, $algorithm);
        $material = $key->getKeyMaterial();
        $publicKey = $material instanceof OpenSSLAsymmetricKey ? $material : openssl_pkey_get_public($material);

        if ($publicKey === false || ($details = openssl_pkey_get_details($publicKey)) === false
            || ($type === 'RSA' && $details['bits'] < 2048)) {
            throw new UnexpectedValueException('The public key is invalid or too short.');
        }

        return new Key($publicKey, $algorithm);
    }

    /**
     * Determine whether key metadata permits verification with the selected algorithm.
     *
     * @param array<string, mixed> $jwk
     */
    public function permits(array $jwk, string $algorithm): bool
    {
        if (! in_array($algorithm, self::ALGORITHMS, true)
            || (array_key_exists('alg', $jwk) && $jwk['alg'] !== $algorithm)
            || (array_key_exists('use', $jwk) && $jwk['use'] !== 'sig')) {
            return false;
        }

        if (array_key_exists('key_ops', $jwk)) {
            $operations = $jwk['key_ops'];

            if (! is_array($operations) || ! array_is_list($operations) || ! in_array('verify', $operations, true)) {
                return false;
            }

            foreach ($operations as $operation) {
                if (! is_string($operation)) {
                    return false;
                }
            }

            if (count(array_unique($operations)) !== count($operations)) {
                return false;
            }
        }

        return $algorithm === 'RS256'
            ? ($jwk['kty'] ?? null) === 'RSA'
            : ($jwk['kty'] ?? null) === 'EC' && ($jwk['crv'] ?? null) === 'P-256';
    }

    /**
     * Reject private or symmetric material even in an otherwise unused key.
     *
     * @param array<string, mixed> $jwk
     */
    public function ensurePublic(array $jwk): void
    {
        if (array_intersect(['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth', 'k'], array_keys($jwk)) !== []) {
            throw new UnexpectedValueException('Only public key material may be supplied.');
        }
    }
}
