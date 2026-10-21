<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\StatefulGuard;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Http\Controllers\PasskeyConfirmationController;
use Hypervel\Passkeys\Http\Controllers\PasskeyLoginController;
use Hypervel\Passkeys\Http\Controllers\PasskeyRegistrationController;
use Hypervel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Hypervel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Passkeys\Fixtures\Admin;
use Hypervel\Tests\Passkeys\Fixtures\User;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

class PasskeysGuardTest extends TestCase
{
    public function testPasskeysGuardFollowsCurrentDefaultGuardSelectedByShouldUse(): void
    {
        $this->configureAdminGuard();

        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);

        $auth->shouldUse('admin');

        $this->assertSame('admin', Passkeys::guardName());
        $this->assertSame($auth->guard('admin'), Passkeys::guard());
        $this->assertInstanceOf(StatefulGuard::class, Passkeys::guard());
    }

    #[DataProvider('ownerTypesOutsideTheSelectedProvider')]
    public function testSelectedGuardProviderScopesPasswordlessPasskeyVerification(string $ownerType): void
    {
        $this->configureAdminGuard();

        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);
        $auth->shouldUse('admin');

        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.com',
        ]);

        $rawCredentialId = random_bytes(32);
        $credentialId = Base64UrlSafe::encodeUnpadded($rawCredentialId);

        (new Passkey)->forceFill([
            'user_type' => $ownerType,
            'user_id' => $user->getKey(),
            'name' => 'User key',
            'credential_id' => $credentialId,
            'credential' => ['id' => $credentialId],
        ])->save();

        $credential = PublicKeyCredential::create(
            'public-key',
            $rawCredentialId,
            $this->createStub(AuthenticatorAssertionResponse::class),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessageIs('Passkey not recognized. It may have been removed from your account.');

        app(VerifyPasskey::class)($credential, PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'localhost',
        ));
    }

    /**
     * Get stored owner types that the admin guard provider does not own.
     *
     * @return array<string, array{string}>
     */
    public static function ownerTypesOutsideTheSelectedProvider(): array
    {
        return [
            'another provider model' => [User::class],
            'unresolvable owner type' => ['Missing\PasskeyOwner'],
        ];
    }

    public function testLoginAndConfirmationOptionsArePendingSeparatelyForEachGuard(): void
    {
        $this->configureAdminGuard();

        Route::middleware(['web', 'guest:admin'])
            ->get('/admin/passkeys/login/options', [PasskeyLoginController::class, 'index']);
        Route::middleware(['web', 'auth:web'])
            ->get('/account/passkeys/confirm/options', [PasskeyConfirmationController::class, 'index']);

        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.com',
        ]);

        $adminLogin = $this->actingAs($user, 'web')
            ->getJson('/admin/passkeys/login/options')
            ->assertOk();

        $userConfirmation = $this->getJson('/account/passkeys/confirm/options')
            ->assertOk();

        $this->assertSame($adminLogin->json('options.challenge'), $this->pendingVerificationChallenge('admin'));
        $this->assertSame($userConfirmation->json('options.challenge'), $this->pendingVerificationChallenge('web'));
    }

    public function testRegistrationOptionsArePendingSeparatelyForEachGuard(): void
    {
        $this->configureAdminGuard();

        Schema::create('admins', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Route::middleware(['web', 'auth:web'])
            ->get('/account/passkeys/options', [PasskeyRegistrationController::class, 'index']);
        Route::middleware(['web', 'auth:admin'])
            ->get('/admin/passkeys/options', [PasskeyRegistrationController::class, 'index']);

        $user = User::create([
            'name' => 'User',
            'email' => 'user@example.com',
        ]);
        $admin = Admin::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        $this->actingAs($user, 'web')->actingAs($admin, 'admin');

        $userRegistration = $this->getJson('/account/passkeys/options')->assertOk();
        $adminRegistration = $this->getJson('/admin/passkeys/options')->assertOk();

        $this->assertSame($userRegistration->json('options.challenge'), $this->pendingRegistrationChallenge('web'));
        $this->assertSame($adminRegistration->json('options.challenge'), $this->pendingRegistrationChallenge('admin'));
    }

    /**
     * Configure the admin guard fixture.
     */
    private function configureAdminGuard(): void
    {
        config()->set([
            'auth.guards.admin' => [
                'driver' => 'session',
                'provider' => 'admins',
                'passwords' => null,
                'password_timeout' => null,
                'remember' => null,
            ],
            'auth.providers.admins' => [
                'driver' => 'eloquent',
                'model' => Admin::class,
                'cache' => [
                    'enabled' => false,
                    'store' => null,
                    'ttl' => 300,
                    'prefix' => EloquentUserProvider::DEFAULT_CACHE_PREFIX,
                    'tags' => null,
                ],
            ],
        ]);
    }

    /**
     * Get the challenge from the guard's pending verification options.
     */
    private function pendingVerificationChallenge(string $guard): string
    {
        $this->app->make(AuthFactory::class)->shouldUse($guard);

        $request = PasskeyVerificationRequest::create('/');
        $request->setHypervelSession($this->app->make('session.store'));

        return WebAuthn::toBrowserArray($request->verificationOptions())['challenge'];
    }

    /**
     * Get the challenge from the guard's pending registration options.
     */
    private function pendingRegistrationChallenge(string $guard): string
    {
        $this->app->make(AuthFactory::class)->shouldUse($guard);

        $request = PasskeyRegistrationRequest::create('/');
        $request->setHypervelSession($this->app->make('session.store'));

        return WebAuthn::toBrowserArray($request->registrationOptions())['challenge'];
    }
}
