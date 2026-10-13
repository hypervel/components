<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Providers\Lcobucci;

use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\CannotSignPayload;
use Lcobucci\JWT\Signer\Ecdsa;
use Lcobucci\JWT\Signer\Ecdsa\ConversionFailed;
use Lcobucci\JWT\Signer\Ecdsa\MultibyteStringConverter;
use Lcobucci\JWT\Signer\Ecdsa\SignatureConverter;
use Lcobucci\JWT\Signer\InvalidKeyProvided;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa;

/**
 * Reuse parsed keys without duplicating lcobucci's ECDSA signature conversion.
 *
 * This depends on its internal algorithm(), pointLength(), expectedKeyLength()
 * and SignatureConverter / MultibyteStringConverter APIs. Check OpenSslSignerTest's
 * interoperability cases when upgrading lcobucci.
 */
class OpenSslSigner implements Signer
{
    protected const int MINIMUM_RSA_BITS = 2048;

    protected const array KEY_TYPES = [
        OPENSSL_KEYTYPE_RSA => 'RSA',
        OPENSSL_KEYTYPE_DSA => 'DSA',
        OPENSSL_KEYTYPE_DH => 'DH',
        OPENSSL_KEYTYPE_EC => 'EC',
    ];

    protected readonly ?SignatureConverter $converter;

    /**
     * Reuse parsed keys with lcobucci's RSA and ECDSA algorithm definitions.
     */
    public function __construct(protected readonly Rsa|Ecdsa $signer)
    {
        $this->converter = $signer instanceof Ecdsa ? new MultibyteStringConverter : null;
    }

    /**
     * Return the JWT algorithm identifier.
     *
     * @return non-empty-string
     */
    public function algorithmId(): string
    {
        return $this->signer->algorithmId();
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
            return $this->signer->sign($payload, $key);
        }

        $this->validateKey($key);

        if (! openssl_sign($payload, $signature, $key->getKey(), $this->signer->algorithm())) {
            throw CannotSignPayload::errorHappened(ParsedKey::opensslErrors());
        }

        return $this->signer instanceof Ecdsa
            ? $this->converter->fromAsn1($signature, $this->signer->pointLength())
            : $signature;
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
            return $this->signer->verify($expected, $payload, $key);
        }

        if ($this->signer instanceof Ecdsa) {
            try {
                $expected = $this->converter->toAsn1($expected, $this->signer->pointLength());
            } catch (ConversionFailed) {
                return false;
            }
        }

        $this->validateKey($key);

        return openssl_verify($payload, $expected, $key->getKey(), $this->signer->algorithm()) === 1;
    }

    /**
     * Apply lcobucci's type and size requirements to the supplied key.
     */
    protected function validateKey(ParsedKey $key): void
    {
        $type = $this->signer instanceof Rsa ? OPENSSL_KEYTYPE_RSA : OPENSSL_KEYTYPE_EC;

        if ($key->type !== $type) {
            throw InvalidKeyProvided::incompatibleKeyType(self::KEY_TYPES[$type], self::KEY_TYPES[$key->type]);
        }

        if ($this->signer instanceof Rsa) {
            if ($key->bits < self::MINIMUM_RSA_BITS) {
                throw InvalidKeyProvided::tooShort(self::MINIMUM_RSA_BITS, $key->bits);
            }
        } elseif ($key->bits !== $this->signer->expectedKeyLength()) {
            throw InvalidKeyProvided::incompatibleKeyLength($this->signer->expectedKeyLength(), $key->bits);
        }
    }
}
