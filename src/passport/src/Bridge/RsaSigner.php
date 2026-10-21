<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\CannotSignPayload;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa\Sha256;

class RsaSigner implements Signer
{
    /**
     * Return the JWT algorithm identifier.
     *
     * @return non-empty-string
     */
    public function algorithmId(): string
    {
        return 'RS256';
    }

    /**
     * Sign the payload with the supplied key.
     *
     * @param non-empty-string $payload
     * @return non-empty-string
     */
    public function sign(string $payload, Key $key): string
    {
        if (! $key instanceof ParsedKey) {
            return (new Sha256)->sign($payload, $key);
        }

        if (! openssl_sign($payload, $signature, $key->getKey(), OPENSSL_ALGO_SHA256)) {
            throw CannotSignPayload::errorHappened(ParsedKey::opensslErrors());
        }

        return $signature;
    }

    /**
     * Verify the signature with the supplied key.
     *
     * @param non-empty-string $expected
     * @param non-empty-string $payload
     */
    public function verify(string $expected, string $payload, Key $key): bool
    {
        if (! $key instanceof ParsedKey) {
            return (new Sha256)->verify($expected, $payload, $key);
        }

        return openssl_verify($payload, $expected, $key->getKey(), OPENSSL_ALGO_SHA256) === 1;
    }
}
