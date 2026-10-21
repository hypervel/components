<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Hypervel\Passport\Exceptions\UnsupportedKeyException;
use OpenSSLAsymmetricKey;
use UnexpectedValueException;

class JwkValidator
{
    public const array ALGORITHMS = ['RS256', 'ES256'];

    protected const int MAX_PARSED_KEYS = 256;

    /** @var array<string, Key> */
    protected array $parsedKeys = [];

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

        $parameters = $type === 'RSA' ? ['n', 'e'] : ['x', 'y'];

        foreach ($parameters as $parameter) {
            if (! is_string($jwk[$parameter] ?? null) || preg_match('/^[A-Za-z0-9_-]+$/D', $jwk[$parameter]) !== 1) {
                throw new UnexpectedValueException('The public key contains an invalid parameter.');
            }
        }

        // A collision would substitute verification material; retain a fixed-size cryptographic digest.
        $identifier = hash('sha256', implode('.', [$algorithm, ...array_map(static fn (string $name): string => $jwk[$name], $parameters)]), true);

        if (isset($this->parsedKeys[$identifier])) {
            $key = $this->parsedKeys[$identifier];
            unset($this->parsedKeys[$identifier]);

            return $this->parsedKeys[$identifier] = $key;
        }

        $key = JWK::parseKey($jwk, $algorithm);
        $material = $key->getKeyMaterial();
        $publicKey = $material instanceof OpenSSLAsymmetricKey ? $material : openssl_pkey_get_public($material);

        if ($publicKey === false) {
            throw new UnexpectedValueException('The public key is invalid.');
        }

        if ($type === 'RSA') {
            $details = openssl_pkey_get_details($publicKey);

            if ($details === false) {
                throw new UnexpectedValueException('The public key is invalid.');
            }

            // Client-selected RSA parameters must not make each proof verification arbitrarily expensive.
            if ($details['bits'] < 2048 || $details['bits'] > 4096) {
                throw new UnsupportedKeyException('RSA keys must be between 2048 and 4096 bits.');
            }

            if (strlen($details['rsa']['e']) > 4) {
                throw new UnsupportedKeyException('RSA public exponents must not exceed 32 bits.');
            }
        }

        $this->parsedKeys[$identifier] = $key = new Key($publicKey, $algorithm);

        if (count($this->parsedKeys) > self::MAX_PARSED_KEYS) {
            unset($this->parsedKeys[array_key_first($this->parsedKeys)]);
        }

        return $key;
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
