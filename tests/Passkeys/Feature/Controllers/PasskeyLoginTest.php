<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Controllers;

use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;

class PasskeyLoginTest extends TestCase
{
    use WebAuthnFixtures;

    public function testItReturnsLoginOptions(): void
    {
        $this->getJson('/passkeys/login/options')
            ->assertOk()
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'timeout',
                    'rpId',
                ],
            ]);
    }

    public function testItStoresLoginOptionsInSession(): void
    {
        $this->getJson('/passkeys/login/options')->assertOk();

        $this->assertNotNull(session('passkey.login_options'));
    }

    public function testItReturnsValidationErrorWhenPasskeyIsInvalid(): void
    {
        $this->instance(VerifyPasskey::class, m::mock(VerifyPasskey::class)
            ->shouldReceive('__invoke')
            ->andThrow(InvalidPasskeyException::make())
            ->getMock());

        $this->withSession(['passkey.login_options' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/login', [
                'credential' => $this->createAssertionCredential(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        $this->assertGuest();
    }

    public function testItReturnsValidationErrorWhenCredentialFormatIsInvalid(): void
    {
        $this->withSession(['passkey.login_options' => WebAuthn::toJson($this->createRequestOptions())])
            ->postJson('/passkeys/login', [
                'credential' => [
                    'id' => 'dGVzdC1pZA',
                    'rawId' => 'dGVzdC1pZA',
                    'type' => 'public-key',
                    'response' => ['test' => 'response'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);

        $this->assertGuest();
    }

    public function testItReturnsValidationErrorWhenSessionHasExpired(): void
    {
        $this->postJson('/passkeys/login', [
            'credential' => $this->createAssertionCredential(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['credential']);
    }
}
