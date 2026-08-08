<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Hypervel\Passkeys\Actions\StorePasskey;
use Hypervel\Passkeys\Events\PasskeyRegistered;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Webauthn\AttestationStatement\AttestationObject;
use Webauthn\AttestationStatement\AttestationStatement;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorData;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CollectedClientData;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

class StorePasskeyTest extends TestCase
{
    use WebAuthnFixtures;

    public function testItStoresAPasskeyForTheUser(): void
    {
        Event::fake([PasskeyRegistered::class]);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $attestationResponse = m::mock(AuthenticatorAttestationResponse::class);

        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: random_bytes(16),
            response: $attestationResponse,
        );

        $options = $this->createRegistrationOptions($user);
        $source = $this->createCredentialSource($user->getPasskeyUserHandle());

        $action = m::mock(StorePasskey::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('validate')
            ->once()
            ->andReturn($source)
            ->getMock();

        $passkey = $action($user, 'My MacBook', $credential, $options);

        $this->assertInstanceOf(Passkey::class, $passkey);
        $this->assertSame('My MacBook', $passkey->name);
        $this->assertSame($user->getMorphClass(), $passkey->user_type);
        $this->assertSame($user->getKey(), $passkey->user_id);
        $this->assertTrue($passkey->user->is($user));
        $this->assertSame(1, $user->passkeys()->count());

        Event::assertDispatched(
            PasskeyRegistered::class,
            static fn (PasskeyRegistered $event): bool => $event->user->is($user)
                && $event->passkey->is($passkey),
        );
    }

    public function testItThrowsExceptionWhenCredentialIsAlreadyRegistered(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $credentialId = random_bytes(16);

        $user->passkeys()->create([
            'name' => 'Existing Passkey',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => ['test' => 'data'],
        ]);

        $attestationResponse = m::mock(AuthenticatorAttestationResponse::class);

        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $attestationResponse,
        );

        $options = $this->createRegistrationOptions($user);
        $source = $this->createCredentialSource($user->getPasskeyUserHandle(), $credentialId);

        $action = m::mock(StorePasskey::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('validate')
            ->once()
            ->andReturn($source)
            ->getMock();

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to register this passkey.');

        $action($user, 'Duplicate Passkey', $credential, $options);
    }

    public function testItConvertsDuplicateCredentialInsertRaceIntoInvalidPasskeyException(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $source = $this->createCredentialSource($user->getPasskeyUserHandle(), random_bytes(16));
        $action = app(StorePasskey::class);

        $passkey = $action->createPasskey($user, 'Laptop', $source);

        $this->assertTrue($passkey->user->is($user));

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to register this passkey.');

        $action->createPasskey($user, 'Laptop again', $source);
    }

    public function testItRejectsCredentialRecordGeneratedForAnotherUser(): void
    {
        $firstUser = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $secondUser = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);
        $source = $this->createCredentialSource($firstUser->getPasskeyUserHandle());

        try {
            app(StorePasskey::class)->createPasskey($secondUser, 'Laptop', $source);
            $this->fail('Expected a credential record generated for another user to be rejected.');
        } catch (InvalidPasskeyException $exception) {
            $this->assertSame(
                'Passkey registration options no longer match this account. Please try again.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, $firstUser->passkeys()->count());
        $this->assertSame(0, $secondUser->passkeys()->count());
    }

    public function testItConvertsWebAuthnRejectionsIntoInvalidPasskeyException(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: random_bytes(16),
            response: $this->createInvalidAttestationResponse(),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to register passkey. Please try again.');

        app(StorePasskey::class)($user, 'Laptop', $credential, $this->createRegistrationOptions($user));
    }

    public function testItDoesNotConvertWebAuthnFactoryFailures(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: random_bytes(16),
            response: $this->createInvalidAttestationResponse(),
        );

        WebAuthn::configureCeremonyStepManagerFactoryUsing(
            static function (CeremonyStepManagerFactory $factory): never {
                throw new RuntimeException('Unable to configure the ceremony factory.');
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to configure the ceremony factory.');

        app(StorePasskey::class)($user, 'Laptop', $credential, $this->createRegistrationOptions($user));
    }

    public function testItThrowsExceptionWhenResponseIsNotAnAttestationResponse(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $assertionResponse = m::mock(AuthenticatorAssertionResponse::class);

        $credential = PublicKeyCredential::create(
            type: 'public-key',
            rawId: 'test-raw-id',
            response: $assertionResponse,
        );

        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(id: 'localhost'),
            user: PublicKeyCredentialUserEntity::create('test', '1', 'Test'),
            challenge: random_bytes(32),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to register passkey');

        app(StorePasskey::class)($user, 'Test Passkey', $credential, $options);
    }

    public function testItUsesTheRelyingPartyIdStoredInRegistrationOptions(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(id: 'registered.example.com'),
            user: PublicKeyCredentialUserEntity::create('test', $user->getPasskeyUserHandle(), 'Test'),
            challenge: random_bytes(32),
        );

        $this->assertSame('registered.example.com', (new ExposesStorePasskeyHost)->host($options));
    }

    public function testItThrowsWhenRegistrationOptionsHaveNoRelyingPartyId(): void
    {
        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(),
            user: PublicKeyCredentialUserEntity::create('test', $user->getPasskeyUserHandle(), 'Test'),
            challenge: random_bytes(32),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passkey registration options must contain a relying party ID.');

        (new ExposesStorePasskeyHost)->host($options);
    }

    /**
     * Create an attestation response that reaches the WebAuthn rejection boundary.
     */
    private function createInvalidAttestationResponse(): AuthenticatorAttestationResponse
    {
        $challenge = random_bytes(32);
        $clientData = [
            'type' => 'webauthn.create',
            'challenge' => Base64UrlSafe::encodeUnpadded($challenge),
            'origin' => 'http://localhost',
        ];
        $rawClientData = json_encode($clientData, JSON_THROW_ON_ERROR);
        $authenticatorData = AuthenticatorData::create(
            authData: '',
            rpIdHash: hash('sha256', 'localhost', binary: true),
            flags: chr(AuthenticatorData::FLAG_UP),
            signCount: 0,
        );
        $attestationStatement = AttestationStatement::createNone(
            fmt: 'none',
            attStmt: [],
            trustPath: EmptyTrustPath::create(),
        );

        return AuthenticatorAttestationResponse::create(
            clientDataJSON: CollectedClientData::create($rawClientData, $clientData),
            attestationObject: AttestationObject::create('', $attestationStatement, $authenticatorData),
        );
    }
}

class ExposesStorePasskeyHost extends StorePasskey
{
    /**
     * Get the relying party ID stored in the registration options.
     */
    public function host(PublicKeyCredentialCreationOptions $options): string
    {
        return $this->hostFromOptions($options);
    }
}
