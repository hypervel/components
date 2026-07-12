<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Feature\Actions;

use Closure;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Events\PasskeyVerified;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Passkeys\Fixtures\User;
use Hypervel\Tests\Passkeys\Fixtures\WebAuthnFixtures;
use Hypervel\Tests\Passkeys\TestCase;
use Mockery as m;
use ParagonIE\ConstantTime\Base64UrlSafe;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use UnitEnum;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;

class VerifyPasskeyTest extends TestCase
{
    use WebAuthnFixtures;

    public function testItVerifiesAPasskeyAndReturnsIt(): void
    {
        Event::fake([PasskeyVerified::class]);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $credentialId = random_bytes(16);
        $userHandle = $user->getPasskeyUserHandle();

        $source = $this->createCredentialSource($userHandle, $credentialId, counter: 5);

        $passkey = $user->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        ]);

        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: m::mock(AuthenticatorAssertionResponse::class),
        );

        $options = $this->createRequestOptions();

        $updatedSource = $this->createCredentialSource($userHandle, $credentialId, counter: 6);

        $action = m::mock(VerifyPasskey::class, [
            app(ConnectionResolverInterface::class),
        ])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('validate')
            ->once()
            ->andReturn($updatedSource)
            ->getMock();

        $result = $action($assertion, $options);

        $this->assertInstanceOf(Passkey::class, $result);
        $this->assertSame($passkey->id, $result->id);
        $this->assertNotNull($result->last_used_at);

        $result->refresh();
        $this->assertSame(6, $result->credential['counter']);

        Event::assertDispatched(
            PasskeyVerified::class,
            static fn (PasskeyVerified $event): bool => $event->user->is($user)
                && $event->passkey->is($passkey),
        );
    }

    public function testItThrowsExceptionWhenResponseIsNotAnAssertionResponse(): void
    {
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: 'test-raw-id',
            response: m::mock(AuthenticatorAttestationResponse::class),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Unable to verify passkey');

        app(VerifyPasskey::class)($assertion, $this->createRequestOptions());
    }

    public function testItThrowsExceptionWhenPasskeyIsNotFound(): void
    {
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: random_bytes(16),
            response: m::mock(AuthenticatorAssertionResponse::class),
        );

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Passkey not recognized');

        app(VerifyPasskey::class)($assertion, $this->createRequestOptions());
    }

    public function testItThrowsExceptionWhenPasskeyDoesNotBelongToExpectedUser(): void
    {
        $owner = User::create([
            'name' => 'Passkey Owner',
            'email' => 'owner@example.com',
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
        ]);

        $credentialId = random_bytes(16);
        $userHandle = $owner->getPasskeyUserHandle();
        $source = $this->createCredentialSource($userHandle, $credentialId, counter: 1);

        $owner->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        ]);

        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: m::mock(AuthenticatorAssertionResponse::class),
        );

        $action = m::mock(VerifyPasskey::class, [
            app(ConnectionResolverInterface::class),
        ])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods()
            ->shouldReceive('validate')
            ->never()
            ->getMock();

        $this->expectException(InvalidPasskeyException::class);
        $this->expectExceptionMessage('Passkey not recognized');

        $action($assertion, $this->createRequestOptions(), $otherUser);
    }

    public function testItVerifiesAnExistingPasskeyAfterUserHandleSecretRotation(): void
    {
        config()->set('passkeys.allowed_origins', ['https://localhost']);
        config()->set('passkeys.relying_party_id', 'localhost');
        config()->set('passkeys.user_handle_secret', 'initial-user-handle-secret');

        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $credentialId = random_bytes(16);
        $initialUserHandle = $user->getPasskeyUserHandle();

        $passkey = $user->passkeys()->create([
            'name' => 'My MacBook',
            'credential_id' => Base64UrlSafe::encodeUnpadded($credentialId),
            'credential' => json_decode(
                WebAuthn::toJson($this->createCredentialSource($initialUserHandle, $credentialId)),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        ]);

        config()->set('passkeys.user_handle_secret', 'rotated-user-handle-secret');

        $options = $this->createRequestOptions();
        $assertion = PublicKeyCredential::create(
            type: 'public-key',
            rawId: $credentialId,
            response: $this->createSignedAssertionResponse($options->challenge, 'https://localhost', signCount: 6, rpId: 'localhost'),
        );

        $result = app(VerifyPasskey::class)($assertion, $options, $user);

        $this->assertSame($passkey->id, $result->id);
        $this->assertSame($initialUserHandle, Base64UrlSafe::decodeNoPadding($result->refresh()->credential['userHandle']));
    }

    public function testItUsesTheRelyingPartyIdStoredInVerificationOptions(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'registered.example.com',
        );

        $action = new ExposesVerifyPasskeyHost(app(ConnectionResolverInterface::class));

        $this->assertSame('registered.example.com', $action->host($options));
    }

    public function testItThrowsWhenVerificationOptionsHaveNoRelyingPartyId(): void
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
        );

        $action = new ExposesVerifyPasskeyHost(app(ConnectionResolverInterface::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Passkey verification options must contain a relying party ID.');

        $action->host($options);
    }

    public function testItUsesTheConfiguredPasskeyModelConnectionForVerificationTransactions(): void
    {
        Passkeys::usePasskeyModel(CustomConnectionPasskey::class);

        $user = User::create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $passkey = new CustomConnectionPasskey;
        $passkey->forceFill([
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->getKey(),
            'name' => 'Laptop',
            'credential_id' => 'credential-connection',
            'credential' => ['id' => 'credential-connection'],
        ]);

        $database = m::mock(ConnectionResolverInterface::class);
        $connection = m::mock(ConnectionInterface::class);
        $database->shouldReceive('connection')->once()->with('passkeys')->andReturn($connection);
        $connection->shouldReceive('transaction')
            ->once()
            ->with(m::type(Closure::class))
            ->andReturnUsing(static fn (Closure $callback): Passkey => $callback());

        $events = m::mock(Dispatcher::class)->shouldIgnoreMissing();
        $events->shouldReceive('hasListeners')->withAnyArgs()->andReturnFalse()->byDefault();
        $events->shouldReceive('hasListeners')->once()->with(PasskeyVerified::class)->andReturnFalse();
        $events->shouldReceive('dispatch')
            ->withArgs(static fn (mixed $event): bool => $event instanceof PasskeyVerified)
            ->never();
        $this->instance('events', $events);

        $credential = PublicKeyCredential::create(
            'public-key',
            random_bytes(32),
            $this->createStub(AuthenticatorResponse::class),
        );

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: 'localhost',
        );

        $verifier = new ConnectionAwareVerifyPasskey(
            $database,
            $passkey,
            $this->createStub(AuthenticatorAssertionResponse::class),
        );

        $this->assertSame($passkey, $verifier($credential, $options, $user));
        $this->assertTrue($verifier->receivedLockedLookup);
    }
}

class CustomConnectionPasskey extends Passkey
{
    protected UnitEnum|string|null $connection = 'passkeys';
}

class ExposesVerifyPasskeyHost extends VerifyPasskey
{
    /**
     * Get the relying party ID stored in the verification options.
     */
    public function host(PublicKeyCredentialRequestOptions $options): string
    {
        return $this->hostFromOptions($options);
    }
}

class ConnectionAwareVerifyPasskey extends VerifyPasskey
{
    public bool $receivedLockedLookup = false;

    public function __construct(
        ConnectionResolverInterface $database,
        private readonly Passkey $passkey,
        private readonly AuthenticatorAssertionResponse $response,
    ) {
        parent::__construct($database);
    }

    /**
     * Get the authenticator assertion response from the credential.
     */
    protected function getResponse(PublicKeyCredential $credential): AuthenticatorAssertionResponse
    {
        return $this->response;
    }

    /**
     * Get the passkey by credential ID.
     */
    public function getPasskey(PublicKeyCredential $credential, bool $lock = false, ?string $ownerType = null): Passkey
    {
        $this->receivedLockedLookup = $lock;

        return $this->passkey;
    }

    /**
     * Validate the credential against the stored passkey.
     */
    protected function validate(
        AuthenticatorAssertionResponse $response,
        Passkey $passkey,
        PublicKeyCredentialRequestOptions $options
    ): CredentialRecord {
        return CredentialRecord::create(
            publicKeyCredentialId: random_bytes(32),
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::v4(),
            credentialPublicKey: random_bytes(77),
            userHandle: 'test-user-handle',
            counter: 0,
        );
    }

    /**
     * Update the passkey with the latest credential data.
     */
    public function updatePasskey(Passkey $passkey, CredentialRecord $source): void
    {
    }
}
