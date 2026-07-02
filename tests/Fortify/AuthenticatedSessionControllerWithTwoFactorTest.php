<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Hypervel\Fortify\Events\TwoFactorAuthenticationFailed;
use Hypervel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Hypervel\Fortify\Features;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Fortify\Fixtures\UserWithTwoFactor;
use PragmaRX\Google2FA\Google2FA;

#[WithMigration]
#[DefineEnvironment('withTwoFactorAuthentication')]
#[WithConfig('auth.providers.users.model', UserWithTwoFactor::class)]
class AuthenticatedSessionControllerWithTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function testUserIsRedirectedToChallengeWhenUsingTwoFactorAuthentication(): void
    {
        Event::fake();

        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/two-factor-challenge');

        Event::assertDispatched(TwoFactorAuthenticationChallenged::class);
    }

    #[DefineEnvironment('withConfirmedTwoFactorAuthentication')]
    public function testUserIsNotRedirectedToChallengeWhenUsingTwoFactorAuthenticationThatHasNotBeenConfirmedAndConfirmationIsEnabled(): void
    {
        Event::fake();

        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/home');
    }

    #[DefineEnvironment('withConfirmedTwoFactorAuthentication')]
    public function testUserIsRedirectedToChallengeWhenUsingTwoFactorAuthenticationThatHasBeenConfirmedAndConfirmationIsEnabled(): void
    {
        Event::fake();

        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
            'two_factor_confirmed_at' => now(),
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/two-factor-challenge');
    }

    #[DefineEnvironment('withoutTwoFactorAuthentication')]
    public function testUserCanAuthenticateWhenTwoFactorChallengeIsDisabled(): void
    {
        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/home');
    }

    public function testRehashUserPasswordWhenRedirectingToTwoFactorChallengeIfRehashingOnLoginIsEnabled(): void
    {
        $this->app['config']->set('hashing.rehash_on_login', true);

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => Hash::make('secret', ['rounds' => 6]),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/two-factor-challenge');

        $this->assertNotSame($user->password, $user->fresh()->password);
        $this->assertTrue(Hash::check('secret', $user->fresh()->password));
    }

    public function testDoesNotRehashUserPasswordWhenRedirectingToTwoFactorChallengeIfRehashingOnLoginIsDisabled(): void
    {
        $this->app['config']->set('hashing.rehash_on_login', false);

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => Hash::make('secret', ['rounds' => 6]),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
        ]);

        $response->assertRedirect('/two-factor-challenge');

        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function testTwoFactorChallengeCanBePassedViaCode(): void
    {
        Event::fake();

        $tfaEngine = app(Google2FA::class);
        $userSecret = $tfaEngine->generateSecretKey();
        $validOtp = $tfaEngine->getCurrentOtp($userSecret);

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt($userSecret),
        ]);

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.guard' => 'web',
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'code' => $validOtp,
        ]);

        Event::assertDispatched(ValidTwoFactorAuthenticationCodeProvided::class);

        $response->assertRedirect('/home')
            ->assertSessionMissing('login.id');
    }

    public function testTwoFactorChallengeEventsReachFakeAfterControllerWasCached(): void
    {
        $tfaEngine = app(Google2FA::class);
        $userSecret = $tfaEngine->generateSecretKey();
        $validOtp = $tfaEngine->getCurrentOtp($userSecret);

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt($userSecret),
        ]);

        $route = $this->app->make('router')->getRoutes()->getRoutesByName()['two-factor.login.store'];
        $route->getController();

        Event::fake();

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.guard' => 'web',
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'code' => $validOtp,
        ]);

        Event::assertDispatched(ValidTwoFactorAuthenticationCodeProvided::class);

        $response->assertRedirect('/home')
            ->assertSessionMissing('login.id');
    }

    public function testTwoFactorAuthenticationPreservesRememberMeSelection(): void
    {
        Event::fake();

        UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => 'test-secret',
        ]);

        $response = $this->withoutExceptionHandling()->post('/login', [
            'email' => 'taylor@laravel.com',
            'password' => 'secret',
            'remember' => false,
        ]);

        $response->assertRedirect('/two-factor-challenge')
            ->assertSessionHas('login.remember', false);
    }

    public function testTwoFactorChallengeFailsForOldOtpAndZeroWindow(): void
    {
        Event::fake();

        // Setting window to 0 should mean any old OTP is instantly invalid
        Features::twoFactorAuthentication(['window' => 0]);

        $tfaEngine = app(Google2FA::class);
        $userSecret = $tfaEngine->generateSecretKey();
        $currentTs = $tfaEngine->getTimestamp();
        $previousOtp = $tfaEngine->oathTotp($userSecret, $currentTs - 1);

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt($userSecret),
        ]);

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.guard' => 'web',
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'code' => $previousOtp,
        ]);

        Event::assertDispatched(TwoFactorAuthenticationFailed::class);

        $response->assertRedirect('/two-factor-challenge')
            ->assertSessionHas('login.id')
            ->assertSessionHasErrors(['code']);
    }

    public function testTwoFactorChallengeCanBePassedViaRecoveryCode(): void
    {
        Event::fake();

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['invalid-code', 'valid-code'])),
        ]);

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.guard' => 'web',
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'recovery_code' => 'valid-code',
        ]);

        Event::assertDispatched(ValidTwoFactorAuthenticationCodeProvided::class);

        $response->assertRedirect('/home')
            ->assertSessionMissing('login.id');
        $this->assertNotNull(Auth::getUser());
        $this->assertNotContains('valid-code', json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true));
    }

    public function testTwoFactorChallengeCanFailViaRecoveryCode(): void
    {
        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['invalid-code', 'valid-code'])),
        ]);

        $response = $this->withSession([
            'login.id' => $user->id,
            'login.guard' => 'web',
            'login.remember' => false,
        ])->withoutExceptionHandling()->post('/two-factor-challenge', [
            'recovery_code' => 'missing-code',
        ]);

        $response->assertRedirect('/two-factor-challenge')
            ->assertSessionHas('login.id')
            ->assertSessionHasErrors(['recovery_code']);
        $this->assertNull(Auth::getUser());
    }

    public function testTwoFactorChallengeRequiresAChallengedUser(): void
    {
        $response = $this->withSession([])->withoutExceptionHandling()->get('/two-factor-challenge');

        $response->assertRedirect('/login');
        $this->assertNull(Auth::getUser());
    }
}
