<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Fortify\Features;
use Hypervel\Fortify\TwoFactorAuthenticationProvider;
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
}
