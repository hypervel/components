<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Controllers;

use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class PasskeyConfirmationTest extends TestCase
{
    use VerifiesDoubles;
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
                    'userVerification',
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

        $this->assertNotNull(session('passkey.verification_options_web'));
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

        $action = Double::for(VerifyPasskey::class)->strict();
        $action
            ->expects('__invoke')
            ->throws(InvalidPasskeyException::make('Unable to verify passkey. Please try again.'));
        $this->instance(VerifyPasskey::class, $action);

        $this->actingAs($user)
            ->withSession(['passkey.verification_options_web' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/confirm', [
                'credential' => $this->createAssertionCredential(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential' => 'Unable to verify passkey. Please try again.']);

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

        $action = Double::for(VerifyPasskey::class)->strict();
        $action
            ->expects('__invoke')
            ->with(Argument::any(), Argument::any(), Argument::satisfies(
                static fn ($expectedUser): bool => $expectedUser instanceof User && $expectedUser->is($user),
            ))
            ->returns($passkey);
        $this->instance(VerifyPasskey::class, $action);

        $this->actingAs($user)
            ->withSession(['passkey.verification_options_web' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/confirm', [
                'credential' => $this->createAssertionCredential(),
            ])
            ->assertOk()
            ->assertJsonStructure(['redirect'])
            ->assertJsonMissing(['confirmed']);

        $this->assertNotNull(session('auth.password_confirmed_at_web'));
    }
}
