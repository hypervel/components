<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Fortify\Features;
use Hypervel\Fortify\TwoFactorAuthenticationProvider;
use Mockery as m;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthenticationProviderTest extends TestCase
{
    public function testDefaultSecretLengthMatchesGoogle2faVersion(): void
    {
        $provider = new TwoFactorAuthenticationProvider(new Google2FA);

        $this->assertSame(32, strlen($provider->generateSecretKey()));
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

    public function testCustomWindowDoesNotMutateSharedGoogle2faEngine(): void
    {
        $engine = new Google2FA;
        $provider = new TwoFactorAuthenticationProvider($engine);
        $secret = $provider->generateSecretKey();
        $code = $engine->getCurrentOtp($secret);
        $defaultWindow = $engine->getWindow();

        Features::twoFactorAuthentication([
            'window' => 4,
        ]);

        $this->assertTrue($provider->verify($secret, $code));
        $this->assertSame($defaultWindow, $engine->getWindow());
    }

    public function testReplayCacheKeyIncludesSecretAndCode(): void
    {
        $engine = m::mock(Google2FA::class);
        $cache = m::mock(Repository::class);
        $provider = new TwoFactorAuthenticationProvider($engine, $cache);

        $code = '123456';
        $firstSecret = 'first-secret';
        $secondSecret = 'second-secret';
        $firstKey = 'fortify.2fa_codes.' . hash('xxh128', $firstSecret . '|' . $code);
        $secondKey = 'fortify.2fa_codes.' . hash('xxh128', $secondSecret . '|' . $code);

        $this->assertNotSame($firstKey, $secondKey);

        $cache->shouldReceive('get')->once()->with($firstKey)->andReturn(null);
        $engine->shouldReceive('verifyKeyNewer')->once()->with($firstSecret, $code, null, null)->andReturn(100);
        $engine->shouldReceive('getWindow')->once()->with(null)->andReturn(1);
        $cache->shouldReceive('put')->once()->with($firstKey, 100, 60)->andReturnTrue();

        $cache->shouldReceive('get')->once()->with($secondKey)->andReturn(null);
        $engine->shouldReceive('verifyKeyNewer')->once()->with($secondSecret, $code, null, null)->andReturn(101);
        $engine->shouldReceive('getWindow')->once()->with(null)->andReturn(1);
        $cache->shouldReceive('put')->once()->with($secondKey, 101, 60)->andReturnTrue();

        $this->assertTrue($provider->verify($firstSecret, $code));
        $this->assertTrue($provider->verify($secondSecret, $code));
    }
}
