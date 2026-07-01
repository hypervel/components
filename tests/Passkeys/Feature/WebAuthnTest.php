<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature;

use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Tests\Passkeys\TestCase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Uid\Uuid;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

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

    public function testItSerializesAndDeserializesCredentialRecords(): void
    {
        $source = $this->credentialRecord();

        $json = WebAuthn::toJson($source);
        $restored = WebAuthn::fromJson($json, CredentialRecord::class);

        $this->assertInstanceOf(CredentialRecord::class, $restored);
        $this->assertSame($source->publicKeyCredentialId, $restored->publicKeyCredentialId);
        $this->assertSame($source->userHandle, $restored->userHandle);
    }

    public function testItCanConfigureAndFlushTheCeremonyStepManagerFactory(): void
    {
        $called = false;

        WebAuthn::configureCeremonyStepManagerFactoryUsing(
            static function (CeremonyStepManagerFactory $factory) use (&$called): CeremonyStepManagerFactory {
                $called = true;

                return $factory;
            }
        );

        WebAuthn::assertionValidator();

        $this->assertTrue($called);

        WebAuthn::flushState();
        $called = false;

        WebAuthn::assertionValidator();

        $this->assertFalse($called);
    }

    public function testPasskeysSourceDoesNotUseDeprecatedPublicKeyCredentialSource(): void
    {
        $sourcePath = dirname(__DIR__, 3) . '/src/passkeys/src';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourcePath)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            $this->assertIsString($contents);
            $this->assertStringNotContainsString(
                'PublicKeyCredentialSource',
                $contents,
                $file->getPathname(),
            );
        }
    }

    /**
     * Create a stored WebAuthn credential record.
     */
    private function credentialRecord(): CredentialRecord
    {
        $rawCredentialId = random_bytes(32);

        return CredentialRecord::create(
            publicKeyCredentialId: $rawCredentialId,
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: random_bytes(77),
            userHandle: Base64UrlSafe::encodeUnpadded(random_bytes(32)),
            counter: 0,
        );
    }
}
