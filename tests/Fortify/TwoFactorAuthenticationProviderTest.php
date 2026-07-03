<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Repository as CacheRepositoryContract;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\TwoFactorAuthenticationProvider;
use Hypervel\Tests\Fortify\Fixtures\FixedClock;
use InvalidArgumentException;
use Mockery as m;
use OTPHP\TOTP;
use OTPHP\TOTPInterface;

class TwoFactorAuthenticationProviderTest extends TestCase
{
    private const int TIMESTAMP = 1234567890;

    private const string SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function testDefaultSecretLengthIsThirtyTwoBase32Characters(): void
    {
        $provider = $this->provider();

        $this->assertSame(32, strlen($provider->generateSecretKey()));
    }

    public function testCustomSecretLengthIsMeasuredInBase32Characters(): void
    {
        $provider = $this->provider();

        $this->assertSame(16, strlen($provider->generateSecretKey(16)));
    }

    public function testRejectsInvalidSecretLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Two-factor authentication secret length must be greater than zero.');

        $this->provider()->generateSecretKey(0);
    }

    public function testFeatureOptionsAreStoredInConfig(): void
    {
        Features::twoFactorAuthentication([
            'confirm' => true,
            'secret-length' => 32,
            'window' => 2,
        ]);

        $this->assertSame([
            'confirm' => true,
            'secret-length' => 32,
            'window' => 2,
        ], Features::options(Features::twoFactorAuthentication()));
    }

    public function testQrCodeUrlMatchesCurrentFortifyShape(): void
    {
        $this->assertSame(
            'otpauth://totp/Hypervel%20Test:taylor%40example.com?secret=ABC123&issuer=Hypervel%20Test&algorithm=SHA1&digits=6&period=30',
            $this->provider()->qrCodeUrl('Hypervel Test', 'taylor@example.com', 'ABC123'),
        );
    }

    public function testQrCodeUrlEncodesReservedCharacters(): void
    {
        $this->assertSame(
            'otpauth://totp/A%20%26%20B%20%2F%20C:x%2By%40example.com?secret=ABC%3D123&issuer=A%20%26%20B%20%2F%20C&algorithm=SHA1&digits=6&period=30',
            $this->provider()->qrCodeUrl('A & B / C', 'x+y@example.com', 'ABC=123'),
        );
    }

    public function testVerifiesCurrentStepCode(): void
    {
        $this->assertTrue($this->provider()->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP)));
    }

    public function testAcceptsCodesAtConfiguredWindowBoundaries(): void
    {
        Features::twoFactorAuthentication(['window' => 2]);

        $this->assertTrue($this->provider()->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP - 60)));
        $this->assertTrue($this->provider()->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP + 60)));
    }

    public function testRejectsCodesOutsideConfiguredWindow(): void
    {
        Features::twoFactorAuthentication(['window' => 2]);

        $this->assertFalse($this->provider()->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP - 90)));
        $this->assertFalse($this->provider()->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP + 90)));
    }

    public function testZeroWindowAcceptsOnlyCurrentStep(): void
    {
        Features::twoFactorAuthentication(['window' => 0]);

        $provider = $this->provider();

        $this->assertTrue($provider->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP)));
        $this->assertFalse($provider->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP - 30)));
        $this->assertFalse($provider->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP + 30)));
    }

    public function testRejectsNegativeWindowConfiguration(): void
    {
        Features::twoFactorAuthentication(['window' => -1]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Two-factor authentication window must be greater than or equal to zero.');

        $this->provider()->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP));
    }

    public function testReplayCacheKeyIncludesSecretAndCode(): void
    {
        $firstSecret = 'GBAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $secondSecret = 'QVAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $code = $this->codeAt($firstSecret, self::TIMESTAMP);
        $firstKey = 'fortify.2fa_codes.' . hash('xxh128', $firstSecret . '|' . $code);
        $secondKey = 'fortify.2fa_codes.' . hash('xxh128', $secondSecret . '|' . $code);
        $timecode = intdiv(self::TIMESTAMP, TOTPInterface::DEFAULT_PERIOD);
        $ttl = 90;
        $cache = m::mock(CacheRepositoryContract::class);

        $this->assertSame($code, $this->codeAt($secondSecret, self::TIMESTAMP));
        $this->assertNotSame($firstKey, $secondKey);
        $cache->shouldReceive('add')->once()->with($firstKey, $timecode, $ttl)->andReturnTrue();
        $cache->shouldReceive('add')->once()->with($secondKey, $timecode, $ttl)->andReturnTrue();

        $provider = $this->provider(cache: $cache);

        $this->assertTrue($provider->verify($firstSecret, $code));
        $this->assertTrue($provider->verify($secondSecret, $code));
    }

    public function testRejectsReplayedCodeForSameSecret(): void
    {
        $provider = $this->provider(cache: $this->cache());
        $code = $this->codeAt(self::SECRET, self::TIMESTAMP);

        $this->assertTrue($provider->verify(self::SECRET, $code));
        $this->assertFalse($provider->verify(self::SECRET, $code));
    }

    public function testAllowsSameCodeForDifferentSecrets(): void
    {
        $provider = $this->provider(cache: $this->cache());
        $firstSecret = 'GBAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $secondSecret = 'QVAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $code = $this->codeAt($firstSecret, self::TIMESTAMP);

        $this->assertSame($code, $this->codeAt($secondSecret, self::TIMESTAMP));
        $this->assertTrue($provider->verify($firstSecret, $code));
        $this->assertTrue($provider->verify($secondSecret, $code));
    }

    public function testReplayCacheTtlCoversFullAcceptedWindow(): void
    {
        foreach ([0 => 30, 1 => 90, 2 => 150] as $window => $ttl) {
            Features::twoFactorAuthentication(['window' => $window]);

            $cache = m::mock(CacheRepositoryContract::class);
            $key = 'fortify.2fa_codes.' . hash('xxh128', self::SECRET . '|' . $this->codeAt(self::SECRET, self::TIMESTAMP));
            $timecode = intdiv(self::TIMESTAMP, TOTPInterface::DEFAULT_PERIOD);

            $cache->shouldReceive('add')->once()->with($key, $timecode, $ttl)->andReturnTrue();

            $this->assertTrue($this->provider(cache: $cache)->verify(self::SECRET, $this->codeAt(self::SECRET, self::TIMESTAMP)));
        }
    }

    public function testGeneratedSecretCanBeVerified(): void
    {
        $provider = $this->provider();
        $secret = $provider->generateSecretKey();

        $this->assertTrue($provider->verify($secret, $this->codeAt($secret, self::TIMESTAMP)));
    }

    public function testOtpLibraryMatchesRfc6238KnownAnswerVectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $timestamp => $expectedCode) {
            $this->assertSame(
                $expectedCode,
                TOTP::createFromSecret($secret, $this->clock($timestamp))->withDigits(8)->at($timestamp),
            );
        }
    }

    /**
     * Create a two-factor authentication provider.
     */
    private function provider(?CacheRepositoryContract $cache = null, int $timestamp = self::TIMESTAMP): TwoFactorAuthenticationProvider
    {
        return new TwoFactorAuthenticationProvider($this->clock($timestamp), $cache ?? $this->cache());
    }

    /**
     * Create a fixed clock.
     */
    private function clock(int $timestamp): FixedClock
    {
        return new FixedClock($timestamp);
    }

    /**
     * Create an in-memory cache repository.
     */
    private function cache(): CacheRepositoryContract
    {
        return new CacheRepository(new ArrayStore);
    }

    /**
     * Generate a TOTP code for the given timestamp.
     */
    private function codeAt(string $secret, int $timestamp): string
    {
        return TOTP::createFromSecret($secret, $this->clock($timestamp))->at($timestamp);
    }
}
