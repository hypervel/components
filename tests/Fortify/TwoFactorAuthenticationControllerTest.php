<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Encryption\Encrypter;
use Hypervel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Hypervel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Hypervel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Attributes\ResetRefreshDatabaseState;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Fortify\Fixtures\UserWithTwoFactor;
use PragmaRX\Google2FA\Google2FA;

#[WithMigration]
class TwoFactorAuthenticationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testTwoFactorAuthenticationCanBeEnabled(): void
    {
        Event::fake();

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->postJson(
            '/user/two-factor-authentication'
        );

        $response->assertStatus(200);

        Event::assertDispatched(TwoFactorAuthenticationEnabled::class);

        $user = $user->fresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertIsArray(json_decode(decrypt($user->two_factor_recovery_codes), true));
        $this->assertNotNull($user->twoFactorQrCodeSvg());
    }

    #[ResetRefreshDatabaseState]
    public function testCallingTwoFactorAuthenticationEndpointWillNotOverwriteWithoutForceParameter(): void
    {
        Event::fake();

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->postJson(
            '/user/two-factor-authentication'
        );

        $response->assertStatus(200);

        Event::assertDispatched(TwoFactorAuthenticationEnabled::class);

        $user = $user->fresh();

        $oldValue = $user->two_factor_secret;

        $response = $this->withoutExceptionHandling()->actingAs($user)->postJson(
            '/user/two-factor-authentication'
        );

        $response->assertStatus(200);

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertEquals($oldValue, $user->fresh()->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertIsArray(json_decode(decrypt($user->two_factor_recovery_codes), true));
        $this->assertNotNull($user->twoFactorQrCodeSvg());
    }

    #[ResetRefreshDatabaseState]
    public function testCallingTwoFactorAuthenticationEndpointWillOverwriteWithForceParameter(): void
    {
        Event::fake();

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
        ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->postJson(
            '/user/two-factor-authentication',
            [
                'force' => true,
            ]
        );

        $response->assertStatus(200);

        Event::assertDispatched(TwoFactorAuthenticationEnabled::class);

        $user = $user->fresh();

        $oldValue = $user->two_factor_secret;

        $response = $this->withoutExceptionHandling()->actingAs($user)->postJson(
            '/user/two-factor-authentication',
            [
                'force' => true,
            ]
        );

        $response->assertStatus(200);

        $user = $user->fresh();

        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNotEquals($oldValue, $user->fresh()->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertIsArray(json_decode(decrypt($user->two_factor_recovery_codes), true));
        $this->assertNotNull($user->twoFactorQrCodeSvg());
    }

    public function testTwoFactorAuthenticationSecretKeyCanBeRetrieved(): void
    {
        Event::fake();

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt('foo'),
        ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->getJson(
            '/user/two-factor-secret-key'
        );

        $response->assertStatus(200);

        $this->assertEquals('foo', $response->original['secretKey']);
    }

    #[DefineEnvironment('withConfirmedTwoFactorAuthentication')]
    #[ResetRefreshDatabaseState]
    public function testTwoFactorAuthenticationCanBeConfirmed(): void
    {
        Event::fake();

        $tfaEngine = $this->app->make(Google2FA::class);
        $userSecret = $tfaEngine->generateSecretKey();
        $validOtp = $tfaEngine->getCurrentOtp($userSecret);

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt($userSecret),
            'two_factor_confirmed_at' => null,
        ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->postJson(
            '/user/confirmed-two-factor-authentication',
            ['code' => $validOtp],
        );

        $response->assertStatus(200);

        Event::assertDispatched(TwoFactorAuthenticationConfirmed::class);

        $user = $user->fresh();

        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertTrue($user->hasEnabledTwoFactorAuthentication());

        // Ensure two factor authentication not considered enabled if not confirmed...
        $user->forceFill(['two_factor_confirmed_at' => null])->save();

        $this->assertFalse($user->hasEnabledTwoFactorAuthentication());
    }

    #[DefineEnvironment('withConfirmedTwoFactorAuthentication')]
    #[ResetRefreshDatabaseState]
    public function testTwoFactorAuthenticationCanNotBeConfirmedWithInvalidCode(): void
    {
        Event::fake();

        $tfaEngine = $this->app->make(Google2FA::class);
        $userSecret = $tfaEngine->generateSecretKey();

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt($userSecret),
            'two_factor_confirmed_at' => null,
        ]);

        $response = $this->withExceptionHandling()->actingAs($user)->postJson(
            '/user/confirmed-two-factor-authentication',
            ['code' => 'invalid-otp'],
        );

        $response->assertStatus(422);

        Event::assertNotDispatched(TwoFactorAuthenticationConfirmed::class);

        $user = $user->fresh();

        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function testTwoFactorAuthenticationCanBeDisabled(): void
    {
        Event::fake();

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => encrypt('foo'),
            'two_factor_recovery_codes' => encrypt(json_encode([])),
        ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->deleteJson(
            '/user/two-factor-authentication'
        );

        $response->assertStatus(200);

        Event::assertDispatched(TwoFactorAuthenticationDisabled::class);

        $user = $user->fresh();

        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
    }

    public function testTwoFactorAuthenticationSecretKeyCanBeRetrievedWithModelEncrypter(): void
    {
        Event::fake();

        Model::encryptUsing(new Encrypter(
            base64_decode(Str::after('base64:FXvqP4Rg3XycgbIND25bhmjYiiFn1Z+AuAC98GU3Cew=', 'base64:')),
            'aes-256-gcm',
        ));

        $user = UserWithTwoFactor::forceCreate([
            'name' => 'Taylor Otwell',
            'email' => 'taylor@laravel.com',
            'password' => bcrypt('secret'),
            'two_factor_secret' => Model::$encrypter->encrypt('foo'),
        ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->getJson(
            '/user/two-factor-secret-key'
        );

        $response->assertStatus(200);

        $this->assertEquals('foo', $response->original['secretKey']);
    }
}
