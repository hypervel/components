<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Controllers;

use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

class PasskeyLoginTest extends TestCase
{
    use VerifiesDoubles;
    use WebAuthnFixtures;

    public function testItReturnsLoginOptions(): void
    {
        $this->getJson('/passkeys/login/options')
            ->assertOk()
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'rpId',
                    'allowCredentials',
                    'userVerification',
                    'timeout',
                ],
            ]);
    }

    public function testItStoresLoginOptionsInSession(): void
    {
        $this->getJson('/passkeys/login/options')->assertOk();

        $this->assertNotNull(session('passkey.verification_options_web'));
    }

    public function testItReturnsValidationErrorWhenPasskeyIsInvalid(): void
    {
        $action = Double::for(VerifyPasskey::class)->strict();
        $action
            ->expects('__invoke')
            ->throws(InvalidPasskeyException::make('Unable to verify passkey. Please try again.'));
        $this->instance(VerifyPasskey::class, $action);

        $this->withSession(['passkey.verification_options_web' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/login', [
                'credential' => $this->createAssertionCredential(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential' => 'Unable to verify passkey. Please try again.']);

        $this->assertGuest();
    }

    public function testItReturnsValidationErrorWhenCredentialFormatIsInvalid(): void
    {
        $this->withSession(['passkey.verification_options_web' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/login', [
                'credential' => [
                    'id' => 'dGVzdC1pZA',
                    'rawId' => 'dGVzdC1pZA',
                    'type' => 'public-key',
                    'response' => ['test' => 'response'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential' => 'Invalid credential format.']);

        $this->assertGuest();
    }

    public function testItReturnsValidationErrorWhenSessionHasExpired(): void
    {
        $this->postJson('/passkeys/login', [
            'credential' => $this->createAssertionCredential(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential' => 'Passkey verification session expired. Please try again.']);
    }
}
