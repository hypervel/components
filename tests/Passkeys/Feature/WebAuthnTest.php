<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature;

use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\TestCase;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

class WebAuthnTest extends TestCase
{
    public function testItSerializesAndDeserializesRegistrationOptions(): void
    {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(id: 'localhost'),
            user: PublicKeyCredentialUserEntity::create(
                name: 'test@example.com',
                id: 'user-id-123',
                displayName: 'Test User',
            ),
            challenge: random_bytes(32),
        );

        $json = WebAuthn::toJson($options);
        $restored = WebAuthn::fromJson($json, PublicKeyCredentialCreationOptions::class);

        $this->assertSame('localhost', $restored->rp->id);
        $this->assertSame('test@example.com', $restored->user->name);
        $this->assertSame('Test User', $restored->user->displayName);
    }

    public function testItSerializesBrowserArraysWithoutNullValues(): void
    {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(id: 'localhost'),
            user: PublicKeyCredentialUserEntity::create(
                name: 'test@example.com',
                id: 'user-id-123',
                displayName: 'Test User',
            ),
            challenge: random_bytes(32),
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            excludeCredentials: [],
        );

        $array = WebAuthn::toBrowserArray($options);

        $this->assertArrayNotHasKey('attestation', $array);
        $this->assertArrayHasKey('excludeCredentials', $array);
        $this->assertSame([], $array['excludeCredentials']);
        $this->assertArrayNotHasKey('authenticatorAttachment', $array['authenticatorSelection']);
        $this->assertSame('required', $array['authenticatorSelection']['residentKey']);
    }

    public function testItSerializesAndDeserializesVerificationOptions(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'localhost',
        );

        $json = WebAuthn::toJson($options);
        $restored = WebAuthn::fromJson($json, PublicKeyCredentialRequestOptions::class);

        $this->assertSame('localhost', $restored->rpId);
        $this->assertSame($options->challenge, $restored->challenge);
    }

    public function testItCreatesValidatorsWithoutThrowing(): void
    {
        $attestation = WebAuthn::attestationValidator();
        $assertion = WebAuthn::assertionValidator();

        $this->assertNotNull($attestation);
        $this->assertNotNull($assertion);
    }

    public function testItFlushesCachedInstances(): void
    {
        WebAuthn::toJson(['test' => 'data']);
        WebAuthn::toJson(['test' => 'data']);

        WebAuthn::flushState();

        $json = WebAuthn::toJson(['test' => 'data']);

        $this->assertStringContainsString('test', $json);
    }
}
