<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Providers;

use Closure;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Hypervel\Jwt\Exceptions\JwtException;
use Hypervel\Jwt\Exceptions\SecretMissingException;
use Hypervel\Jwt\Exceptions\TokenExpiredException;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Providers\Lcobucci;
use Hypervel\Jwt\Providers\Provider;
use Hypervel\Jwt\Validations\ExpiredClaim;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\TestCase;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256 as RsaSha256;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use PHPUnit\Framework\Attributes\DataProvider;
use SensitiveParameterValue;
use Throwable;

class LcobucciTest extends TestCase
{
    private int $testNowTimestamp;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->testNowTimestamp = Date::now()->timestamp;
    }

    public function testEncodeClaimsUsingASymmetricKey(): void
    {
        $payload = [
            'sub' => 1,
            'exp' => $exp = $this->testNowTimestamp + 3600,
            'iat' => $iat = $this->testNowTimestamp,
            'iss' => '/foo',
            'custom_claim' => 'foobar',
        ];

        $token = $this->getProvider($this->getRandomString(), Provider::ALGO_HS256)->encode($payload);
        [$header, $payload, $signature] = explode('.', $token);

        $claims = json_decode(base64_decode($payload), true);
        $headerValues = json_decode(base64_decode($header), true);

        $this->assertEquals(Provider::ALGO_HS256, $headerValues['alg']);
        $this->assertIsString($signature);

        $this->assertEquals('1', $claims['sub']);
        $this->assertEquals('/foo', $claims['iss']);
        $this->assertEquals('foobar', $claims['custom_claim']);
        $this->assertEquals($exp, $claims['exp']);
        $this->assertEquals($iat, $claims['iat']);
    }

    public function testEncodeAndDecodeATokenUsingASymmetricKey(): void
    {
        $payload = [
            'sub' => 1,
            'exp' => $exp = $this->testNowTimestamp + 3600,
            'iat' => $iat = $this->testNowTimestamp,
            'iss' => '/foo',
            'custom_claim' => 'foobar',
            'nested_claim' => ['bar' => [0, 0, 0]],
        ];

        $provider = $this->getProvider($this->getRandomString(), Provider::ALGO_HS256);

        $token = $provider->encode($payload);
        $claims = $provider->decode($token);

        $this->assertEquals('1', $claims['sub']);
        $this->assertEquals('/foo', $claims['iss']);
        $this->assertEquals('foobar', $claims['custom_claim']);
        $this->assertSame(['bar' => [0, 0, 0]], $claims['nested_claim']);
        $this->assertEquals($exp, $claims['exp']);
        $this->assertEquals($iat, $claims['iat']);
    }

    public function testEncodeAndDecodeATokenUsingAnAsymmetricRs256Key(): void
    {
        $payload = [
            'sub' => 1,
            'exp' => $exp = $this->testNowTimestamp + 3600,
            'iat' => $iat = $this->testNowTimestamp,
            'iss' => '/foo',
            'custom_claim' => 'foobar',
        ];

        $provider = $this->getProvider(
            $this->getRandomString(),
            Provider::ALGO_RS256,
            ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()]
        );

        $token = $provider->encode($payload);

        $header = json_decode(base64_decode(explode('.', $token)[0]), true);
        $this->assertEquals(Provider::ALGO_RS256, $header['alg']);

        $claims = $provider->decode($token);

        $this->assertEquals('1', $claims['sub']);
        $this->assertEquals('/foo', $claims['iss']);
        $this->assertEquals('foobar', $claims['custom_claim']);
        $this->assertEquals($exp, $claims['exp']);
        $this->assertEquals($iat, $claims['iat']);
    }

    public function testEncodeAndDecodeATokenWithNbfJtiAndAudClaims(): void
    {
        $payload = [
            'sub' => 1,
            'exp' => $exp = $this->testNowTimestamp + 3600,
            'iat' => $iat = $this->testNowTimestamp,
            'nbf' => $nbf = $this->testNowTimestamp,
            'jti' => $jti = 'unique-token-id',
            'iss' => '/foo',
            'aud' => $aud = 'my-audience',
            'custom_claim' => 'foobar',
        ];

        $provider = $this->getProvider($this->getRandomString(), Provider::ALGO_HS256);

        $token = $provider->encode($payload);
        $claims = $provider->decode($token);

        $this->assertEquals('1', $claims['sub']);
        $this->assertEquals('/foo', $claims['iss']);
        $this->assertEquals('foobar', $claims['custom_claim']);
        $this->assertEquals($exp, $claims['exp']);
        $this->assertEquals($iat, $claims['iat']);
        $this->assertEquals($nbf, $claims['nbf']);
        $this->assertEquals($jti, $claims['jti']);
        $this->assertEquals([$aud], $claims['aud']);
    }

    public function testEncodeAndDecodeATokenUsingAnAsymmetricEs256Key(): void
    {
        $payload = [
            'sub' => 1,
            'exp' => $exp = $this->testNowTimestamp + 3600,
            'iat' => $iat = $this->testNowTimestamp,
            'iss' => '/foo',
            'custom_claim' => 'foobar',
        ];

        $provider = $this->getProvider(
            $this->getRandomString(),
            Provider::ALGO_ES256,
            ['private' => $this->getDummyEcPrivateKey(), 'public' => $this->getDummyEcPublicKey()]
        );

        $token = $provider->encode($payload);

        $header = json_decode(base64_decode(explode('.', $token)[0]), true);
        $this->assertEquals(Provider::ALGO_ES256, $header['alg']);

        $claims = $provider->decode($token);

        $this->assertEquals('1', $claims['sub']);
        $this->assertEquals('/foo', $claims['iss']);
        $this->assertEquals('foobar', $claims['custom_claim']);
        $this->assertEquals($exp, $claims['exp']);
        $this->assertEquals($iat, $claims['iat']);
    }

    public function testEncodeAndDecodeATokenWithMultipleAudiences(): void
    {
        $payload = [
            'sub' => 1,
            'aud' => ['https://first.example.test', 'https://second.example.test'],
            'iat' => $this->testNowTimestamp,
        ];

        $provider = $this->getProvider($this->getRandomString(), Provider::ALGO_HS256);

        $claims = $provider->decode($provider->encode($payload));

        $this->assertSame(['https://first.example.test', 'https://second.example.test'], $claims['aud']);
    }

    #[DataProvider('dateClaimFormProvider')]
    public function testEncodeAndDecodeSupportedDateClaimForms(
        int|string|DateTimeInterface|DateInterval $exp,
        int|string|DateTimeInterface|DateInterval $nbf,
        int|string|DateTimeInterface|DateInterval $iat,
    ): void {
        $provider = $this->getProvider($this->getRandomString(), Provider::ALGO_HS256);

        $token = $provider->encode(['sub' => 1, 'exp' => $exp, 'nbf' => $nbf, 'iat' => $iat]);
        $encodedClaims = json_decode(base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
        $claims = $provider->decode($token);

        foreach ([$encodedClaims, $claims] as $dates) {
            $this->assertSame($this->testNowTimestamp + 3600, $dates['exp']);
            $this->assertSame($this->testNowTimestamp, $dates['nbf']);
            $this->assertSame($this->testNowTimestamp, $dates['iat']);
        }
    }

    /**
     * Provide expiration, not-before and issued-at values that represent the same dates.
     *
     * @return array<string, array{DateInterval|DateTimeInterface|int|string, DateInterval|DateTimeInterface|int|string, DateInterval|DateTimeInterface|int|string}>
     */
    public static function dateClaimFormProvider(): array
    {
        $now = 946684800;

        return [
            'integer timestamps' => [$now + 3600, $now, $now],
            'integer string timestamps' => [(string) ($now + 3600), (string) $now, (string) $now],
            'Carbon dates with microseconds' => [
                CarbonImmutable::createFromTimestamp($now + 3600.5),
                CarbonImmutable::createFromTimestamp($now + 0.5),
                CarbonImmutable::createFromTimestamp($now),
            ],
            'DateTime dates' => [new DateTime('@' . ($now + 3600)), new DateTime('@' . $now), new DateTime('@' . $now)],
            'DateTimeImmutable dates' => [
                new DateTimeImmutable('@' . ($now + 3600)),
                new DateTimeImmutable('@' . $now),
                new DateTimeImmutable('@' . $now),
            ],
            'intervals from now' => [new DateInterval('PT1H'), new DateInterval('PT0S'), new DateInterval('PT0S')],
        ];
    }

    public function testShouldThrowAnInvalidExceptionWhenThePayloadCouldNotBeEncoded(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIsOrContains('Could not create token:');

        $payload = [
            'sub' => 1,
            'exp' => $this->testNowTimestamp + 3600,
            'iat' => $this->testNowTimestamp,
            'iss' => '/foo',
            'custom_claim' => 'foobar',
            'invalid_utf8' => "\xB1\x31", // cannot be encoded as JSON
        ];

        $this->getProvider($this->getRandomString(), Provider::ALGO_HS256)->encode($payload);
    }

    public function testShouldThrowATokenInvalidExceptionWhenTheTokenCouldNotBeDecodedDueToABadSignature(): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');

        // This has a different secret than the one used to encode the token
        $this->getProvider($this->getRandomString(), Provider::ALGO_HS256)
            ->decode('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwiZXhwIjoxNjQ5MjYxMDY1LCJpYXQiOjE2NDkyNTc0NjUsImlzcyI6Ii9mb28iLCJjdXN0b21fY2xhaW0iOiJmb29iYXIifQ.jamiInQiin-1RUviliPjZxl0MLEnQnVTbr2sGooeXBY');
    }

    public function testShouldThrowATokenInvalidExceptionWhenTheTokenCouldNotBeDecodedDueToTamperedToken(): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');

        // This sub claim for this token has been tampered with so the signature will not match
        $this->getProvider($this->getRandomString(), Provider::ALGO_HS256)
            ->decode('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwiZXhwIjoxNjQ5MjYxMDY1LCJpYXQiOjE2NDkyNTc0NjUsImlzcyI6Ii9mb29iYXIiLCJjdXN0b21fY2xhaW0iOiJmb29iYXIifQ.jamiInQiin-1RUviliPjZxl0MLEnQnVTbr2sGooeXBY');
    }

    public function testShouldThrowATokenInvalidExceptionWhenAnEcdsaSignatureHasTheWrongLength(): void
    {
        $provider = $this->getProvider(
            'does_not_matter',
            Provider::ALGO_ES256,
            ['private' => $this->getDummyEcPrivateKey(), 'public' => $this->getDummyEcPublicKey()]
        );

        [$header, $payload] = explode('.', $provider->encode(['sub' => 1, 'iat' => $this->testNowTimestamp]));

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');

        $provider->decode("{$header}.{$payload}." . $this->base64UrlEncode('signature'));
    }

    #[DataProvider('undecodableTokenProvider')]
    public function testShouldThrowATokenInvalidExceptionWhenTheTokenCouldNotBeDecoded(string $token): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIsOrContains('Could not decode token:');

        $this->getProvider('secret', Provider::ALGO_HS256)->decode($token);
    }

    /**
     * Provide tokens that cannot be parsed.
     *
     * @return array<string, array{string}>
     */
    public static function undecodableTokenProvider(): array
    {
        return [
            'segments that are not encoded JSON' => ['foo.bar.baz'],
            'missing signature' => ['one.two.'],
            'missing header and signature' => ['.two.'],
            'missing header' => ['.two.three'],
            'missing claims' => ['one..three'],
            'separators only' => ['..'],
            'blank segments' => [' . . '],
            'padded segments' => [' one . two . three '],
            'two segments' => ['one.two'],
            'four segments' => ['one.two.three.four'],
            'five segments' => ['one.two.three.four.five'],
        ];
    }

    #[DataProvider('malformedRegisteredDateProvider')]
    public function testMalformedRegisteredDatesAreReportedAsInvalidTokens(string $claim, mixed $value): void
    {
        $provider = $this->getProvider('secret', Provider::ALGO_HS256);

        try {
            $provider->decode($this->encodeExternalToken([$claim => $value], 'different-secret'));

            $this->fail('Expected the malformed registered date to be rejected.');
        } catch (TokenInvalidException $exception) {
            $this->assertStringStartsWith('Could not decode token:', $exception->getMessage());
            $this->assertInstanceOf(Throwable::class, $exception->getPrevious());
        }
    }

    public static function malformedRegisteredDateProvider(): array
    {
        return [
            'null expiration' => ['exp', null],
            'boolean not-before' => ['nbf', true],
            'array issued-at' => ['iat', []],
            'object expiration' => ['exp', (object) ['timestamp' => 0]],
        ];
    }

    public function testExternalStringZeroExpirationIsRejected(): void
    {
        $secret = str_repeat('s', 64);
        $payload = $this->getProvider($secret, Provider::ALGO_HS256)
            ->decode($this->encodeExternalToken(['exp' => '0'], $secret));

        $this->assertSame(0, $payload['exp']);

        $this->expectException(TokenExpiredException::class);

        (new ExpiredClaim)->validate($payload);
    }

    public function testShouldThrowAnExceptionWhenTheAlgorithmPassedIsInvalid(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('The given algorithm could not be found');

        $this->getProvider('secret', 'INVALID_ALGO')->decode('foo.bar.baz');
    }

    public function testShouldThrowAnExceptionWhenNoAsymmetricPublicKeyIsProvided(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Public key is not set.');

        $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            ['private' => $this->getDummyPrivateKey(), 'public' => null]
        )->decode('foo.bar.baz');
    }

    public function testShouldThrowAnExceptionWhenNoAsymmetricPrivateKeyIsProvided(): void
    {
        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Private key is not set.');

        $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            ['private' => null, 'public' => $this->getDummyPublicKey()]
        )->encode(['sub' => 1]);
    }

    #[DataProvider('asymmetricKeyPairProvider')]
    public function testThePublicKeyAloneVerifiesTokens(string $algo, string $privateKeyFile, string $publicKeyFile): void
    {
        $publicKey = file_get_contents(__DIR__ . "/../Fixtures/keys/{$publicKeyFile}");
        $token = $this->getProvider('does_not_matter', $algo, [
            'private' => file_get_contents(__DIR__ . "/../Fixtures/keys/{$privateKeyFile}"),
            'public' => $publicKey,
        ])->encode(['sub' => 1, 'iat' => $this->testNowTimestamp]);

        $verifier = $this->getProvider('does_not_matter', $algo, ['public' => $publicKey]);

        $this->assertSame('1', $verifier->decode($token)['sub']);

        [$header, , $signature] = explode('.', $token);

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');

        $verifier->decode("{$header}." . $this->base64UrlEncode('{"sub":"2"}') . ".{$signature}");
    }

    /**
     * Provide asymmetric algorithms with their key pair fixtures.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function asymmetricKeyPairProvider(): array
    {
        return [
            'RSA' => [Provider::ALGO_RS256, 'id_rsa', 'id_rsa.pub'],
            'ECDSA' => [Provider::ALGO_ES256, 'id_ecdsa', 'id_ecdsa.pub'],
        ];
    }

    public function testShouldThrowASecretMissingExceptionWhenNoSymmetricSecretIsProvided(): void
    {
        $this->expectException(SecretMissingException::class);
        $this->expectExceptionMessageIs('Secret is not set.');

        $this->getProvider('', Provider::ALGO_HS256);
    }

    public function testEncodeAndDecodeATokenUsingFileKeyPaths(): void
    {
        $payload = [
            'sub' => 1,
            'exp' => $exp = $this->testNowTimestamp + 3600,
            'iat' => $iat = $this->testNowTimestamp,
        ];

        $provider = $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            [
                'private' => 'file://' . __DIR__ . '/../Fixtures/keys/id_rsa',
                'public' => 'file://' . __DIR__ . '/../Fixtures/keys/id_rsa.pub',
            ],
        );

        $claims = $provider->decode($provider->encode($payload));

        $this->assertSame('1', $claims['sub']);
        $this->assertSame($exp, $claims['exp']);
        $this->assertSame($iat, $claims['iat']);
    }

    public function testShouldReturnThePublicKey(): void
    {
        $provider = $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            $keys = ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()]
        );

        $this->assertSame($keys['public'], $provider->getPublicKey());
    }

    public function testShouldReturnTheKeys(): void
    {
        $provider = $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            $keys = ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()]
        );

        $this->assertSame($keys, $provider->getKeys());
    }

    public function testSetAlgoTakesEffectOnEncoding(): void
    {
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp];

        $provider = $this->getProvider($this->getRandomString(), Provider::ALGO_HS256);
        $provider->setAlgo(Provider::ALGO_HS512);

        $token = $provider->encode($payload);
        $header = json_decode(base64_decode(explode('.', $token)[0]), true);

        $this->assertEquals(Provider::ALGO_HS512, $header['alg']);
    }

    public function testSetSecretTakesEffectOnSigning(): void
    {
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp];

        $originalSecret = $this->getRandomString();
        $rotatedSecret = $this->getRandomString();

        $provider = $this->getProvider($originalSecret, Provider::ALGO_HS256);
        $provider->setSecret($rotatedSecret);

        $token = $provider->encode($payload);

        // A fresh provider using the new secret decodes the token successfully.
        $decoded = $this->getProvider($rotatedSecret, Provider::ALGO_HS256)->decode($token);
        $this->assertEquals('1', $decoded['sub']);

        // A fresh provider using the old secret cannot verify the signature.
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');
        $this->getProvider($originalSecret, Provider::ALGO_HS256)->decode($token);
    }

    public function testShouldThrowAnExceptionWhenTheSecretHasBeenUpdatedAndAnOldTokenIsUsed(): void
    {
        $payload = ['sub' => '1', 'exp' => $this->testNowTimestamp + 3600, 'iat' => $this->testNowTimestamp, 'iss' => '/foo'];

        $provider = $this->getProvider($this->getRandomString(), Provider::ALGO_HS256);
        $token = $provider->encode($payload);

        $this->assertSame($payload, $provider->decode($token));

        $provider->setSecret($this->getRandomString());

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');

        $provider->decode($token);
    }

    public function testSetKeysTakesEffectOnSigning(): void
    {
        $payload = ['sub' => 1, 'iat' => $this->testNowTimestamp];

        $keyPair1 = ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()];
        $keyPair2 = ['private' => $this->getAltPrivateKey(), 'public' => $this->getAltPublicKey()];

        $provider = $this->getProvider('does_not_matter', Provider::ALGO_RS256, $keyPair1);
        $provider->setKeys($keyPair2);

        $token = $provider->encode($payload);

        // A fresh provider using key pair 2 decodes the token successfully.
        $decoded = $this->getProvider('does_not_matter', Provider::ALGO_RS256, $keyPair2)->decode($token);
        $this->assertEquals('1', $decoded['sub']);

        // A fresh provider using key pair 1 cannot verify the signature.
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');
        $this->getProvider('does_not_matter', Provider::ALGO_RS256, $keyPair1)->decode($token);
    }

    public function testSetKeysSwitchesBetweenVerifyingAndSigning(): void
    {
        $keyPair = ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()];

        $provider = $this->getProvider('does_not_matter', Provider::ALGO_RS256, ['public' => $keyPair['public']]);
        $provider->setKeys($keyPair);

        $token = $provider->encode(['sub' => 1, 'iat' => $this->testNowTimestamp]);

        $this->assertSame('1', $provider->decode($token)['sub']);

        $provider->setKeys(['public' => $keyPair['public']]);

        $this->assertSame('1', $provider->decode($token)['sub']);

        $this->expectException(JwtException::class);
        $this->expectExceptionMessageIs('Private key is not set.');

        $provider->encode(['sub' => 1]);
    }

    public function testConstraintsAddedByBuildConfigApplyWhenDecoding(): void
    {
        $provider = new LcobucciWithIssuerConstraint($this->getRandomString(), Provider::ALGO_HS256, []);

        $claims = $provider->decode($provider->encode(['sub' => 1, 'iss' => 'https://issuer.example.test']));

        $this->assertSame('https://issuer.example.test', $claims['iss']);

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');

        $provider->decode($provider->encode(['sub' => 1, 'iss' => 'https://other.example.test']));
    }

    #[DataProvider('asymmetricKeyPairProvider')]
    public function testConstraintsAddedByBuildConfigApplyWithOnlyThePublicKey(string $algo, string $privateKeyFile, string $publicKeyFile): void
    {
        $publicKey = file_get_contents(__DIR__ . "/../Fixtures/keys/{$publicKeyFile}");
        $issuer = $this->getProvider('does_not_matter', $algo, [
            'private' => file_get_contents(__DIR__ . "/../Fixtures/keys/{$privateKeyFile}"),
            'public' => $publicKey,
        ]);
        $verifier = new LcobucciWithIssuerConstraint('does_not_matter', $algo, ['public' => $publicKey]);

        $claims = $verifier->decode($issuer->encode(['sub' => 1, 'iss' => 'https://issuer.example.test']));

        $this->assertSame('https://issuer.example.test', $claims['iss']);

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');

        $verifier->decode($issuer->encode(['sub' => 1, 'iss' => 'https://other.example.test']));
    }

    public function testAGivenConfigurationSignsAndVerifiesTokensInsteadOfTheSecret(): void
    {
        $secret = $this->getRandomString();
        $key = InMemory::plainText($this->getRandomString());
        $config = Configuration::forSymmetricSigner(new Sha256, $key)
            ->withValidationConstraints(new SignedWith(new Sha256, $key));

        $provider = new Lcobucci($secret, Provider::ALGO_HS256, [], $config);
        $token = $provider->encode(['sub' => 1, 'iat' => $this->testNowTimestamp]);

        $this->assertSame('1', $provider->decode($token)['sub']);

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs('Token Signature could not be verified.');

        $this->getProvider($secret, Provider::ALGO_HS256)->decode($token);
    }

    public function testAGivenAsymmetricConfigurationSignsWithoutLocalKeys(): void
    {
        $publicKey = InMemory::plainText($this->getDummyPublicKey());
        $config = Configuration::forAsymmetricSigner(new RsaSha256, InMemory::plainText($this->getDummyPrivateKey()), $publicKey)
            ->withValidationConstraints(new SignedWith(new RsaSha256, $publicKey));

        $provider = new Lcobucci('does_not_matter', Provider::ALGO_RS256, [], $config);
        $token = $provider->encode(['sub' => 1, 'iat' => $this->testNowTimestamp]);

        $this->assertSame('1', $provider->decode($token)['sub']);
    }

    #[DataProvider('credentialFailureProvider')]
    public function testSigningCredentialsAreKeptOutOfExceptionTraces(Closure $configure, string $function, array $positions): void
    {
        $exception = null;
        $ignoreArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            $configure();
        } catch (Exception $exception) {
        } finally {
            ini_set('zend.exception_ignore_args', $ignoreArguments);
        }

        $this->assertInstanceOf(Exception::class, $exception);

        $frames = array_values(array_filter(
            $exception->getTrace(),
            static fn (array $frame): bool => is_a($frame['class'] ?? '', Provider::class, true) && $frame['function'] === $function,
        ));

        $this->assertCount(1, $frames);

        foreach ($positions as $position) {
            $this->assertInstanceOf(SensitiveParameterValue::class, $frames[0]['args'][$position]);
        }
    }

    /**
     * Provide failures that happen while the given credentials are function arguments.
     *
     * @return array<string, array{Closure, string, array<int, int>}>
     */
    public static function credentialFailureProvider(): array
    {
        $keys = __DIR__ . '/../Fixtures/keys';

        return [
            'constructor' => [
                static fn (): Lcobucci => new Lcobucci('signing-secret', 'INVALID_ALGO', ['private' => 'private-key']),
                '__construct',
                [0, 2],
            ],
            'new keys' => [
                static fn (): Lcobucci => (new Lcobucci('does_not_matter', Provider::ALGO_RS256, [
                    'private' => file_get_contents("{$keys}/id_rsa"),
                    'public' => file_get_contents("{$keys}/id_rsa.pub"),
                ]))->setKeys(['private' => file_get_contents("{$keys}/id_rsa_alt")]),
                'setKeys',
                [0],
            ],
            'key file' => [
                static fn (): Lcobucci => new Lcobucci('does_not_matter', Provider::ALGO_RS256, [
                    'private' => "file://{$keys}/missing",
                    'public' => file_get_contents("{$keys}/id_rsa.pub"),
                    'passphrase' => 'key-passphrase',
                ]),
                'getKey',
                [0, 1],
            ],
        ];
    }

    private function getProvider(string $secret, string $algo, array $keys = []): Lcobucci
    {
        return new Lcobucci($secret, $algo, $keys);
    }

    private function getRandomString(int $length = 64): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; ++$i) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    private function encodeExternalToken(array $payload, string $secret): string
    {
        $segments = [
            $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => Provider::ALGO_HS256], JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $segments[] = $this->base64UrlEncode(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function getDummyPrivateKey(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/keys/id_rsa');
    }

    private function getDummyPublicKey(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/keys/id_rsa.pub');
    }

    private function getAltPrivateKey(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/keys/id_rsa_alt');
    }

    private function getAltPublicKey(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/keys/id_rsa_alt.pub');
    }

    /**
     * Get the ECDSA private key fixture.
     */
    private function getDummyEcPrivateKey(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/keys/id_ecdsa');
    }

    /**
     * Get the ECDSA public key fixture.
     */
    private function getDummyEcPublicKey(): string
    {
        return file_get_contents(__DIR__ . '/../Fixtures/keys/id_ecdsa.pub');
    }
}

class LcobucciWithIssuerConstraint extends Lcobucci
{
    /**
     * Build the configuration, also requiring the expected issuer.
     */
    protected function buildConfig(): Configuration
    {
        $config = parent::buildConfig();

        return $config->withValidationConstraints(
            ...[...$config->validationConstraints(), new IssuedBy('https://issuer.example.test')],
        );
    }
}
