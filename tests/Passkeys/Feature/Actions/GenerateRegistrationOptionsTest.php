<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Actions\GenerateRegistrationOptions;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\TestCase;
use ParagonIE\ConstantTime\Base64UrlSafe;
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
        $rawCredentialId = random_bytes(32);

        $user->passkeys()->create([
            'name' => 'Test Passkey',
            'credential_id' => Base64UrlSafe::encodeUnpadded($rawCredentialId),
            'credential' => ['publicKey' => 'test'],
        ]);

        $options = app(GenerateRegistrationOptions::class)($user);

        $this->assertSame('localhost', $options->rp->id);
        $this->assertSame($user->getPasskeyUserHandle(), $options->user->id);
        $this->assertCount(1, $options->excludeCredentials);
        $this->assertSame($rawCredentialId, $options->excludeCredentials[0]->id);
    }

    public function testItUsesRequestAwareRelyingPartyIdForRegistrationOptions(): void
    {
        RequestContext::set(Request::create('https://dynamic.example.com/user/passkeys/options'));
        Passkeys::resolveRelyingPartyIdUsing(
            static fn (Request $request): string => $request->getHost(),
        );

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $options = app(GenerateRegistrationOptions::class)($user);

        $this->assertSame('dynamic.example.com', $options->rp->id);
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
