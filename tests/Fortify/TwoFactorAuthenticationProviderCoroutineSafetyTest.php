<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Contracts\Cache\Repository as CacheRepositoryContract;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\TwoFactorAuthenticationProvider;
use Hypervel\Tests\Fortify\Fixtures\FixedClock;
use OTPHP\TOTP;

use function Hypervel\Coroutine\parallel;

class TwoFactorAuthenticationProviderCoroutineSafetyTest extends TestCase
{
    private const int TIMESTAMP = 1234567890;

    public function testSharedProviderVerifiesDifferentSecretsConcurrently(): void
    {
        Features::twoFactorAuthentication(['window' => 1]);

        $provider = new TwoFactorAuthenticationProvider($this->clock(self::TIMESTAMP), $this->cache());
        $firstSecret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $secondSecret = 'GBAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
        $firstCode = $this->codeAt($firstSecret, self::TIMESTAMP);
        $secondCode = $this->codeAt($secondSecret, self::TIMESTAMP);

        $results = parallel([
            'first' => function () use ($provider, $firstSecret, $firstCode): bool {
                usleep(5000);

                return $provider->verify($firstSecret, $firstCode);
            },
            'second' => function () use ($provider, $secondSecret, $secondCode): bool {
                usleep(5000);

                return $provider->verify($secondSecret, $secondCode);
            },
        ]);

        $this->assertTrue($results['first']);
        $this->assertTrue($results['second']);
    }

    public function testWindowIsResolvedForEachVerificationCall(): void
    {
        $provider = new TwoFactorAuthenticationProvider($this->clock(self::TIMESTAMP), $this->cache());
        $secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
        $previousStepCode = $this->codeAt($secret, self::TIMESTAMP - 30);

        Features::twoFactorAuthentication(['window' => 0]);
        $this->assertFalse($provider->verify($secret, $previousStepCode));

        Features::twoFactorAuthentication(['window' => 1]);
        $this->assertTrue($provider->verify($secret, $previousStepCode));
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
