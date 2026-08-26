<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Controllers;

use Hypervel\Contracts\Auth\Factory as AuthFactory;
use Hypervel\Contracts\Auth\Guard;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Passkeys\Actions\StorePasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\PasskeysServiceProvider;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use InvalidArgumentException;
use Mockery as m;
use UnitEnum;

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

        $this->actingAs($user);

        $binding = Route::getBindingCallback('passkey');

        $this->assertInstanceOf(CustomRouteKeyPasskey::class, $binding($passkey->credential_id));
        $this->assertSame($passkey->id, $binding($passkey->credential_id)->id);
    }

    public function testPasskeyBindingUsesTheAuthenticatedUsersMorphIdentity(): void
    {
        Relation::morphMap(['passkey-user' => User::class]);

        try {
            $user = User::create([
                'name' => 'Morph User',
                'email' => 'morph@example.com',
            ]);
            $passkey = $user->passkeys()->create([
                'name' => 'Morph Passkey',
                'credential_id' => 'bW9ycGgtY3JlZGVudGlhbA',
                'credential' => ['publicKey' => 'test'],
            ]);

            $this->assertSame('passkey-user', $passkey->user_type);

            $this->actingAs($user);

            $binding = Route::getBindingCallback('passkey');
            $resolved = $binding((string) $passkey->getRouteKey());

            $this->assertTrue($resolved->is($passkey));
        } finally {
            Relation::morphMap([], false);
        }
    }

    #[WithConfig('database.connections.passkeys_testing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'foreign_key_constraints' => false,
    ])]
    public function testPasskeyBindingUsesTheConfiguredModelConnection(): void
    {
        Passkeys::usePasskeyModel(ConnectionBoundPasskey::class);

        Schema::connection('passkeys_testing')->create('passkeys', function (Blueprint $table): void {
            $table->id();
            $table->morphs('user');
            $table->string('name');
            $table->string('credential_id', 1364)->unique();
            $table->jsonb('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        $user = User::create([
            'name' => 'Connection User',
            'email' => 'connection@example.com',
        ]);
        $passkey = $user->passkeys()->create([
            'name' => 'Connection Passkey',
            'credential_id' => 'Y29ubmVjdGlvbi1jcmVkZW50aWFs',
            'credential' => ['publicKey' => 'test'],
        ]);

        $this->actingAs($user);

        $binding = Route::getBindingCallback('passkey');
        $resolved = $binding((string) $passkey->getRouteKey());

        $this->assertInstanceOf(ConnectionBoundPasskey::class, $resolved);
        $this->assertSame('passkeys_testing', $resolved->getConnectionName());
        $this->assertTrue($resolved->is($passkey));
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
            ->assertNotFound();

        $this->assertDatabaseHas('passkeys', [
            'id' => $passkey->getKey(),
        ]);
    }

    public function testPasskeyBindingReturnsNotFoundWithoutAnAuthenticatedUser(): void
    {
        $passkey = $this->createPasskeyForBinding('unauthenticated@example.com');
        $binding = Route::getBindingCallback('passkey');

        $this->expectException(ModelNotFoundException::class);

        $binding((string) $passkey->getRouteKey());
    }

    public function testPasskeyBindingReturnsNotFoundForANonStatefulGuard(): void
    {
        $passkey = $this->createPasskeyForBinding('stateless@example.com');
        $auth = m::mock(AuthFactory::class);
        $auth->shouldReceive('guard')->once()->andReturn(m::mock(Guard::class));
        $this->app->instance(AuthFactory::class, $auth);

        $binding = Route::getBindingCallback('passkey');

        $this->expectException(ModelNotFoundException::class);

        $binding((string) $passkey->getRouteKey());
    }

    public function testPasskeyBindingDoesNotHideGuardConfigurationFailures(): void
    {
        $auth = m::mock(AuthFactory::class);
        $auth->shouldReceive('guard')->once()->andThrow(new InvalidArgumentException('Invalid guard configuration.'));
        $this->app->instance(AuthFactory::class, $auth);

        $binding = Route::getBindingCallback('passkey');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid guard configuration.');

        $binding('1');
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

    private function createPasskeyForBinding(string $email): Passkey
    {
        $user = User::create([
            'name' => 'Binding User',
            'email' => $email,
        ]);

        return $user->passkeys()->create([
            'name' => 'Binding Passkey',
            'credential_id' => rtrim(strtr(base64_encode($email), '+/', '-_'), '='),
            'credential' => ['publicKey' => 'test'],
        ]);
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

class ConnectionBoundPasskey extends Passkey
{
    protected ?string $table = 'passkeys';

    protected UnitEnum|string|null $connection = 'passkeys_testing';
}
