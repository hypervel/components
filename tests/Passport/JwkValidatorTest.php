<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Firebase\JWT\JWT;
use Hypervel\Passport\JwkValidator;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use UnexpectedValueException;

class JwkValidatorTest extends TestCase
{
    #[TestWith(['RS256'])]
    #[TestWith(['ES256'])]
    public function testPublicKeysVerifySignaturesWithoutAnAlgorithmMember(string $algorithm): void
    {
        [$privateKey, $jwk] = $this->key($algorithm);
        $key = (new JwkValidator)->validate($jwk, $algorithm);
        $jwt = JWT::encode(['sub' => 'client'], $privateKey, $algorithm);

        $this->assertSame('client', JWT::decode($jwt, $key)->sub);
    }

    #[TestWith(['d', null])]
    #[TestWith(['p', 'private'])]
    #[TestWith(['k', 'symmetric'])]
    #[TestWith(['alg', 'HS256'])]
    #[TestWith(['use', 'enc'])]
    #[TestWith(['key_ops', ['sign']])]
    #[TestWith(['key_ops', null])]
    #[TestWith(['key_ops', ['verify', 'verify']])]
    #[TestWith(['key_ops', ['verify', null]])]
    #[TestWith(['n', ['not-a-string']])]
    #[TestWith(['n', 'invalid base64!'])]
    public function testPrivateMaterialAndInvalidKeyMetadataAreRejected(string $parameter, mixed $value): void
    {
        [, $jwk] = $this->key('RS256');
        $jwk[$parameter] = $value;

        $this->expectException(UnexpectedValueException::class);
        (new JwkValidator)->validate($jwk, 'RS256');
    }

    public function testWeakRsaKeysAreRejected(): void
    {
        [, $jwk] = $this->key('RS256', 1024);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('too short');
        (new JwkValidator)->validate($jwk, 'RS256');
    }

    public function testEs256DoesNotAcceptAnotherCurve(): void
    {
        [, $jwk] = $this->key('ES256');
        $jwk['crv'] = 'P-384';

        $this->expectException(UnexpectedValueException::class);
        (new JwkValidator)->validate($jwk, 'ES256');
    }

    /**
     * Generate signing material and its public JWK for a supported algorithm.
     */
    protected function key(string $algorithm, int $bits = 2048): array
    {
        $key = openssl_pkey_new($algorithm === 'RS256'
            ? ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]
            : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        $jwk = $algorithm === 'RS256'
            ? ['kty' => 'RSA', 'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])]
            : ['kty' => 'EC', 'crv' => 'P-256', 'x' => JWT::urlsafeB64Encode($details['ec']['x']), 'y' => JWT::urlsafeB64Encode($details['ec']['y'])];

        return [$privateKey, $jwk];
    }
}
