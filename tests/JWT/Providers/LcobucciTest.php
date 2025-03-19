<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Providers;

use Carbon\Carbon;
use Hypervel\JWT\Exceptions\JWTException;
use Hypervel\JWT\Exceptions\TokenInvalidException;
use Hypervel\JWT\Providers\Lcobucci;
use Hypervel\JWT\Providers\Provider;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class LcobucciTest extends TestCase
{
    private int $testNowTimestamp;

    protected function setUp(): void
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->testNowTimestamp = Carbon::now()->timestamp;
    }

    public function testEncodeClaimsUsingASymmetricKey()
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

    public function testEncodeAndDecodeATokenUsingASymmetricKey()
    {
        $payload = [
            'sub' => 1,
            'exp' => $exp = $this->testNowTimestamp + 3600,
            'iat' => $iat = $this->testNowTimestamp,
            'iss' => '/foo',
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
    }

    public function testEncodeAndDecodeATokenUsingAnAsymmetricRs256Key()
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

    public function testShouldThrowAnInvalidExceptionWhenThePayloadCouldNotBeEncoded()
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionMessage('Could not create token:');

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

    public function testShouldThrowATokenInvalidExceptionWhenTheTokenCouldNotBeDecodedDueToABadSignature()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Token Signature could not be verified.');

        // This has a different secret than the one used to encode the token
        $this->getProvider($this->getRandomString(), Provider::ALGO_HS256)
            ->decode('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwiZXhwIjoxNjQ5MjYxMDY1LCJpYXQiOjE2NDkyNTc0NjUsImlzcyI6Ii9mb28iLCJjdXN0b21fY2xhaW0iOiJmb29iYXIifQ.jamiInQiin-1RUviliPjZxl0MLEnQnVTbr2sGooeXBY');
    }

    public function testShouldThrowATokenInvalidExceptionWhenTheTokenCouldNotBeDecodedDueToTamperedToken()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Token Signature could not be verified.');

        // This sub claim for this token has been tampered with so the signature will not match
        $this->getProvider($this->getRandomString(), Provider::ALGO_HS256)
            ->decode('eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIiwiZXhwIjoxNjQ5MjYxMDY1LCJpYXQiOjE2NDkyNTc0NjUsImlzcyI6Ii9mb29iYXIiLCJjdXN0b21fY2xhaW0iOiJmb29iYXIifQ.jamiInQiin-1RUviliPjZxl0MLEnQnVTbr2sGooeXBY');
    }

    public function testShouldThrowATokenInvalidExceptionWhenTheTokenCouldNotBeDecoded()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Could not decode token:');

        $this->getProvider('secret', Provider::ALGO_HS256)->decode('foo.bar.baz');
    }

    public function testShouldThrowAnExceptionWhenTheAlgorithmPassedIsInvalid()
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionMessage('The given algorithm could not be found');

        $this->getProvider('secret', 'INVALID_ALGO')->decode('foo.bar.baz');
    }

    public function testShouldThrowAnExceptionWhenNoAsymmetricPublicKeyIsProvided()
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionMessage('Public key is not set.');

        $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            ['private' => $this->getDummyPrivateKey(), 'public' => null]
        )->decode('foo.bar.baz');
    }

    public function testShouldThrowAnExceptionWhenNoAsymmetricPrivateKeyIsProvided()
    {
        $this->expectException(JWTException::class);
        $this->expectExceptionMessage('Private key is not set.');

        $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            ['private' => null, 'public' => $this->getDummyPublicKey()]
        )->encode(['sub' => 1]);
    }

    public function testShouldReturnThePublicKey()
    {
        $provider = $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            $keys = ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()]
        );

        $this->assertSame($keys['public'], $provider->getPublicKey());
    }

    public function testShouldReturnTheKeys()
    {
        $provider = $this->getProvider(
            'does_not_matter',
            Provider::ALGO_RS256,
            $keys = ['private' => $this->getDummyPrivateKey(), 'public' => $this->getDummyPublicKey()]
        );

        $this->assertSame($keys, $provider->getKeys());
    }

    private function getProvider(string $secret, string $algo, array $keys = []): Lcobucci
    {
        return new Lcobucci($secret, $algo, $keys);
    }

    private function getRandomString(int $length = 64)
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; ++$i) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    private function getDummyPrivateKey()
    {
        return file_get_contents(__DIR__ . '/keys/id_rsa');
    }

    private function getDummyPublicKey()
    {
        return file_get_contents(__DIR__ . '/keys/id_rsa.pub');
    }
}
