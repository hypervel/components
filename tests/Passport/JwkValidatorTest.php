<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Firebase\JWT\JWT;
use Hypervel\Passport\Exceptions\UnsupportedKeyException;
use Hypervel\Passport\JwkValidator;
use Hypervel\Tests\Passport\Fixtures\JsonWebKeyPair;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionProperty;
use UnexpectedValueException;

class JwkValidatorTest extends TestCase
{
    #[TestWith(['RS256'])]
    #[TestWith(['ES256'])]
    public function testPublicKeysVerifySignaturesWithoutAnAlgorithmMember(string $algorithm): void
    {
        $pair = $algorithm === 'RS256'
            ? JsonWebKeyPair::sharedRsa()
            : JsonWebKeyPair::generate();
        $key = (new JwkValidator)->validate($pair->jwk, $algorithm);
        $jwt = JWT::encode(['sub' => 'client'], $pair->privateKey, $algorithm);

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
        $jwk = JsonWebKeyPair::sharedRsa()->jwk;
        $validator = new JwkValidator;
        $validator->validate($jwk, 'RS256');
        $jwk[$parameter] = $value;

        $this->expectException(UnexpectedValueException::class);
        $validator->validate($jwk, 'RS256');
    }

    #[TestWith([128, 3, 'RSA keys must be between 2048 and 4096 bits.'])]
    #[TestWith([513, 3, 'RSA keys must be between 2048 and 4096 bits.'])]
    #[TestWith([256, 5, 'RSA public exponents must not exceed 32 bits.'])]
    public function testUnsupportedRsaParametersAreRejectedWithoutCaching(int $modulusBytes, int $exponentBytes, string $message): void
    {
        $validator = new JwkValidator;
        $jwk = [
            'kty' => 'RSA',
            'n' => JWT::urlsafeB64Encode("\x80" . random_bytes($modulusBytes - 2) . "\x01"),
            'e' => JWT::urlsafeB64Encode("\x01" . str_repeat("\0", $exponentBytes - 2) . "\x01"),
        ];

        try {
            $validator->validate($jwk, 'RS256');
            $this->fail('Unsupported RSA parameters were accepted.');
        } catch (UnsupportedKeyException $exception) {
            $this->assertSame($message, $exception->getMessage());
            $this->assertSame([], (new ReflectionProperty(JwkValidator::class, 'parsedKeys'))->getValue($validator));
        }
    }

    public function testRsaParametersAtBothUpperBoundsAreAccepted(): void
    {
        $key = (new JwkValidator)->validate([
            'kty' => 'RSA',
            'n' => JWT::urlsafeB64Encode("\x80" . random_bytes(510) . "\x01"),
            'e' => JWT::urlsafeB64Encode("\xff\xff\xff\xff"),
        ], 'RS256');

        $this->assertSame('RS256', $key->getAlgorithm());
    }

    public function testParsedKeysAreReusedAndLeastRecentlyUsedEntriesAreEvictedAtTheBound(): void
    {
        $validator = new JwkValidator;
        $recent = JsonWebKeyPair::generate()->jwk;
        $old = JsonWebKeyPair::generate()->jwk;
        $recentKey = $validator->validate($recent, 'ES256');
        $oldKey = $validator->validate($old, 'ES256');

        for ($index = 2; $index < 256; ++$index) {
            $validator->validate(JsonWebKeyPair::generate()->jwk, 'ES256');
        }

        $this->assertSame($recentKey, $validator->validate($recent, 'ES256'));
        $validator->validate(JsonWebKeyPair::generate()->jwk, 'ES256');
        $this->assertSame($recentKey, $validator->validate($recent, 'ES256'));
        $this->assertNotSame($oldKey, $validator->validate($old, 'ES256'));
        $this->assertCount(256, (new ReflectionProperty(JwkValidator::class, 'parsedKeys'))->getValue($validator));
    }

    public function testEs256DoesNotAcceptAnotherCurve(): void
    {
        $details = openssl_pkey_get_details(openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1',
        ]));
        $jwk = [
            'kty' => 'EC', 'crv' => 'P-384',
            'x' => JWT::urlsafeB64Encode(str_pad($details['ec']['x'], 48, "\0", STR_PAD_LEFT)),
            'y' => JWT::urlsafeB64Encode(str_pad($details['ec']['y'], 48, "\0", STR_PAD_LEFT)),
        ];

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('does not permit the selected signature algorithm');
        (new JwkValidator)->validate($jwk, 'ES256');
    }
}
