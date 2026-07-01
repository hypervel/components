<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Controllers;

use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;

class PasskeyConfirmationTest extends TestCase
{
    use WebAuthnFixtures;

    public function testItReturnsConfirmationOptionsForAuthenticatedUser(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($user)
            ->getJson('/passkeys/confirm/options')
            ->assertOk()
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'timeout',
                    'rpId',
                    'allowCredentials',
                ],
            ]);
    }

    public function testItStoresConfirmationOptionsInSession(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->actingAs($user)
            ->getJson('/passkeys/confirm/options')
            ->assertOk();

        $this->assertNotNull(session('passkey.confirmation_options'));
    }

    public function testItRequiresAuthenticationForConfirmationOptions(): void
    {
        $this->getJson('/passkeys/confirm/options')
            ->assertUnauthorized();
    }

    public function testItRequiresAuthenticationToConfirmAPasskey(): void
    {
        $this->postJson('/passkeys/confirm', [
            'credential' => [
                'id' => 'test-id',
                'rawId' => 'test-raw-id',
                'type' => 'public-key',
                'response' => ['test' => 'response'],
            ],
        ])
            ->assertUnauthorized();
    }

    public function testItReturnsValidationErrorWhenPasskeyConfirmationIsInvalid(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->instance(VerifyPasskey::class, m::mock(VerifyPasskey::class)
            ->shouldReceive('__invoke')
            ->andThrow(InvalidPasskeyException::make())
            ->getMock());

        $this->actingAs($user)
            ->withSession(['passkey.confirmation_options' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/confirm', [
                'credential' => $this->createAssertionCredential(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        $this->assertAuthenticatedAs($user);
    }

    public function testItMarksThePasswordAsConfirmedWhenPasskeyConfirmationSucceeds(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $passkey = $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'test-credential-id',
            'credential' => [],
        ]);

        $this->instance(VerifyPasskey::class, m::mock(VerifyPasskey::class)
            ->shouldReceive('__invoke')
            ->once()
            ->withArgs(static fn ($credential, $options, $expectedUser): bool => $expectedUser instanceof User
                && $expectedUser->is($user))
            ->andReturn($passkey)
            ->getMock());

        $this->actingAs($user)
            ->withSession(['passkey.confirmation_options' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/confirm', [
                'credential' => $this->createAssertionCredential(),
            ])
            ->assertOk()
            ->assertJsonStructure(['redirect'])
            ->assertJsonMissing(['confirmed']);

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }
}
