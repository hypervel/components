<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Controllers;

use Hypervel\Passkeys\Actions\StorePasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\PasskeysServiceProvider;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;

class PasskeyRegistrationTest extends TestCase
{
    use WebAuthnFixtures;

    public function testItReturnsRegistrationOptionsForAuthenticatedUser(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->getJson('/user/passkeys/options')
            ->assertOk()
            ->assertJsonStructure([
                'options' => [
                    'rp' => ['id'],
                    'user' => ['id', 'name', 'displayName'],
                    'challenge',
                    'pubKeyCredParams',
                    'excludeCredentials',
                    'authenticatorSelection',
                    'attestation',
                    'timeout',
                ],
            ]);
    }

    public function testItOmitsNullValuesFromBrowserFacingRegistrationOptions(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $response = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->getJson('/user/passkeys/options')
            ->assertOk();

        $this->assertNull($response->json('options.authenticatorSelection.authenticatorAttachment'));
        $this->assertArrayNotHasKey('authenticatorAttachment', $response->json('options.authenticatorSelection'));
        $this->assertSame('required', $response->json('options.authenticatorSelection.residentKey'));
        $this->assertSame('required', $response->json('options.authenticatorSelection.userVerification'));
    }

    public function testItStoresRegistrationOptionsInSession(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->getJson('/user/passkeys/options')
            ->assertOk();

        $this->assertNotNull(session('passkey.registration_options'));
    }

    public function testRegistrationOptionsRemainInSessionWhenTheAuthenticatedUserChanges(): void
    {
        $firstUser = User::create([
            'name' => 'First User',
            'email' => 'first@example.com',
        ]);
        $secondUser = User::create([
            'name' => 'Second User',
            'email' => 'second@example.com',
        ]);

        $this->actingAs($firstUser)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->getJson('/user/passkeys/options')
            ->assertOk();

        $registrationOptions = session('passkey.registration_options');

        Passkeys::guard()->logout();
        Passkeys::guard()->login($secondUser);

        $this->assertTrue(Passkeys::guard()->user()?->is($secondUser));
        $this->assertSame($registrationOptions, session('passkey.registration_options'));
    }

    public function testItRequiresPasswordConfirmationForRegistrationOptions(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($user)
            ->getJson('/user/passkeys/options')
            ->assertStatus(423)
            ->assertJson([
                'message' => 'Password confirmation required.',
            ]);
    }

    public function testItRequiresAuthenticationForRegistrationOptions(): void
    {
        $this->getJson('/user/passkeys/options')
            ->assertUnauthorized();
    }

    public function testItRequiresAuthenticationToStoreAPasskey(): void
    {
        $this->postJson('/user/passkeys', [
            'name' => 'My Passkey',
            'credential' => [
                'id' => 'test-id',
                'rawId' => 'test-raw-id',
                'type' => 'public-key',
                'response' => ['test' => 'response'],
            ],
        ])
            ->assertUnauthorized();
    }

    public function testItReturnsValidationErrorWhenPasskeyIsInvalid(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->instance(StorePasskey::class, m::mock(StorePasskey::class)
            ->shouldReceive('__invoke')
            ->once()
            ->andThrow(InvalidPasskeyException::make('Unable to register passkey. Please try again.'))
            ->getMock());

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->withSession(['passkey.registration_options' => WebAuthn::toJson($this->createRegistrationOptions($user))])
            ->postJson('/user/passkeys', [
                'name' => 'My Passkey',
                'credential' => $this->createRegistrationCredential(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential' => 'Unable to register passkey. Please try again.']);
    }

    public function testItReturnsValidationErrorWhenPasskeyCredentialFormatIsInvalid(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->postJson('/user/passkeys', [
                'name' => 'My Passkey',
                'credential' => [
                    'id' => 'dGVzdC1pZA',
                    'rawId' => 'dGVzdC1pZA',
                    'type' => 'public-key',
                    'response' => ['test' => 'response'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential' => 'Invalid credential format.']);
    }

    public function testItReturnsValidationErrorWhenSessionHasExpired(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->postJson('/user/passkeys', [
                'name' => 'My Passkey',
                'credential' => $this->createRegistrationCredential(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential' => 'Passkey registration session expired. Please try again.']);
    }

    public function testItRequiresPasswordConfirmationToStoreAPasskey(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($user)
            ->postJson('/user/passkeys', [
                'name' => 'My Passkey',
                'credential' => [
                    'id' => 'dGVzdC1pZA',
                    'rawId' => 'dGVzdC1pZA',
                    'type' => 'public-key',
                    'response' => ['test' => 'response'],
                ],
            ])
            ->assertStatus(423)
            ->assertJson([
                'message' => 'Password confirmation required.',
            ]);
    }

    public function testItRequiresAuthenticationToDeleteAPasskey(): void
    {
        $this->deleteJson('/user/passkeys/1')
            ->assertUnauthorized();
    }

    public function testItDeletesAPasskeyForTheAuthenticatedUser(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLWlk',
            'credential' => ['publicKey' => 'test'],
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->deleteJson("/user/passkeys/{$passkey->id}")
            ->assertOk()
            ->assertJson(['status' => 'passkey-deleted']);

        $this->assertNull(Passkey::find($passkey->id));
    }

    public function testItResolvesPasskeyRouteBindingsWithTheConfiguredPasskeyModel(): void
    {
        Passkeys::usePasskeyModel(CustomRouteKeyPasskey::class);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLWlk',
            'credential' => ['publicKey' => 'test'],
        ]);

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->deleteJson("/user/passkeys/{$passkey->credential_id}")
            ->assertOk()
            ->assertJson(['status' => 'passkey-deleted']);

        $this->assertNull(CustomRouteKeyPasskey::find($passkey->id));
    }

    public function testItResolvesPasskeyRouteBindingsWhenPackageRoutesAreIgnored(): void
    {
        Passkeys::ignoreRoutes();
        (new PasskeysServiceProvider($this->app))->boot();

        Passkeys::usePasskeyModel(CustomRouteKeyPasskey::class);

        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'dGVzdC1jdXN0b20tY3JlZGVudGlhbC1pZA',
            'credential' => ['publicKey' => 'test'],
        ]);

        $binding = Route::getBindingCallback('passkey');

        $this->assertInstanceOf(CustomRouteKeyPasskey::class, $binding($passkey->credential_id));
        $this->assertSame($passkey->id, $binding($passkey->credential_id)->id);
    }

    public function testItForbidsDeletingAnotherUsersPasskey(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLWlkLW90aGVy',
            'credential' => ['publicKey' => 'test'],
        ]);

        $this->actingAs($otherUser)
            ->withSession(['auth.password_confirmed_at_web' => time()])
            ->deleteJson("/user/passkeys/{$passkey->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
        ]);
    }

    public function testItRequiresPasswordConfirmationToDeleteAPasskey(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLWlk',
            'credential' => ['publicKey' => 'test'],
        ]);

        $this->actingAs($user)
            ->deleteJson("/user/passkeys/{$passkey->id}")
            ->assertStatus(423)
            ->assertJson([
                'message' => 'Password confirmation required.',
            ]);

        $this->assertNotNull(Passkey::find($passkey->id));
    }
}

class CustomRouteKeyPasskey extends Passkey
{
    protected ?string $table = 'passkeys';

    public function getRouteKeyName(): string
    {
        return 'credential_id';
    }
}
