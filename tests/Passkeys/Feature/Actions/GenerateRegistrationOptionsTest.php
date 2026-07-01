<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Passkeys\Actions\GenerateRegistrationOptions;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;

class GenerateRegistrationOptionsTest extends TestCase
{
    public function testItGeneratesRegistrationOptionsWithUserData(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $options = app(GenerateRegistrationOptions::class)($user);

        $this->assertInstanceOf(PublicKeyCredentialCreationOptions::class, $options);
        $this->assertSame('john@example.com', $options->user->name);
        $this->assertSame('John Doe', $options->user->displayName);
    }

    public function testItExcludesExistingCredentialsFromRegistration(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => 'dGVzdC1jcmVkZW50aWFsLWlk',
            'credential' => ['publicKey' => 'test'],
        ]);

        $options = app(GenerateRegistrationOptions::class)($user);

        $this->assertCount(1, $options->excludeCredentials);
    }

    public function testItAllowsOverridingAuthenticatorSelectionWithACustomActionBinding(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        app()->bind(GenerateRegistrationOptions::class, static fn (): GenerateRegistrationOptions => new class extends GenerateRegistrationOptions {
            public function authenticatorSelection(): AuthenticatorSelectionCriteria
            {
                return AuthenticatorSelectionCriteria::create(
                    authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                    userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                    residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
                );
            }
        });

        $options = app(GenerateRegistrationOptions::class)($user);

        $this->assertSame(
            AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
            $options->authenticatorSelection?->authenticatorAttachment,
        );
    }
}
